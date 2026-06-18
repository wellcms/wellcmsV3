<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

/**
 * 协程/线程安全的请求栈
 */
class RequestStack
{
    /** @var array */
    protected static $stacks = [];

    public static function push(\Framework\Http\Interfaces\ServerRequestInterface $request): void{
        $cid = self::getCid();
        if (!isset(self::$stacks[$cid])) {
            self::$stacks[$cid] = [];
        }
        self::$stacks[$cid][] = $request;
    }

    public static function pop(): void{
        $cid = self::getCid();
        if (isset(self::$stacks[$cid])) {
            array_pop(self::$stacks[$cid]);
            if (empty(self::$stacks[$cid])) {
                unset(self::$stacks[$cid]);
            }
        }
    }

    public static function getCurrent(): ?\Framework\Http\Interfaces\ServerRequestInterface
    {
        $cid = self::getCid();
        if (!isset(self::$stacks[$cid]) || empty(self::$stacks[$cid])) return null;
        return end(self::$stacks[$cid]);
    }

    /**
     * @return int
     */
    protected static function getCid()
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (extension_loaded('swoole') && class_exists($coroClass) && call_user_func([$coroClass, 'getCid']) > 0) {
            return call_user_func([$coroClass, 'getCid']);
        }
        return 0;
    }
}
