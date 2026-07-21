<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Queue;

use Framework\Scheduler\Task;
use Framework\Scheduler\Interfaces\TaskQueueInterface;
use Framework\Scheduler\Interfaces\TaskStorageInterface;
use Framework\Scheduler\EventBus;

/**
 * MySQL 持久化装饰器
 *
 * 先写 MySQL（权威副本），再写 Redis（高速队列）。
 * moveToSuccessQueue/moveToFailedQueue 不委托 inner，
 * 直接操作 Redis 索引，防止 inner（RedisTaskQueue）调用 remove() 清空 Hash。
 *
 * P0 #1 修复: 不再调用 inner->moveTo*()，直接操作 Redis 索引。
 * v3.2 重构: DatabaseQueue → TaskStorageInterface。
 * PHP 7.2 兼容。
 */
class PersistenceQueue implements TaskQueueInterface
{
    /** @var TaskQueueInterface 被装饰的 Redis 队列 */
    private $inner;

    /** @var \Framework\Cache\Drivers\RedisCache P0 #1: 直接操作 Redis 索引 */
    private $redis;

    /** @var TaskStorageInterface 任务持久化存储接口 */
    private $storage;

    /** @var EventBus|null */
    private $eventBus;

    /**
     * @param TaskQueueInterface  $inner
     * @param TaskStorageInterface $storage
     * @param \Framework\Cache\Drivers\RedisCache $redis
     * @param EventBus|null       $eventBus
     */
    public function __construct(
        TaskQueueInterface $inner,
        TaskStorageInterface $storage,
        \Framework\Cache\Drivers\RedisCache $redis,
        ?EventBus $eventBus = null
    ) {
        $this->inner = $inner;
        $this->storage = $storage;
        $this->redis = $redis;
        $this->eventBus = $eventBus;
    }

    /**
     * 先写 MySQL（权威副本），再写 Redis（高速队列）
     * MySQL 失败 → 抛出异常，Redis 不写入
     *
     * @param Task $task
     * @throws \RuntimeException
     */
    public function push(Task $task): void
    {
        $this->storage->insertTask($task);
        $this->inner->push($task);

        if ($this->eventBus !== null) {
            $this->eventBus->emit('task.created', [
                'id' => $task->id,
                'class_name' => $task->className,
                'scheduled_at' => $task->scheduledAt,
                'dedupe_key' => $task->dedupeKey,
            ]);
        }
    }

    /**
     * 优先读 Redis，Redis 空时从 MySQL 恢复
     *
     * @return Task|null
     */
    public function pop(): ?Task
    {
        $task = $this->inner->pop();

        if ($task === null) {
            // Redis 无到期任务 → 从 MySQL 恢复一条
            // 恢复的任务直接返回执行，不进 Redis 队列，避免被二次消费
            $task = $this->storage->recoverOne();
        }

        return $task;
    }

    /**
     * 重试：MySQL 更新 → Redis 重排
     *
     * @param Task $task
     */
    public function requeue(Task $task): void
    {
        $task->retryCount++;
        $task->updatedAt = time();
        $task->error = '';

        $this->storage->updateTaskStatus($task->id, [
            'status' => \Framework\Scheduler\Task::STATUS_RETRYING,
            'retry_count' => $task->retryCount,
            'scheduled_at' => $task->scheduledAt,
            'updated_at' => $task->updatedAt,
            'error' => '',
        ]);

        $this->inner->requeue($task);

        if ($this->eventBus !== null) {
            $this->eventBus->emit('task.retried', [
                'id' => $task->id,
                'class_name' => $task->className,
                'retry_count' => $task->retryCount,
                'delay_s' => $task->retryDelay,
            ]);
        }
    }

    /**
     * 重新调度（不递增重试计数）
     *
     * @param Task $task
     */
    public function reschedule(Task $task): void
    {
        $task->updatedAt = time();
        $this->storage->updateTaskStatus($task->id, [
            'status' => $task->status,
            'scheduled_at' => $task->scheduledAt,
            'updated_at' => $task->updatedAt,
        ]);
        $this->inner->reschedule($task);
    }

