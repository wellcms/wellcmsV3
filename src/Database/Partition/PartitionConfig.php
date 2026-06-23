<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

/**
 * 单个表的分区配置值对象。
 *
 * 由插件 install.php 通过 PartitionManager::register() 创建并传入。
 * 所有属性不可变（构造后只读）。
 *
 * PHP 7.2 兼容：使用简单类型声明。
 */
class PartitionConfig
{
    /** @var string 纯表名，不携带前缀（如 'forum_thread'） */
    public $table;

    /** @var string 分区列（如 'created_at'） */
    public $partitionColumn;

    /** @var string 周期：PartitionPeriod::Quarter 或 PartitionPeriod::Month */
    public $period;

    /** @var int 预创建分区数（默认 4 个季度） */
    public $advanceCount;

    /** @var int 保留周期数（0 表示永不清除；默认 8 个季度即 2 年） */
    public $retention;

    /** @var string|null 子分区列（如 'thread_id' / 'user_id'），null 表示无子分区 */
    public $subPartitionColumn;

    /** @var int 子分区数（默认 0，无子分区） */
    public $subPartitions;

    /** @var string 命名模式：'pYYYYQ{N}' 或 'pYYYYMM' */
    public $namingPattern;

    /**
     * @param string      $table              纯表名，不携带前缀
     * @param string      $partitionColumn    分区列名
     * @param string      $period             周期常量 PartitionPeriod::Quarter / Month
     * @param int         $advanceCount       预创建分区数（默认 4）
     * @param int         $retention          保留周期数（默认 8，0 为永不清除）
     * @param string|null $subPartitionColumn 子分区列名（null 表示无子分区）
     * @param int         $subPartitions      子分区数（默认 0）
     */
    public function __construct(
        string $table,
        string $partitionColumn,
        string $period = 'quarter',
        int $advanceCount = 4,
        int $retention = 8,
        ?string $subPartitionColumn = null,
        int $subPartitions = 0
    ) {
        $this->table = $table;
        $this->partitionColumn = $partitionColumn;
        $this->period = $period;
        $this->advanceCount = $advanceCount;
        $this->retention = $retention;
        $this->subPartitionColumn = $subPartitionColumn;
        $this->subPartitions = $subPartitions;
        $this->namingPattern = ($period === 'month') ? 'pYYYYMM' : 'pYYYYQ{N}';
    }
}
