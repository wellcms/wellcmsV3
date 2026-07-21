<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Services\Market;

use App\Services\System\KeyValueService;

/**
 * 熔断器（降级机制）
 * 职责：服务端故障时自动熔断，使用本地缓存降级
 * 遵循 Skill #16: 非主键聚合统计冗余化
 */
class MarketCircuitBreaker
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var KeyValueService */
    protected $kv;
    /** @var string 熔断器名称 */
    protected $name;
    /** @var int 失败阈值 */
    protected $failureThreshold;
    /** @var int 熔断持续时间（秒） */
    protected $timeout;
    /** @var int 半开状态测试请求数 */
    protected $halfOpenMaxCalls;

    // 状态常量
    public const STATE_CLOSED = 'closed';       // 正常
    public const STATE_OPEN = 'open';           // 熔断
    public const STATE_HALF_OPEN = 'half_open'; // 半开

    public function __construct(
        KeyValueService $kv,
        string $name = 'market',
        int $failureThreshold = 5,
        int $timeout = 60,
        int $halfOpenMaxCalls = 3
    ) {
        // hook app_Services_Market_MarketCircuitBreaker_construct_start.php
        $this->kv = $kv;
        $this->name = $name;
        $this->failureThreshold = $failureThreshold;
        $this->timeout = $timeout;
        $this->halfOpenMaxCalls = $halfOpenMaxCalls;
        // hook app_Services_Market_MarketCircuitBreaker_construct_end.php
    }

    /**
     * 获取当前状态
     *
     * @return string
     */
    public function getState(): string
    {
        $state = $this->kv->get("circuit_breaker:{$this->name}:state");

        if ($state === null) {
            return self::STATE_CLOSED;
        }

        // 检查熔断是否过期
        if ($state === self::STATE_OPEN) {
            $lastFailure = (int)$this->kv->get("circuit_breaker:{$this->name}:last_failure");
            if (time() - $lastFailure >= $this->timeout) {
                // 转为半开状态
                $this->kv->set("circuit_breaker:{$this->name}:state", self::STATE_HALF_OPEN);
                $this->kv->set("circuit_breaker:{$this->name}:half_open_calls", 0);
                return self::STATE_HALF_OPEN;
            }
        }

        return (string)$state;
    }

    /**
     * 记录成功
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $calls = (int)$this->kv->get("circuit_breaker:{$this->name}:half_open_calls") + 1;

            if ($calls >= $this->halfOpenMaxCalls) {
                // 恢复关闭状态
                $this->reset();
            } else {
                $this->kv->set("circuit_breaker:{$this->name}:half_open_calls", $calls);
            }
        } else {
            // 正常状态，重置失败计数
            $this->kv->delete("circuit_breaker:{$this->name}:failures");
        }
    }

    /**
     * 记录失败
     *
     * @return void
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            // 半开状态失败，重新熔断
            $this->trip();
            return;
        }

        // 增加失败计数
        $failures = (int)$this->kv->get("circuit_breaker:{$this->name}:failures") + 1;
        $this->kv->set("circuit_breaker:{$this->name}:failures", $failures);
        $this->kv->set("circuit_breaker:{$this->name}:last_failure", time());

        // 达到阈值，熔断
        if ($failures >= $this->failureThreshold) {
            $this->trip();
        }
    }

    /**
     * 熔断
     *
     * @return void
     */
    public function trip(): void
    {
        $this->kv->set("circuit_breaker:{$this->name}:state", self::STATE_OPEN);
        $this->kv->set("circuit_breaker:{$this->name}:last_failure", time());
    }

    /**
     * 重置
     *
     * @return void
     */
    public function reset(): void
    {
        $this->kv->delete("circuit_breaker:{$this->name}:state");
        $this->kv->delete("circuit_breaker:{$this->name}:failures");
        $this->kv->delete("circuit_breaker:{$this->name}:last_failure");
        $this->kv->delete("circuit_breaker:{$this->name}:half_open_calls");
    }

    /**
     * 是否允许请求
     *
     * @return bool
     */
    public function canRequest(): bool
    {
        $state = $this->getState();
        return $state !== self::STATE_OPEN;
    }

    /**
     * 是否处于降级模式
     *
     * @return bool
     */
    public function isDegraded(): bool
    {
        return $this->getState() !== self::STATE_CLOSED;
    }

    /**
     * 获取熔断器统计信息
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'state' => $this->getState(),
            'can_request' => $this->canRequest(),
            'failures' => (int)$this->kv->get("circuit_breaker:{$this->name}:failures"),
            'last_failure' => (int)$this->kv->get("circuit_breaker:{$this->name}:last_failure"),
        ];
    }
}
