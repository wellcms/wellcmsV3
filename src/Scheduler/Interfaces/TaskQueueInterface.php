<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Interfaces;

use Framework\Scheduler\Task;

/**
 * 任务队列接口：定义基本的队列操作
 */
interface TaskQueueInterface
{
    /**
     * 把任务推入队列，按优先级决定分数
     * @param Task $task
     */
    public function push(Task $task): void;

    /**
     * 从队列中弹出一个优先级最高（分数最小）的任务；如果队列空，返回 null
     * @return Task|null
     */
    public function pop(): ?Task;

    /**
     * 将某任务重新推回队列（比如重试），并更新优先级/重试计数等信息
     */
    public function requeue(Task $task): void;

    /**
     * 将任务重新调度回队列但不递增 retryCount（锁争用/延迟执行场景）
     * 保留调用方设置的 scheduledAt
     */
    public function reschedule(Task $task): void;

    /**
     * 删除某个任务，不再调度
     */
    public function remove(string $taskId): void;

    /**
     * 将任务移动到失败队列(“死信队列”)，方便人工或定时脚本处理
     */
    public function moveToFailedQueue(Task $task): void;

    /**
     * 将任务移动到成功记录列表
     */
    public function moveToSuccessQueue(Task $task): void;
}
