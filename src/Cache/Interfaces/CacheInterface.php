<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Cache\Interfaces;

/**
 * 缓存接口，支持多驱动（Redis, Memcached, YAC, APCu）
 * 并由 CacheManager 托管
 *
 * @method int setBit(string $key, int $offset, int $value) 设置位图
 * @method int getBit(string $key, int $offset) 获取位图
 * @method int bitCount(string $key) 统计位图
 * @method bool expire(string $key, int $seconds) 设置过期时间
 * @method int zAdd(string $key, array $scoreMembers) 添加有序集合
 * @method array zRevRange(string $key, int $start, int $stop, bool $withScores = false) 获取有序集合（降序）
 * @method int zCount(string $key, $min, $max) 统计有序集合区间
 * @method int zRem(string $key, $members) 删除有序集合成员
 * @method int zRemRangeByScore(string $key, $min, $max) 按分数区间删除有序集合成员
 */
interface CacheInterface
{
    /** 获取驱动内部经过前缀和哈希处理后的真实 Key */
    public function withPrefix(string $key): string;
    public function getPrefix(): string;

    /** 批量获取缓存
     * @param array $keys
     * @param mixed $default
     * @return array
     */
    public function getMulti(array $keys, $default = null): array;

    /** PSR-16 兼容别名 */
    public function getMultiple(array $keys, $default = null): array;

    /** 批量设置缓存
     * @param array $items  ['key' => 'value', ...]
     * @param int   $ttl    生存时间（秒）
     * @return bool
     */
    public function setMulti(array $items, int $ttl = 0): bool;

    /** PSR-16 兼容别名 */
    public function setMultiple(array $items, int $ttl = 0): bool;

    /** 获取缓存
     * @param string $key
     * @param mixed  $default
     * @return mixed
     * @return array
     */
    public function get(string $key, $default = null);

    /** 设置缓存
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl    生存时间（秒）
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool;

    /** 删除缓存
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /** 自增缓存值
     * @param string $key
     * @param int    $step
     * @param int    $ttl   当键不存在时，创建并设置ttl
     * @return int|false 新值或失败
     */
    public function increment(string $key, int $step = 1, int $ttl = 0);

    /**
     * @return string|null
     */
    public function lock(string $key, int $ttl = 0);

    /**
     * @return bool
     */
    public function unlock(string $key, string $token);

    /**
     * @return bool
     */
    public function isLocked(string $key);

    /** 清空所有缓存（慎用）
     * @return bool
     */
    public function clear(): bool;
    public function allow(string $key, int $cap, int $rate, array $only = []): bool;
    public function original(string $only = '');
    public function cacheWithLock(string $key, string $lockKey, callable $cacheGetter, int $maxAttempts = 5, int $cacheTtl = 0, int $lockTtl = 3);
}
