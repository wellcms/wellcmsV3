<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Interfaces;

/**
 * 声明式调度接口
 *
 * 实现此接口的 Job 将由调度引擎自动维护调度周期：
 * 1. handle() 执行完成后 → 引擎调用 getNextSchedule()
 * 2. 自动创建下一次任务（含 dedupeKey，防止重复）
 * 3. 启动时自动检测断链并重新播种
 *
 * ⚠️ 不破坏现有 Job：现有 Job 不实现此接口，行为完全不变。
 * ⚠️ 如果 Job 同时实现此接口又在 handle() 内调用了 createTask()，
 *    引擎会忽略 self-loop（通过 dedupeKey duplicate 检测），不制造双倍任务。
 *
 * PHP 7.2 兼容。
 */
interface ScheduleProviderInterface
{
    /**
     * 返回下次调度的 Unix 时间戳
     * 引擎自动管理 dedupeKey，无需 Job 关心。
     *
     * @return int Unix timestamp
     */
    public function getNextSchedule(): int;

    /**
     * 返回该 Job 的调度优先级（0-10，越小越优先）
     * 引擎在自动播种时使用此优先级创建任务。
     *
     * @return int 0-10
     */
    public function getSchedulePriority(): int;

    /**
     * 返回该 Job 的调度描述（用于管理后台展示）
     *
     * @return string
     */
    public function getScheduleDescription(): string;
}
