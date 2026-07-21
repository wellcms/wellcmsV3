<?php

declare(strict_types=1);

namespace Framework\Exception;

/**
 * 验证异常
 * 用于表单或参数校验失败，默认返回 400 Bad Request
 */
class ValidationException extends \Framework\Exception\BusinessException
{
    /** @var array */
    protected $errors = [];

    public function __construct(string $message = "Validation failed", array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 400, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return 400;
    }
}
