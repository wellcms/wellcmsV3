<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Collector;

/**
 * QueryCollector 用于在内存中收集“[耗时] 完整 SQL”条目，供后面打印或调试使用
 */
class QueryCollector
{
    /** @var float */
    private static $startTime = 0;
    /** @var string[] */
    protected static $queries = [];

    /**
     * 程序执行开始时间
     */
    public static function startTime(): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            call_user_func([$coroClass, 'getContext'])->queryStartTime = microtime(true);
        } else {
            self::$startTime = microtime(true);
        }
    }

    /**
     * 获取程序执行时间
     */
    public static function processedTime(): float
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $start = call_user_func([$coroClass, 'getContext'])->queryStartTime ?? 0;
            return $start > 0 ? microtime(true) - (float)$start : 0.0;
        }
        return self::$startTime > 0 ? microtime(true) - self::$startTime : 0.0;
    }

    public static function add(string $entry): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            /** @noinspection PhpUndefinedFieldInspection */
            $ctx->queries = $ctx->queries ?? [];
            /** @noinspection PhpUndefinedFieldInspection */
            $ctx->queries[] = $entry;
        } else {
            self::$queries[] = $entry;
            // 防御 FPM/CLI 静态变量累积泄漏：超过阈值时自动截断
            if (count(self::$queries) > 1000) {
                array_splice(self::$queries, 0, 500);
            }
        }
    }

    /**
     * 返回当前已收集日志条目的数量
     */
    public static function count(): int
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            return count(call_user_func([$coroClass, 'getContext'])->queries ?? []);
        }
        return count(self::$queries);
    }

    /**
     * 返回所有已经收集到的日志条目
     *
     * @return string[]
     */
    public static function getLoggedQueries(): array
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            /** @noinspection PhpUndefinedFieldInspection */
            return call_user_func([$coroClass, 'getContext'])->queries ?? [];
        }
        return self::$queries;
    }

    /**
     * 清空之前收集的日志（如果需要重复使用）
     */
    public static function clear(): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            unset($ctx->queries, $ctx->queryStartTime);
        } else {
            self::$queries = [];
            self::$startTime = 0;
        }
    }

    /**
     * 滑动窗口截断：超过限制时仅保留最后 $keep 条
     */
    public static function slide(int $limit = 100, int $keep = 50): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $queries = $ctx->queries ?? [];
            if (count($queries) > $limit) {
                $ctx->queries = array_slice($queries, -$keep);
            }
        } else {
            if (count(self::$queries) > $limit) {
                self::$queries = array_slice(self::$queries, -$keep);
            }
        }
    }
}

/*
我们把 QueryCollector 放在 Framework\Database 命名空间下，仅提供静态方法 add()、getLoggedQueries() 和 clear()。

add() 接受一个字符串（格式是 [耗时] 完整 SQL），然后追加到 self::$queries 数组末尾；

getLoggedQueries() 最终返回一个 string[] 数组，控制器里可以直接 print_r()；

如果同一个进程里多次调用同一个控制器动作，记得在最开始前 QueryCollector::clear() 一下，避免前一次残余。
*/