<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Core\Traits;


/**
 * 有状态服务适配 Trait
 * 用于解决 Swoole 环境下单例服务的成员属性污染问题
 */
trait StatefulTrait
{
    /** @var array 非协程环境下的状态存储（兼容 PHP 8.2+，避免动态属性废弃） */
    private $stateData = [];
    /**
     * 获取上下文相关的状态数据
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected function getState(string $name, $default = null)
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $key = static::class . ':' . $name;
            return $ctx[$key] ?? $default;
        }

        return $this->stateData[$name] ?? $default;
    }

    /**
     * 设置上下文相关的状态数据
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    protected function setState(string $name, $value): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $key = static::class . ':' . $name;
            $ctx[$key] = $value;
        } else {
            $this->stateData[$name] = $value;
        }
    }

    /**
     * 清除上下文相关的状态数据
     *
     * @param string $name
     * @return void
     */
    protected function unsetState(string $name): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $key = static::class . ':' . $name;
            unset($ctx[$key]);
        } else {
            unset($this->stateData[$name]);
        }
    }

    /**
     * 清空当前服务的所有状态数据 (内存清理核心)
     */
    public function clearStates(): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $prefix = static::class . ':';
            foreach ($ctx as $key => $val) {
                if (0 === strpos((string)$key, $prefix)) {
                    unset($ctx[$key]);
                }
            }
        }

        // 重置非协程环境下的状态数组（针对 FPM 或单进程模式）
        $this->stateData = [];
    }
}
