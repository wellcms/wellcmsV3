<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 升级系统异常
 *
 * 用于封装升级全流程中的各类核心错误，由中间件统一接管渲染。
 */
class UpgradeException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    /** @var string 错误发生后建议跳转的地址 */
    protected $redirectUrl = '';

    /**
     * @param string $message 错误消息
     * @param int $code 业务错误码
     * @param string $redirectUrl 跳转地址（供中间件渲染错误页面时使用）
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct(string $message = '', int $code = 0, string $redirectUrl = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->redirectUrl = $redirectUrl;
    }

    /**
     * 获取推荐的 HTTP 状态码
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return 500;
    }

    /**
     * 获取建议跳转地址
     *
     * @return string
     */
    public function getRedirectUrl(): string
    {
        return $this->redirectUrl;
    }
}
