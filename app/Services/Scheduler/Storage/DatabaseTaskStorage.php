<?php

declare(strict_types=1);

namespace App\Services\Scheduler\Storage;

use Framework\Scheduler\Task;
use Framework\Scheduler\Interfaces\TaskStorageInterface;
use Framework\Utils\UuidHelper;

/**
 * MySQL 调度任务存储层
 *
 * v3.2 重构: 从 src/Scheduler/Queue/DatabaseQueue.php 迁入。
 *             命名空间 Framework\Scheduler\Queue → App\Services\Scheduler\Storage。
 *             实现 TaskStorageInterface，而非裸类。
 *
 * v3.3 重构: 注入 SchedulerTaskModel 替代 ProxyDriver。
 *             消除裸 PDO 手写 SQL，通过 BaseModel 标准方法 + Model 封装方法实现合规访问。
 *
 * PHP 7.2 兼容。
 */
class DatabaseTaskStorage implements TaskStorageInterface
{
    /** @var \App\Models\SchedulerTaskModel */
    private $model;

    /** @var \Framework\Scheduler\Logger */
    private $logger;

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
     * 插入新任务
     *
     * @param Task $task
     * @throws \RuntimeException
     */
    public function insertTask(Task $task): void
    {
        try {
            // 清理上一轮同 dedupeKey 的旧行，避免 MySQL UNIQUE 约束冲突。
            // 进入此方法前 Redis setNx 已做串行化防重，无并发竞争。
            if ($task->dedupeKey !== '') {
                $this->model->delete(['dedupe_key' => $task->dedupeKey]);
            }

            $this->model->insert([
                'id'             => UuidHelper::toBinary($task->id),
                'class_name'     => $task->className,
                'method_name'    => $task->methodName,
                'args'           => json_encode($task->args, JSON_UNESCAPED_UNICODE),
                'priority'       => $task->priority,
                'max_retries'    => $task->maxRetries,
                'retry_delay'    => $task->retryDelay,
                'timeout'        => $task->timeout,
                'callback_url'   => $task->callbackUrl,
                'callback_method'=> $task->callbackMethod,
                'retry_count'    => $task->retryCount,
                'status'         => $task->status,
                'dedupe_key'     => ($task->dedupeKey === '' ? null : $task->dedupeKey),
                'created_at'     => $task->createdAt,
                'updated_at'     => $task->updatedAt,
                'scheduled_at'   => $task->scheduledAt,
                'error'          => $task->error,
            ]);
        } catch (\Throwable $e) {
            $this->logger->log('insertTask failed: ' . $e->getMessage()
                . ' task_id=' . $task->id, 'ERROR');
            throw new \RuntimeException(
                'Failed to persist task: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 更新任务状态（自动记录心跳时间）
     *
     * @param string $taskId
     * @param array  $data
     */
    public function updateTaskStatus(string $taskId, array $data): void
    {
        if (!isset($data['heartbeat_at'])) {
            $data['heartbeat_at'] = time();
        }

        try {
            $this->model->update(['id' => UuidHelper::toBinary($taskId)], $data);
        } catch (\Throwable $e) {
            $this->logger->log('updateTaskStatus failed: ' . $e->getMessage()
                . ' task_id=' . $taskId, 'ERROR');
        }
    }

    /**
     * 恢复一条 pending 任务
     *
     * @return Task|null
     */
    public function recoverOne(): ?Task
    {
        try {
            $row = $this->model->read(
                [
                    'status'       => [\Framework\Scheduler\Task::STATUS_PENDING, \Framework\Scheduler\Task::STATUS_RETRYING],
                    'scheduled_at' => ['<=' => time()],
                ],
                ['scheduled_at' => 1]  // ASC
            );

            if (empty($row)) {
                return null;
            }

            return $this->rowToTask($row);
        } catch (\Throwable $e) {
            $this->logger->log('recoverOne failed: ' . $e->getMessage(), 'WARNING');
            return null;
        }
    }

    /**
     * 批量恢复 pending 任务
     *
     * 走 BaseModel::find() → 连接池，消除裸 PDO。
     * 条件使用 idx_status_scheduled 索引，status 左优先。
     *
     * @param int $limit 最大恢复条数
     * @return Task[]
     */
    public function recoverAll(int $limit = 10000): array
    {
        $tasks = [];

        try {
            $rows = $this->model->find(
                [
                    'status' => [\Framework\Scheduler\Task::STATUS_PENDING, \Framework\Scheduler\Task::STATUS_RETRYING],
                    // 移除 scheduled_at 限制。Redis ZSET score 编码了 scheduledAt，
                    // pop() 的 Lua 脚本 zrangebyscore -inf now 只弹出到期任务。
                    // 未来任务在 ZSET 中安全等待。
                ],
                ['scheduled_at' => 1, 'id' => 1],
                1,
                $limit
            );

            foreach ($rows as $row) {
                $tasks[] = $this->rowToTask($row);
            }
        } catch (\Throwable $e) {
            $this->logger->log('recoverAll failed: ' . $e->getMessage(), 'ERROR');
        }

        return $tasks;
    }

    /**
     * 游标分页查询指定类的 pending/retrying 任务。
     * 条件 WHERE class_name=? AND status IN (?,?) [AND id > ?] ORDER BY id ASC LIMIT ?。
     * 无复合索引，低频路径接受全表扫描。
     * 排序使用 id（UUIDv7，时间前缀单调递增），确保游标单向推进不遗漏。
     *
     * @param string $className
     * @param string $lastId   UUID 字符串，空串为首页
     * @param int    $limit
     * @return array
     */
    public function findPendingByClass(string $className, string $lastId = '', int $limit = 500): array
    {
        $condition = [
            'class_name' => $className,
            'status'     => [
                \Framework\Scheduler\Task::STATUS_PENDING,
                \Framework\Scheduler\Task::STATUS_RETRYING,
            ],
        ];
        if ($lastId !== '') {
            $condition['id'] = ['>' => \Framework\Utils\UuidHelper::toBinary($lastId)];
        }
        // page=1: 游标 id > ? 代替 OFFSET，LIMIT = limit
        return $this->model->find($condition, ['id' => 'ASC'], 1, $limit);
    }

    /**
     * 删除任务（终态清理）
     *
     * @param string $taskId
     */
    public function deleteTask(string $taskId): void
    {
        try {
            $this->model->delete(['id' => UuidHelper::toBinary($taskId)]);
        } catch (\Throwable $e) {
            $this->logger->log('deleteTask failed: ' . $e->getMessage()
                . ' task_id=' . $taskId, 'ERROR');
        }
    }

    /**
     * 将原始行转为 Task 对象
     *
     * @param array $row
     * @return Task
     */
    private function rowToTask(array $row): Task
    {
        return Task::fromArray([
            'id'             => UuidHelper::fromBinary($row['id']),
            'className'      => $row['class_name'],
            'methodName'     => $row['method_name'],
            'args'           => json_decode($row['args'] ?? '[]', true) ?: [],
            'priority'       => (int)$row['priority'],
            'maxRetries'     => (int)$row['max_retries'],
            'retryDelay'     => (int)$row['retry_delay'],
            'timeout'        => (int)$row['timeout'],
            'callbackUrl'    => $row['callback_url'] ?? '',
            'callbackMethod' => $row['callback_method'] ?? \Framework\Scheduler\Task::METHOD_POST,
            'retryCount'     => (int)$row['retry_count'],
            'status'         => $row['status'],
            'createdAt'      => (int)$row['created_at'],
            'updatedAt'      => (int)$row['updated_at'],
            'scheduledAt'    => (int)$row['scheduled_at'],
            'dedupeKey'      => $row['dedupe_key'] ?? '',
            'error'          => $row['error'] ?? '',
        ], false);
    }
}
