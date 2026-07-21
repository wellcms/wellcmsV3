<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/**
 * 配置文件加载或解析异常
 */
class ConfigException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    public static function missingKey(string $key): self
    {
        return new self(sprintf('Configuration key missing: %s', $key));
    }

    public function getStatusCode(): int
    {
        return 500;
    }
}
