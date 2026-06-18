<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

trait CoroutineAwareTrait
{
    /**
     * 获取当前协程上下文对象；非协程环境返回 null
     */
    protected static function coroContext(): ?object
    {
        if (!\extension_loaded('swoole')) {
            return null;
        }
        $cid = \Swoole\Coroutine::getCid();
        return $cid > 0 ? \Swoole\Coroutine::getContext() : null;
    }
}
