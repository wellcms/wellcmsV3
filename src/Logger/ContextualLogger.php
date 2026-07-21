<?php

declare(strict_types=1);

namespace Framework\Logger;

use Framework\Utils\LoggerContext;

class ContextualLogger implements LoggerInterface
{
    /** @var LoggerInterface */
    private $inner;

    public function __construct(LoggerInterface $inner)
    {
        $this->inner = $inner;
    }

    /**
     * 自动合并 LoggerContext 数据到每条日志的 context 中。
     * 注意：调用方显式传入的 context 键优先级高于 LoggerContext 的自动上下文，
     * 确保调用方可以覆盖（如特定日志使用不同的 request_id）。
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->inner->log($level, $message, array_merge(LoggerContext::all(), $context));
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
