<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

use Framework\Scheduler\Interfaces\TaskStorageInterface;
use Framework\Scheduler\Interfaces\ZombieDetectorInterface;

/**
 * 恢复引擎
 * 从 MySQL 读取 pending 任务并重建 Redis 队列。
 * S-3 修复: 将 recoverAll() 的原始行消费逻辑集中在此。
 * #1 修复: 恢复任务直接写 Redis，不走 PersistenceQueue 避免 MySQL duplicate INSERT。
 * v3.2 重构: DatabaseQueue → TaskStorageInterface；新增 ZombieDetectorInterface。
 * PHP 7.2 兼容。
 */
class RecoveryEngine
{
    /** @var TaskStorageInterface v3.2: DatabaseQueue → TaskStorageInterface */
    private $storage;

    /** @var \Framework\Cache\Drivers\RedisCache #1: 直接操作 Redis，绕过装饰器链 */
    private $redis;

    /** @var ZombieDetectorInterface|null v3.2 新增 */
    private $zombieDetector;

    /** @var \Framework\Scheduler\Logger v3.2 新增 */
    private $logger;

    /** @var EventBus|null */
    private $eventBus;

    /**
     * @param TaskStorageInterface                $storage        v3.2 修改
     * @param \Framework\Cache\Drivers\RedisCache $redis
     * @param ZombieDetectorInterface|null        $zombieDetector v3.2 新增
     * @param \Framework\Scheduler\Logger|null    $logger         v3.2 新增
     * @param EventBus|null                       $eventBus
     */
    public function __construct(
        TaskStorageInterface $storage,
        \Framework\Cache\Drivers\RedisCache $redis,
        ?ZombieDetectorInterface $zombieDetector = null,
        ?\Framework\Scheduler\Logger $logger = null,
        ?EventBus $eventBus = null
    ) {
        $this->storage       = $storage;
        $this->redis         = $redis;
        $this->zombieDetector = $zombieDetector;
        $this->logger        = $logger ?? new \Framework\Scheduler\Logger();
        $this->eventBus      = $eventBus;
    }

    /**
     * 全量恢复: MySQL → Redis
     * 在 Redis 启动后检测到数据为空时调用。
     * #1 修复: 直接写 Redis HSET + ZADD，不走 queue->push()，
     *          避免 PersistenceQueue 再次 INSERT MySQL 导致 duplicate key。
     * v3.2: recoverAll() 返回 Task[] 对象数组，无需 rowToTask()。
     *
     * @param int $limit
     * @return int 恢复的任务数
     */
    public function recoverAll(int $limit = 10000): int
    {
        $tasks = $this->storage->recoverAll($limit);  // ← 返回 Task[] 对象数组
        $count = 0;

        foreach ($tasks as $task) {
            try {
                // 直接写 Redis（Hash + ZSET），绕过装饰器链
                $json = json_encode($task->toArray(), JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $this->logger->log('json_encode failed for task ' . $task->id, 'ERROR');
                    continue;
                }

                $this->redis->hSet('scheduler:queue:hash', $task->id, $json);

                // 计算 score（复用 RedisTaskQueue 的编码逻辑）
                $ts    = $task->scheduledAt & ((1 << 42) - 1);
                $prio  = max(0, min($task->priority, (1 << 22) - 1));
                $score = ($ts << 22) | $prio;
                $this->redis->zAdd('scheduler:queue:zset', [$score => $task->id]);

                $count++;
            } catch (\Throwable $e) {
                $this->logger->log('recoverAll: failed to restore task '
                    . $task->id . ': ' . $e->getMessage(), 'ERROR');
                continue;
            }
        }

        if ($count > 0 && $this->eventBus !== null) {
            $this->eventBus->emit('worker.started', [
                'worker_id'       => gethostname() . ':' . getmypid(),
                'host'            => gethostname(),
                'pid'             => getmypid(),
                'recovered_count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * 检测并处理僵尸任务
     * 由定时器调用（FPM: 主循环每 60 轮，Swoole: Timer::tick 60000）
     * v3.2: 不再接收 WorkerCoordinator 参数，通过 $this->zombieDetector 调用
     *
     * @return int 处理的僵尸任务数
     */
    public function handleZombies(): int
    {
        if ($this->zombieDetector === null) {
            return 0;
        }

        $zombies = $this->zombieDetector->detectZombies();
        $count   = 0;

        foreach ($zombies as $zombie) {
            $this->zombieDetector->markZombieTask($zombie['id']);
            $count++;
        }

        return $count;
    }
}
