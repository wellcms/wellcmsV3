<?php

declare(strict_types=1);

namespace Framework\Exception;

/**
 * HTTP 协议相关异常基类
 */
class HttpException extends \RuntimeException implements \Framework\Exception\ExceptionInterface
{
    /** @var int */
    protected $statusCode;

    public function __construct(int $statusCode, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function notFound(string $uri): self
    {
        return new self(404, "No route found for URI: {$uri}");
    }
}
