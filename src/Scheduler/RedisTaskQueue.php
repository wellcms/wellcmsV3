<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

use Framework\Scheduler\Task;

/**
 * 基于 Redis 的任务队列实现，注入Redis
 * - 使用 ZSET 存储待执行任务 ID 与“分数=实际优先级  时间戳偏移”(保证同优先级时先进先出)
 * - 使用 Redis Hash 存储 Task 详细信息（序列化 JSON）
 * - 失败队列用 List 存储 JSON，定期人工或脚本扫描处理
 */
class RedisTaskQueue implements \Framework\Scheduler\Interfaces\TaskQueueInterface
{
    /** Redis Key 前缀 */
    private const ZSET_KEY = 'scheduler:queue:zset';
    private const HASH_KEY = 'scheduler:queue:hash';
    private const FAILED_LIST = 'scheduler:queue:failed_list';
    private const SUCCESS_LIST = 'scheduler:queue:success_list';
    /**
     * Summary of redis
     * @var \Framework\Cache\Drivers\RedisCache
     */
    private $redis;

    public function __construct(\Framework\Cache\Drivers\RedisCache $redis)
    {
        $this->redis = $redis;
    }

    /**
     * 分数编码采用“时间优先  低位优先级”：
     * 高 42 位：scheduledAt（秒）
     * 低 22 位：priority（越小越优先）
     * 这样可用 ZRANGEBYSCORE 截止到 now，严格按“到期时间”取任务。
     */
    private function calcScore(Task $task): int
    {
        if (PHP_INT_SIZE < 8) {
            // 运行时警告：在 32 位环境位移可能溢出，建议使用 64 位 PHP
            throw new \RuntimeException('RedisTaskQueue::calcScore requires 64-bit PHP (PHP_INT_SIZE >= 8).');
        }

        $ts = $task->scheduledAt & ((1 << 42) - 1);
        $prio = max(0, min($task->priority, (1 << 22) - 1));
        return ($ts << 22) | $prio;
    }

    public function push(Task $task): void
    {
        $task->updatedAt = time();
        // 1. 存储 Task 详细信息到 HASH
        $json = json_encode($task->toArray(), JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Task json encode error: ' . json_last_error_msg());
        }

        $this->redis->hSet(self::HASH_KEY, $task->id, $json);
        // 2. 将任务 ID 推入 ZSET，分数= (scheduledAt << 22) | priority
        $score = $this->calcScore($task);
        $this->redis->zAdd(self::ZSET_KEY, [$score => $task->id]);
    }

    /* public function pop(): ?Task
    {
        $entry = $this->redis->zPopMin(self::ZSET_KEY, 1);
        if (!$entry) return null;
        $taskId = array_key_first($entry);

        $raw = $this->redis->hGet(self::HASH_KEY, $taskId);
        return $raw ? Task::fromArray(json_decode($raw, true)) : null;
    } */
    /**
     * 原子弹出“到期”的最低分任务：
     * - 仅取 scheduledAt <= now 的任务
     * - 读取 HASH 获取 task payload
     * - 若 hash 缺失，清理坏数据并继续
     */
    public function pop(): ?Task
    {
        $lua = <<<LUA
local zset = KEYS[1]
local hash = KEYS[2]
local nowScore = ARGV[1]
-- 循环查找，直到找到有效的 payload 或队列到期任务取完
while true do
    local ids = redis.call('ZRANGEBYSCORE', zset, '-inf', nowScore, 'LIMIT', 0, 1)
    if (#ids == 0) then return nil end
    local id = ids[1]
    local payload = redis.call('HGET', hash, id)
    if payload and payload ~= false then
        redis.call('ZREM', zset, id)
        return payload
    end
    -- payload 缺失，清理坏数据并继续
    redis.call('ZREM', zset, id)
    redis.call('HDEL', hash, id)
end
LUA;
        // 计算截止分数： (now << 22) | ((1<<22)-1)
        $now = time();
        $nowScore = (($now & ((1 << 42) - 1)) << 22) | ((1 << 22) - 1);
        // 重要：调用 Lua 脚本时，KEYS 传参部分需使用 withPrefix() 保持与 push/requeue/remove 等方法一致的 key 生成逻辑
        $zsetKey = $this->redis->withPrefix(self::ZSET_KEY);
        $hashKey = $this->redis->withPrefix(self::HASH_KEY);
        $raw = $this->redis->call($lua, [$zsetKey, $hashKey, $nowScore], 2);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // 如果 payload 不能被解析，作为防护清理对应 hash，避免再次取到
            // raw 可能是字符串非 json，这是异常情形，记录后返回 null
            // （这里没有 logger，抛异常以便上层发现）
            throw new \RuntimeException('Invalid task payload JSON popped from Redis: ' . json_last_error_msg());
        }

        return $data ? Task::fromArray($data) : null;
    }

