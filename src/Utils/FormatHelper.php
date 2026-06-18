<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

class FormatHelper
{
    /**
     * 格式化时间戳
     * @param int $timestamp 时间戳
     * @param $lang 语言包
     * @return string
     */
    public static function humanDate(int $timestamp, $lang): string
    {
        $seconds = time() - $timestamp;

        if ($seconds < 0) $seconds = 0;

        if ($seconds >= 31536000) {
            return date('Y-n-j', $timestamp);
        }

        $units = [
            2592000 => 'month_ago',
            86400   => 'day_ago',
            3600    => 'hour_ago',
            60      => 'minute_ago',
            1       => 'second_ago'
        ];

        foreach ($units as $divisor => $key) {
            if ($seconds >= $divisor) {
                return (string)floor($seconds / $divisor) . $lang->get($key);
            }
        }

        return '0' . $lang->get('second_ago');
    }

    /**
     * 格式化数字 为 1K+ / 1M+ 形式
     * @param int $number
     * @return string
     */
    public static function formatNumber(int $number): string
    {
        if ($number < 1000) {
            return (string)$number;
        }

        $units = ['K', 'M', 'B', 'T'];
        $unit = '';
        $value = (float)$number;

        foreach ($units as $u) {
            if ($value < 1000) break;
            $value /= 1000;
            $unit = $u;
        }

        return number_format($value, 1) . $unit . '+';
    }

    /**
     * 格式化文件大小
     * @param int $number 字节数
     * @return string
     */
    public static function humanSize(int $number): string
    {
        if ($number < 1024) {
            return $number . 'B';
        }

        $units = ['K', 'M', 'G', 'T', 'P'];
        $unit = 'B';
        $value = (float)$number;

        foreach ($units as $u) {
            $value /= 1024;
            $unit = $u;
            if ($value < 1024) break;
        }

        return number_format($value, 2, '.', '') . $unit;
    }
}
