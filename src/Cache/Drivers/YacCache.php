<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Drivers;

class YacCache implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var \Yac */
    private $yac;

    /** @var string */
    private $prefix;

    private function getCacheKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    public function withPrefix(string $key): string
    {
        return $this->getCacheKey($key);
    }

    public function __construct(array $cacheConfig = [])
    {
        $this->prefix = $cacheConfig['cachepre'] ?? '';
        try {
            $this->yac = new \Yac($this->prefix);
        } catch (\Exception $e) {
            throw new \RuntimeException("Yac 初始化失败: " . $e->getMessage());
        }
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }



    /**
     * @param null $default
     * @return array
     */
    public function get(string $key, $default = null)
    {
        $key = $this->getCacheKey($key);
        $result = $this->yac->get($key);
        return (false === $result) ? $default : $result;
    }

    public function set(string $key, $value, int $ttl = 0): bool
    {
        $key = $this->getCacheKey($key);
        // Yac::set 的 $ttl 单位也是秒
        $attempts = 0;
        while ($attempts < 3) {
            if ($this->yac->set($key, $value, $ttl)) {
                return true;
            }
            $attempts++;
            // 简单退避
            usleep(50000 * $attempts);
        }
        return false;
    }

    public function delete(string $key): bool
    {
        $key = $this->getCacheKey($key);
        return $this->yac->delete($key);
    }

    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $key = $this->getCacheKey($key);
        // 注意：Yac 本身没有原子 incr 操作，此处依赖 get + set → 不完全原子
        $attempts = 0;
        do {
            $current = $this->yac->get($key);
            $base = (false === $current) ? 0 : (int)$current;
            $newValue = $base + $step;
            // 如果 $ttl>0，就在写入时重置过期；否则写入时默认持久化（expire=0）
            if ($this->yac->set($key, $newValue, $ttl)) {
                return $newValue;
            }
            $attempts++;
            usleep(30000 * $attempts);
        } while ($attempts < 3);

        // 重试三次失败后，仍然返回计算值（但未能保证真正写入）
        return $newValue;
    }

    /**
     * @return void
     */
    public function lock(string $key, int $ttl = 3)
    {
        $lockKey = $this->getCacheKey('lock_' . $key);
        $attempts = 0;

        while ($attempts < 3) {
            $tokenCandidate = bin2hex(random_bytes(16));
            // 使用 add 确保原子性：仅在不存在时写入成功
            if ($this->yac->add($lockKey, $tokenCandidate, $ttl)) {
                return $tokenCandidate;
            }

            // 写入失败说明锁已存在，执行指数退避
            usleep(min(100000, 50000 * ($attempts + 1)));
            $attempts++;
        }

        return null;
    }

    /**
     * @return void
     */
    public function unlock(string $key, string $token)
    {
        $lockKey = $this->getCacheKey('lock_' . $key);
        $current = $this->yac->get($lockKey);
        if ($current === $token) {
            return $this->yac->delete($lockKey);
        }
        return false;
    }

    /**
     * @return bool
     */
    public function isLocked(string $key)
    {
        $lockKey = $this->getCacheKey('lock_' . $key);
        // 直接 get，看是不是已过期（过期后 get 返回 false）
        return $this->yac->get($lockKey) !== false;
    }

    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        $tkKey = $this->getCacheKey("tb:t:$key"); // 剩余 token
        $tsKey = $this->getCacheKey("tb:s:$key"); // 时间戳
        $lock  = $this->getCacheKey("tb:l:$key"); // 1 s 轻量锁
        $ttl   = (int)ceil($cap / $rate);
        $now   = microtime(true);

        /* 自旋锁，最多 5 次，间隔 1 ms */
        $spin = 5;
        while ($spin > 0) {
            // 抢到锁
            if ($this->yac->add($lock, 1, 1)) {
                break;
            }
            usleep(1000); // 1 ms
            --$spin;
        }

        $tokens = $this->yac->get($tkKey);
        $ts = $this->yac->get($tsKey);

        if ($tokens === false || $ts === false) {
            $tokens = $cap;
            $ts = $now;
        }

        $tokens = min($cap, $tokens + ($now - $ts) * $rate);
        if ($tokens < 1) {
            $this->yac->delete($lock); // 释放锁
            return false;
        }

        $tokens -= 1;
        $this->yac->set($tkKey, $tokens, $ttl);
        $this->yac->set($tsKey, $now, $ttl);
        $this->yac->delete($lock); // 释放锁
        return true;
    }

    public function original(string $only = '')
    {
        return $this->yac;
    }

    public function clear(): bool
    {
        return $this->yac->flush();
    }

    /**
     * @param null $default
     */
    public function getMulti(array $keys, $default = null): array
    {
        if (empty($keys)) return [];
        $fullKeys = array_map([$this, 'withPrefix'], $keys);
        $vals = $this->yac->get($fullKeys);
        $result = [];
        foreach ($keys as $idx => $k) {
            $fk = $fullKeys[$idx];
            $result[$k] = (is_array($vals) && array_key_exists($fk, $vals)) ? $vals[$fk] : $default;
        }
        return $result;
    }

    /**
     * @param null $default
     */
    public function getMultiple(array $keys, $default = null): array
    {
        return $this->getMulti($keys, $default);
    }

    public function setMulti(array $items, int $ttl = 0): bool
    {
        if (empty($items)) return true;
        $prefixedItems = [];
        foreach ($items as $k => $v) {
            $prefixedItems[$this->withPrefix($k)] = $v;
        }
        return $this->yac->set($prefixedItems, $ttl);
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    use \Framework\Cache\Traits\CacheWithLockTrait;
}
