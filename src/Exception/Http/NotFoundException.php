<?php

declare(strict_types=1);

namespace Framework\Exception\Http;

/**
 * 404 Not Found
 */
class NotFoundException extends \Framework\Exception\HttpException
{
    public function __construct(string $message = "Resource not found", ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 0, $previous);
    }
}
