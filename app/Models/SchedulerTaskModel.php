<?php

declare(strict_types=1);

namespace App\Models;

/**
 * 调度任务数据模型
 *
 * 对应 install.sql 核心表 `well_scheduler_tasks`。
 * 继承 BaseModel 获得标准 CRUD：insert()、update()、read()、find()、delete()、count()。
 *
 * PHP 7.2 兼容。
 */
class SchedulerTaskModel extends BaseModel
{
    /** @var string */
    protected $table = 'scheduler_tasks';
}
