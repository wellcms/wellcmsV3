<?php

declare(strict_types=1);

namespace Framework\Scheduler\Interfaces;

use Framework\Scheduler\Task;

/**
 * 任务持久化存储接口
 *
 * v3.2 新增: 抽象 MySQL 持久化操作，使框架层不依赖具体数据表。
 * 实现见 App\Services\Scheduler\Storage\DatabaseTaskStorage。
 * PHP 7.2 兼容。
 */
interface TaskStorageInterface
{
    /**
     * 插入新任务
     *
     * @param Task $task
     * @throws \RuntimeException
     */
    public function insertTask(Task $task): void;

    /**
     * 更新任务状态（自动记录 heartbeat_at）
     *
     * @param string $taskId
     * @param array  $data
     */
    public function updateTaskStatus(string $taskId, array $data): void;

    /**
     * 恢复一条到期 pending 任务
     *
     * @return Task|null
     */
    public function recoverOne(): ?Task;

    /**
     * 批量恢复 pending 任务
     *
     * @param int $limit 最大恢复条数
     * @return Task[]
     */
    public function recoverAll(int $limit = 10000): array;

    /**
     * 游标分页查询指定类的 pending/retrying 任务。
     * 使用 id > ? 游标替代 OFFSET，符合游标分页规约。
     *
     * @param string $className 类全名
     * @param string $lastId    上一页最后一条的 UUID 字符串，空串为第一页
     * @param int    $limit     每页最大条数
     * @return array 原始数据行（id 为 BINARY(16)）
     */
    public function findPendingByClass(string $className, string $lastId = '', int $limit = 500): array;

    /**
     * 删除任务（终态清理）
     *
     * 任务到达终态（success/failed/cancelled）后从 MySQL 删除，
     * 保持表内仅存待执行行，对齐权威副本设计意图。
     *
     * @param string $taskId
     */
    public function deleteTask(string $taskId): void;
}
