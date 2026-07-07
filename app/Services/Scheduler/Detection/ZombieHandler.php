<?php

declare(strict_types=1);

namespace App\Services\Scheduler\Detection;

use Framework\Scheduler\Interfaces\ZombieDetectorInterface;
use Framework\Utils\UuidHelper;

/**
 * 僵尸任务处理器
 *
 * v3.2 新增: 从 src/Scheduler/WorkerCoordinator.php 迁入的 SQL 逻辑。
 *             WorkerCoordinator 保留纯 Redis 心跳部分。
 *
 * v3.3 重构: 注入 SchedulerTaskModel 替代 ProxyDriver。
 *             消除裸 PDO 手写 SQL，通过 BaseModel 标准方法实现合规访问。
 *
 * PHP 7.2 兼容。
 */
class ZombieHandler implements ZombieDetectorInterface
{
    /** @var \App\Models\SchedulerTaskModel */
    private $model;

    /** @var \Framework\Scheduler\Logger */
    private $logger;

    /** @var int */
    private $zombieThreshold = 120;

    /**
     * @param \App\Models\SchedulerTaskModel  $model
     * @param \Framework\Scheduler\Logger|null $logger
     */
    public function __construct(
        \App\Models\SchedulerTaskModel $model,
        ?\Framework\Scheduler\Logger $logger = null
    ) {
        $this->model = $model;
        $this->logger = $logger ?? new \Framework\Scheduler\Logger();
    }

    /**
     * @param int $zombieThreshold
     */
    public function configure(int $zombieThreshold): void
    {
        $this->zombieThreshold = $zombieThreshold;
    }

    /**
     * 检测僵尸任务
     *
     * 使用 configure() 注入的 zombieThreshold。
     *
     * @return array
     */
    public function detectZombies(): array
    {
        $threshold = time() - $this->zombieThreshold;

        try {
            $rows = $this->model->find(
                [
                    'status'       => \Framework\Scheduler\Task::STATUS_RUNNING,
                    'heartbeat_at' => ['<' => $threshold],
                    'updated_at'   => ['<' => $threshold],
                ],
                [],      // 无需排序
                1,       // page = 1 → LIMIT 100 OFFSET 0
                100,
                '',      // 数值索引（与原返回格式一致）
                ['id', 'class_name']
            );

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->log('detectZombies failed: ' . $e->getMessage(), 'WARNING');
            return [];
        }
    }

    /**
     * 标记僵尸任务为失败
     *
     * @param string $taskId
     */
    public function markZombieTask(string $taskId): void
    {
        try {
            $now = time();
            $this->model->update(
                ['id' => UuidHelper::toBinary($taskId)],
                [
                    'status'       => \Framework\Scheduler\Task::STATUS_FAILED,
                    'error'        => 'zombie: worker lost',
                    'completed_at' => $now,
                    'heartbeat_at' => $now,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->log('markZombieTask failed for ' . $taskId . ': ' . $e->getMessage(), 'ERROR');
        }
    }
}
