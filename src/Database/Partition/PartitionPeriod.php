<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

/**
 * 分区周期类型常量与工具方法。
 *
 * PHP 7.2 兼容：使用 final class + const 模拟枚举。
 * 迁移至 PHP 8.1+ 后可使用原生 enum。
 *
 * 提供：
 * - 周期常量 Quarter / Month
 * - nextBoundary()：计算下个边界时间戳
 * - ago()：计算 N 个周期前的时间戳
 * - formatName()：格式化分区名后缀
 */
final class PartitionPeriod
{
    const Quarter = 'quarter';
    const Month   = 'month';

    /**
     * 计算下一个分区边界时间戳（UTC，0 点）。
     *
     * Quarter 算法：
     *   当前月份所在季度的结束月 = ceil(month/3)*3
     *   下季度开始 = 下个季度第 1 个月的第 1 天 00:00:00
     *   例：2026-05-03 12:30 → 2026-07-01 00:00:00
     *
     * Month 算法：
     *   下个月 1 日 00:00:00
     *   例：2026-05-03 → 2026-06-01 00:00:00
     *
     * @param int    $timestamp 基准时间戳
     * @param string $period    self::Quarter 或 self::Month
     * @return int 下一个边界时间戳
     */
    public static function nextBoundary(int $timestamp, string $period): int
    {
        if ($period === self::Month) {
            return strtotime(date('Y-m-01', $timestamp) . ' +1 month');
        }

        // Quarter
        $month = (int)date('n', $timestamp);
        $year = (int)date('Y', $timestamp);
        $nextQuarterMonth = (int)(ceil($month / 3) * 3) + 1;

        if ($nextQuarterMonth > 12) {
            $nextQuarterMonth = 1;
            $year++;
        }

        return strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $nextQuarterMonth));
    }

    /**
     * 计算 N 个周期前的边界时间戳（UTC，0 点）。
     * 用于 retention 计算："8 个季度前的分区可以删除"。
     *
     * @param int    $timestamp 基准时间戳
     * @param string $period    self::Quarter 或 self::Month
     * @param int    $count     偏移周期数（必须 >= 0）
     * @return int 0 表示无法计算（count <= 0），否则返回边界时间戳
     */
    public static function ago(int $timestamp, string $period, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        if ($period === self::Month) {
            $time = strtotime(date('Y-m-01', $timestamp) . " -{$count} month");
            return $time !== false ? (int)$time : 0;
        }

        // Quarter: 向前偏移 3 * $count 个月
        $months = $count * 3;
        $time = strtotime(date('Y-m-01', $timestamp) . " -{$months} month");
        return $time !== false ? (int)$time : 0;
    }

    /**
     * 格式化分区名后缀。
     *
     * Quarter: 2026-Q1 → "p2026Q1"
     * Month:   2026-05 → "p202605"
     *
     * @param int    $timestamp
     * @param string $period self::Quarter 或 self::Month
     * @return string 分区名，如 "p2026Q1"
     */
    public static function formatName(int $timestamp, string $period): string
    {
        if ($period === self::Month) {
            return 'p' . date('Ym', $timestamp);
        }

        $quarter = (int)(ceil((int)date('n', $timestamp) / 3));
        return 'p' . date('Y', $timestamp) . 'Q' . $quarter;
    }
}
