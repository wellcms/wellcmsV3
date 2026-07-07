<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * 熔断器
 *
 * 基于 Redis 统计窗口 + 状态切换。
 * PHP 7.2 兼容。
 */
class CircuitBreaker
{
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    /** @var \Framework\Cache\Drivers\RedisCache */
    private $redis;

    /** @var int */
    private $failureThreshold = 10;

    /** @var int */
    private $windowSeconds = 300;

    /** @var int */
    private $openSeconds = 300;

    /** @var int */
    private $halfOpenTtl = 60;

    public function __construct(\Framework\Cache\Drivers\RedisCache $redis)
    {
        $this->redis = $redis;
    }

    /**
     * 由 ServiceProvider 在注册时调用
     *
     * @param int $failureThreshold
     * @param int $windowSeconds
     * @param int $openSeconds
     * @param int $halfOpenTtl
     */
    public function configure(
        int $failureThreshold,
        int $windowSeconds,
        int $openSeconds,
        int $halfOpenTtl
    ): void {
        $this->failureThreshold = $failureThreshold;
        $this->windowSeconds = $windowSeconds;
        $this->openSeconds = $openSeconds;
        $this->halfOpenTtl = $halfOpenTtl;
    }

    /**
     * @param string $jobClass
     * @return bool
     */
    public function isOpen(string $jobClass): bool
    {
        $key = $this->stateKey($jobClass);
        $state = $this->redis->get($key);
        if ($state === false || $state === null) {
            return false;
        }
        if ((string)$state === self::STATE_OPEN) {
            $ttl = $this->redis->ttl($key);
            if ($ttl <= 0) {
                $this->redis->setex($key, $this->halfOpenTtl, self::STATE_HALF_OPEN);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * @param string $jobClass
     * @return string
     */
    public function recordFailure(string $jobClass): string
    {
        $key = $this->stateKey($jobClass);
        $state = $this->redis->get($key);

        if ($state !== false && $state !== null && (string)$state === self::STATE_OPEN) {
            return self::STATE_OPEN;
        }
        if ($state !== false && $state !== null && (string)$state === self::STATE_HALF_OPEN) {
            $this->redis->setex($key, $this->openSeconds, self::STATE_OPEN);
            return self::STATE_OPEN;
        }

        $windowKey = $this->windowKey($jobClass);
        $failures = $this->redis->incr($windowKey);
        if ($failures === 1) {
            $this->redis->expire($windowKey, $this->windowSeconds);
        }
        if ($failures >= $this->failureThreshold) {
            $this->redis->setex($key, $this->openSeconds, self::STATE_OPEN);
            return self::STATE_OPEN;
        }
        return self::STATE_CLOSED;
    }

    /**
     * @param string $jobClass
     */
    public function recordSuccess(string $jobClass): void
    {
        $key = $this->stateKey($jobClass);
        $state = $this->redis->get($key);
        if ($state !== false && $state !== null && (string)$state === self::STATE_HALF_OPEN) {
            $this->redis->del($key);
        }
        $this->redis->del($this->windowKey($jobClass));
    }

    /**
     * @param string $jobClass
     * @return int
     */
    public function getFailureCount(string $jobClass): int
    {
        $val = $this->redis->get($this->windowKey($jobClass));
        return $val !== false && $val !== null ? (int)$val : 0;
    }

    /**
     * @param string $jobClass
     * @return string
     */
    private function stateKey(string $jobClass): string
    {
        return 'scheduler:circuit:state:' . strtr($jobClass, '\\', ':');
    }

    /**
     * @param string $jobClass
     * @return string
     */
    private function windowKey(string $jobClass): string
    {
        return 'scheduler:circuit:window:' . strtr($jobClass, '\\', ':');
    }
}
