<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Services\Market;

/**
 * 重试策略配置
 * 职责：管理 API 请求的指数退避重试策略
 */
class RetryPolicy
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var int 最大重试次数 */
    protected $maxRetries;
    /** @var int 初始延迟（毫秒） */
    protected $initialDelay;
    /** @var float 退避倍数 */
    protected $backoffMultiplier;
    /** @var int 最大延迟（毫秒） */
    protected $maxDelay;
    /** @var array 需要重试的错误码 */
    protected $retryableCodes;

    public function __construct(
        int $maxRetries = 3,
        int $initialDelay = 1000,
        float $backoffMultiplier = 2.0,
        int $maxDelay = 30000,
        array $retryableCodes = [429, 423, 500, 502, 503, 504]
    ) {
        $this->maxRetries = $maxRetries;
        $this->initialDelay = $initialDelay;
        $this->backoffMultiplier = $backoffMultiplier;
        $this->maxDelay = $maxDelay;
        $this->retryableCodes = $retryableCodes;
    }

    /**
     * 计算下次重试延迟
     * 
     * @param int $attempt 当前尝试次数（从1开始）
     * @return int 延迟时间（毫秒）
     */
    public function getDelay(int $attempt): int
    {
        $delay = $this->initialDelay * pow($this->backoffMultiplier, $attempt - 1);
        return (int)min($delay, $this->maxDelay);
    }

    /**
     * 判断是否需要重试
     * 
     * @param int $attempt 当前尝试次数
     * @param int|null $errorCode 错误码
     * @param \Exception|null $exception 异常
     * @return bool
     */
    public function shouldRetry(int $attempt, ?int $errorCode = null, ?\Exception $exception = null): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        // 网络异常一律重试
        if ($exception instanceof \Framework\Utils\HttpClientNetworkException) {
            return true;
        }

        // 根据错误码判断
        if ($errorCode !== null && in_array($errorCode, $this->retryableCodes, true)) {
            return true;
        }

        return false;
    }

    /**
     * 获取最大重试次数
     * 
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * 获取默认策略
     * 
     * @return self
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * 获取激进策略（更多重试、更长延迟）
     * 
     * @return self
     */
    public static function aggressive(): self
    {
        return new self(5, 500, 2.0, 60000);
    }

    /**
     * 获取保守策略（较少重试）
     * 
     * @return self
     */
    public static function conservative(): self
    {
        return new self(2, 2000, 2.0, 10000);
    }
}
