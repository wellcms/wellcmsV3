<?php

declare(strict_types=1);

namespace Framework\Exception;

/**
 * 业务逻辑异常基类
 * 默认返回 422 Unprocessable Entity
 */
class BusinessException extends \Exception implements \Framework\Exception\ExceptionInterface
{
    public function __construct(string $message = "Business logic error", int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: 422;
    }
}
