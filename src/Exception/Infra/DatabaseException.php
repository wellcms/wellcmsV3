<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/**
 * 数据库底层异常基类
 */
class DatabaseException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    public function getStatusCode(): int
    {
        return 500;
    }
}
