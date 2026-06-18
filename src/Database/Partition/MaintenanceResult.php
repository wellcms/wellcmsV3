<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

/**
 * 分区维护操作的结果值对象。
 *
 * 由 PartitionManager::maintain() 和 maintainOne() 返回。
 * 包含扫描表数、创建/删除分区数、错误详情等度量指标。
 */
class MaintenanceResult
{
    /** @var int 检查的表数 */
    public $tablesScanned = 0;

    /** @var int 新增分区数 */
    public $partitionsCreated = 0;

    /** @var int 删除分区数 */
    public $partitionsDropped = 0;

    /** @var int 错误数 */
    public $errors = 0;

    /** @var array 错误详情列表，每项含 table 和 error 字段 */
    public $errorDetails = array();

    /** @var float 执行耗时（毫秒） */
    public $executionMs = 0.0;

    /** @var string 触发方式：scheduler | admin | cli | lazy */
    public $trigger = '';
}