    /**
     * 移除任务
     *
     * @param string $taskId
     */
    public function remove(string $taskId): void
    {
        // 先删 Redis（ZSET + HASH），再删 MySQL。
        // 不写 CANCELLED 状态：MySQL 只储存 pending 任务，终态任务不保留。
        // OS crash 在 Redis 删除后、MySQL 删除前 → MySQL 残留 pending 行，
        // recoverAll 恢复后执行 handle()，配置已关闭时不自链→自然终止，无害。
        $this->inner->remove($taskId);
        $this->storage->deleteTask($taskId);
    }

    /**
     * 移入失败队列（v2 模式）
     *
     * 注意：此方法仅在 v2 启用时被调用（覆盖 RedisTaskQueue::moveToFailedQueue）。
     * 切换边界：v2 关闭 → RedisTaskQueue::moveToFailedQueue() 写入 scheduler:queue:failed_list（List）；
     *           v2 开启 → 此方法写入 scheduler:dlq:*（ZSET），不再写入 failed_list。
     * TaskManage::failedList() 已兼容两种模式。
     * 新增消费 failed_list 的代码必须同时兼容 dlq:* 格式。
     *
     * @param Task $task
     */
    public function moveToFailedQueue(Task $task): void
    {
        $task->status = \Framework\Scheduler\Task::STATUS_FAILED;
        $task->completedAt = time();
        $task->updatedAt = time();

        $this->storage->updateTaskStatus($task->id, [
            'status' => \Framework\Scheduler\Task::STATUS_FAILED,
            'error' => $task->error,
            'completed_at' => $task->completedAt,
            'updated_at' => $task->updatedAt,
        ]);

        // 直接操作 Redis 索引（不调用 inner，防止 remove → hDel）
        $this->redis->zRem('scheduler:running:zset', $task->id);
        $this->redis->hSet('scheduler:queue:hash', $task->id, json_encode($task->toArray()));

        // 死信索引（写入 dlq:* ZSET，不再写入 failed_list）
        $bucket = ($task->retryCount >= $task->maxRetries && $task->maxRetries > 0)
            ? 'max_retry' : 'other';
        $dlqKey = 'scheduler:dlq:' . $bucket;
        $this->redis->zAdd($dlqKey, [$task->completedAt => $task->id]);
        $this->redis->expire($dlqKey, 259200); // 3 天

        if ($this->eventBus !== null) {
            $this->eventBus->emit('task.failed', [
                'id' => $task->id,
                'class_name' => $task->className,
                'error' => $task->error,
                'duration_ms' => $task->completedAt - ($task->startedAt ?: $task->completedAt),
                'bucket' => $bucket,
            ]);
        }

        $this->storage->deleteTask($task->id);
    }

    /**
     * 移入成功队列
     * P0 #1 修复: 不委托 inner（RedisTaskQueue 会 remove → hDel），直接写 MySQL + Redis 索引
     *
     * @param Task $task
     */
    public function moveToSuccessQueue(Task $task): void
    {
        $task->status = \Framework\Scheduler\Task::STATUS_SUCCESS;
        $task->completedAt = time();
        $task->updatedAt = time();

        $this->storage->updateTaskStatus($task->id, [
            'status' => \Framework\Scheduler\Task::STATUS_SUCCESS,
            'error' => '',
            'completed_at' => $task->completedAt,
            'updated_at' => $task->updatedAt,
        ]);

        $ttl = 3 * 24 * 3600; // 3 天 

        // 直接操作 Redis 索引（不调用 inner，防止 remove → hDel）
        $this->redis->zAdd('scheduler:stats:success', [$task->completedAt => $task->id]);
        $this->redis->expire('scheduler:stats:success', $ttl);
        $this->redis->zRem('scheduler:running:zset', $task->id);
        $this->redis->hSet('scheduler:queue:hash', $task->id, json_encode($task->toArray()));

        // 记录执行时间
        $elapsed = $task->completedAt - ($task->startedAt ?: $task->completedAt);
        $this->redis->hSet('scheduler:execution_times', $task->id, (string)$elapsed);
        $this->redis->expire('scheduler:execution_times', $ttl);

        if ($this->eventBus !== null) {
            $this->eventBus->emit('task.completed', [
                'id' => $task->id,
                'class_name' => $task->className,
                'duration_ms' => $elapsed,
            ]);
        }

        $this->storage->deleteTask($task->id);
    }
}