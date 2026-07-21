<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

/**
 * @deprecated 当前架构已由容器（Container）直接承担单例管理职责，本类不再被框架核心使用。
 *             保留仅作向后兼容，建议新代码通过容器获取 PoolInterface 实例。
 */
class PoolManager
{
    /** @var PoolInterface[] 按名称注册的连接池 */
    protected static $pools = [];

    /**
     * 注册一个命名连接池
     *
     * @param string                 $name
     * @param PoolInterface          $pool
     */
    public static function register(string $name, PoolInterface $pool): void
    {
        if (isset(self::$pools[$name])) {
            self::$pools[$name]->closeAll();
        }
        self::$pools[$name] = $pool;
    }

    /**
     * 获取已注册的连接池
     *
     * @param string $name
     * @return PoolInterface
     * @throws \RuntimeException
     */
    public static function get(string $name): PoolInterface
    {
        if (!isset(self::$pools[$name])) {
            throw new \RuntimeException('Connection pool ' . $name . ' not found');
        }
        return self::$pools[$name];
    }

    /**
     * 是否已注册指定名称的连接池
     */
    public static function has(string $name): bool
    {
        return isset(self::$pools[$name]);
    }

    /**
     * 销毁并移除指定连接池
     */
    public static function remove(string $name): void
    {
        if (isset(self::$pools[$name])) {
            self::$pools[$name]->closeAll();
            unset(self::$pools[$name]);
        }
    }

    /**
     * 清空所有连接池
     */
    public static function clear(): void
    {
        foreach (self::$pools as $pool) {
            $pool->closeAll();
        }
        self::$pools = [];
    }
}
