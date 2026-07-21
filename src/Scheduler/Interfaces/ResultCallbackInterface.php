<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Interfaces;

use Framework\Scheduler\Task;

/**
 * 任务执行完毕后通知回调接口
 */
interface ResultCallbackInterface
{
    /**
     * @param Task  $task
     * @param bool  $success   本次执行是否成功
     * @param float $elapsed   耗时（秒）
     * @param string $errorMsg 失败原因
     */
    public function notify(Task $task, bool $success, float $elapsed, string $errorMsg): void;
}
