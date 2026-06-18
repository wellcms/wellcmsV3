<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/**
 * 站点未安装异常
 */
class SiteNotInstalledException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    public function __construct(string $message = "Not installed. Please run the installation first. / 未安装，请先初始化站点。", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return 503; // Service Unavailable
    }
}