    public function requeue(Task $task): void
    {
        // 更新 retryCount、updatedAt
        $task->retryCount++;
        $task->updatedAt = time();

        // 指数退避 + 抖动（基于 retryDelay 基数，封顶 3600s）
        $base = max(1, $task->retryDelay ?: 1);
        $cap  = 3600;
        $expMultiplier = 1 << min($task->retryCount, 12); // 2^n
        $exp  = (int) min($cap, $base * $expMultiplier);
        $jitter = random_int(0, (int)($exp * 0.25)); // 0~25% 抖动
        $task->scheduledAt = time() + $exp + $jitter;

        $this->pushTaskToQueue($task);

        // P1: 若任务原状态为 failed，从死信队列中移除，避免幽灵数据
        if ($task->status === 'failed') {
            $this->removeFromFailedList($task->id);
        }
    }

    /**
     * 将任务重新调度回队列，不递增 retryCount（锁争用 / 延迟执行场景）
     * 保留调用方预设的 scheduledAt
     */
    public function reschedule(Task $task): void
    {
        $task->updatedAt = time();
        $this->pushTaskToQueue($task);
    }

    /**
     * 内部：将 Task 写入 HASH + ZSET
     */
    private function pushTaskToQueue(Task $task): void
    {
        $json = json_encode($task->toArray(), JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Task json encode error: ' . json_last_error_msg());
        }
        $score = $this->calcScore($task);
        $this->redis->hSet(self::HASH_KEY, $task->id, $json);
        $this->redis->zAdd(self::ZSET_KEY, [$score => $task->id]);
    }

    /**
     * 从死信队列中移除指定任务
     */
    private function removeFromFailedList(string $taskId): void
    {
        // 遍历 failed_list 前 1000 条找到并重建列表（避免留下空洞）
        $items = $this->redis->lRange(self::FAILED_LIST, 0, 999);
        $filtered = [];
        foreach ($items as $raw) {
            $data = json_decode($raw, true);
            if (($data['id'] ?? '') !== $taskId) {
                $filtered[] = $raw;
            }
        }
        if (count($filtered) < count($items)) {
            $this->redis->del(self::FAILED_LIST);
            foreach ($filtered as $raw) {
                $this->redis->lPush(self::FAILED_LIST, $raw);
            }
        }
    }

    public function remove(string $taskId): void
    {
        $this->redis->zRem(self::ZSET_KEY, $taskId);
        $this->redis->hDel(self::HASH_KEY, $taskId);
    }

    public function moveToFailedQueue(Task $task): void
    {
        $task->status = 'failed';
        $task->updatedAt = time();

        $payload = json_encode($task->toArray(), JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            // 如果无法序列化，将简单记录部分字段
            $fallback = json_encode([
                'id' => $task->id,
                'status' => 'failed',
                'error' => 'serialize_error',
                'updatedAt' => $task->updatedAt
            ], JSON_UNESCAPED_UNICODE);
            $this->redis->lPush(self::FAILED_LIST, $fallback);
        } else {
            $this->redis->lPush(self::FAILED_LIST, $payload);
        }

        // 限制长度，防止列表无限增长
        $this->redis->lTrim(self::FAILED_LIST, 0, 1999);

        // 从主队列中移除（保险）
        $this->remove($task->id);
    }

    public function moveToSuccessQueue(Task $task): void
    {
        $task->status = 'success';
        $task->updatedAt = time();

        $payload = json_encode($task->toArray(), JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            $this->redis->lPush(self::SUCCESS_LIST, $payload);
            // 限制长度，只保留最近 2000 条成功记录
            $this->redis->lTrim(self::SUCCESS_LIST, 0, 1999);

            // 记录到统计 ZSET
            $this->redis->zAdd('scheduler:stats:success', [$task->updatedAt => $task->id]);
            // 保持 30 天统计数据
            $this->redis->expire('scheduler:stats:success', 30 * 24 * 3600);
        }

        // 从主队列中移除
        $this->remove($task->id);
    }
}
