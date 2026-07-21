<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/**
 * 连接池异常 (Pool full, connection failed, etc.)
 */
class PoolException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    public static function poolError(string $message): self
    {
        return new self($message);
    }

    public function getStatusCode(): int
    {
        return 500;
    }
}
