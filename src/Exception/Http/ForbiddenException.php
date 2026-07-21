<?php

declare(strict_types=1);

namespace Framework\Exception\Http;

/**
 * 403 Forbidden
 */
class ForbiddenException extends \Framework\Exception\HttpException
{
    public function __construct(string $message = "Access forbidden", ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, 0, $previous);
    }
}
