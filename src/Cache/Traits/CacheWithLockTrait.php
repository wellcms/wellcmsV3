<?php

declare(strict_types=1);

namespace Framework\Cache\Traits;

/**
 * Trait CacheWithLockTrait
 * 
 * 为所有缓存驱动及 CacheManager 提供统一的基础“带锁查询”实现。
 * 必须保证类已实现 get, set, lock, unlock, isLocked 接口。
 */
trait CacheWithLockTrait
{
    /**
     * 带锁缓存获取通用实现
     */
    public function cacheWithLock(
        string $key,
        string $lockKey,
        callable $cacheGetter,
        int $maxAttempts = 5,
        int $cacheTtl = 0,
        int $lockTtl = 3
    ) {
        // 1. 先尝试直接从缓存里拿
        $data = $this->get($key);
        if ($data !== null) {
            return $data;
        }

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            try {
                // 2. 如果锁还在，则等待（引入指数退避与随机抖动）
                if ($this->isLocked($lockKey)) {
                    $baseDelay = 50000; // 50ms
                    $stepDelay = min(200000, $baseDelay * (2 ** $attempt));
                    $jitter = random_int(1, 10) * 5000; // 5-50ms 抖动
                    usleep($stepDelay + $jitter);
                    $attempt++;
                    continue;
                }

                // 3. 尝试加锁
                $token = $this->lock($lockKey, $lockTtl);
                if (!$token) {
                    $baseDelay = 40000;
                    $stepDelay = min(150000, $baseDelay * (2 ** $attempt));
                    $jitter = random_int(1, 10) * 5000;
                    usleep($stepDelay + $jitter);
                    $attempt++;
                    continue;
                }

                try {
                    // “双查”：加锁后再查看一次缓存
                    $data = $this->get($key);
                    if ($data !== null) {
                        return $data;
                    }

                    // 4. 真正去执行业务拿数据
                    $data = call_user_func($cacheGetter);
                    if ($data !== null) {
                        $this->set($key, $data, $cacheTtl);
                    }
                    return $data;
                } finally {
                    $this->unlock($lockKey, (string)$token);
                }
            } catch (\Throwable $e) {
                if (function_exists('error_log')) {
                    error_log("CacheWithLock Error: " . $e->getMessage());
                }
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    return null;
                }
            }
        }

        return null;
    }
}
