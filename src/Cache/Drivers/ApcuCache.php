<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Drivers;

class ApcuCache implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var string */
    private $prefix;

    public function __construct(array $cacheConfig = [])
    {
        $this->prefix = $cacheConfig['cachepre'] ?? '';
    }

    private function getCacheKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    public function withPrefix(string $key): string
    {
        return $this->getCacheKey($this->prefix . $key);
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
        $fullKey = $this->getCacheKey($this->prefix . $key);
        $ok = false;
        $val = apcu_fetch($fullKey, $ok);
        return $ok ? $val : $default;
    }

    public function set(string $key, $value, int $ttl = 0): bool
    {
        $fullKey = $this->getCacheKey($this->prefix . $key);
        return apcu_store($fullKey, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->getCacheKey($this->prefix . $key);
        return apcu_delete($fullKey);
    }

    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $fullKey = $this->getCacheKey($this->prefix . $key);
        // 如果 key 不存在，则直接存储 step，并附带 TTL（如果有的话）
        if (!apcu_exists($fullKey)) {
            apcu_store($fullKey, $step, $ttl);
            return $step;
        }

        // key 存在时，先原子 incr 再根据 $ttl 决定是否重写以更新时间戳
        $newVal = apcu_inc($fullKey, $step);

        if ($ttl > 0) {
            // 因为 apcu_inc() 不会更新已有 TTL，所以我们此处主动覆盖一次
            apcu_store($fullKey, $newVal, $ttl);
        }
        return $newVal;
    }

    /**
     * @return void
     */
    public function lock(string $key, int $ttl = 3)
    {
        $fullLock = $this->getCacheKey($this->prefix . 'lock_' . $key);
        $attempts = 0;

        while ($attempts < 3) {
            $token = bin2hex(random_bytes(16));
            $expireAt = time() + $ttl;
            $info = ['token' => $token, 'expire' => $expireAt];

            // apcu_add 仅在不存在时存储成功
            $ok = apcu_add($fullLock, $info, $ttl);
            if ($ok) {
                return $token;
            }

            // 存在旧锁时，检查过期
            $existing = apcu_fetch($fullLock);
            if (is_array($existing) && isset($existing['expire']) && time() > $existing['expire']) {
                apcu_delete($fullLock);
                // 旧锁已过期，立刻重试一次
                continue;
            }

            // 指数退避 + 随机抖动
            $backoff = min(100000, 30000 * min($attempts, 3));
            usleep($backoff);
            $attempts++;
        }

        return null;
    }

    /**
     * @return void
     */
    public function unlock(string $key, string $token)
    {
        $fullLock = $this->getCacheKey($this->prefix . 'lock_' . $key);
        $existing = apcu_fetch($fullLock);
        if (is_array($existing) && isset($existing['token']) && $existing['token'] === $token) {
            return apcu_delete($fullLock);
        }
        return false;
    }

    /**
     * @return bool
     */
    public function isLocked(string $key){
        $fullLock = $this->getCacheKey($this->prefix . 'lock_' . $key);
        $existing = apcu_fetch($fullLock);
        if (!is_array($existing) || !isset($existing['expire'])) {
            return false;
        }

        // 如果已过期，删掉旧锁并返回 false
        if (time() > $existing['expire']) {
            apcu_delete($fullLock);
            return false;
        }
        return true;
    }

    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        $store = $this->getCacheKey("tb:$key");
        $lock  = $this->getCacheKey("tb:l:$key");
        $ttl   = (int)ceil($cap / $rate);
        $now   = microtime(true);

        /* 自旋锁，最多 5 次，间隔 1 ms */
        $spin = 5;
        while ($spin-- > 0) {
            if (apcu_add($lock, 1, 1)) {
                $hit = false;
                $old = apcu_fetch($store, $hit);

                if (!$hit) {
                    $tokens = $cap - 1;
                    $val = $tokens . '|' . $now;
                    apcu_store($store, $val, $ttl);
                    apcu_delete($lock);
                    return true;
                }

                [$tokens, $ts] = array_map('floatval', explode('|', $old, 2));
                $tokens = min((float)$cap, $tokens + ($now - $ts) * $rate);

                if ($tokens < 1) {
                    apcu_delete($lock);
                    return false;
                }

                $tokens -= 1;
                $newVal = $tokens . '|' . $now;
                apcu_store($store, $newVal, $ttl);
                apcu_delete($lock);
                return true;
            }
            usleep(1000);
        }

        return false;
    }

    public function original(string $only = '')
    {
        return null;
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    /**
     * @param null $default
     */
    public function getMulti(array $keys, $default = null): array
    {
        if (empty($keys)) return [];
        $fullKeys = array_map([$this, 'withPrefix'], $keys);
        /** @var array|false $vals */
        $vals = apcu_fetch($fullKeys);
        $result = [];
        if ($vals === false) {
            foreach ($keys as $k) $result[$k] = $default;
            return $result;
        }
        foreach ($keys as $idx => $k) {
            $fk = $fullKeys[$idx];
            $result[$k] = array_key_exists($fk, $vals) ? $vals[$fk] : $default;
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
        return empty(apcu_store($prefixedItems, null, $ttl));
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    use \Framework\Cache\Traits\CacheWithLockTrait;
}
