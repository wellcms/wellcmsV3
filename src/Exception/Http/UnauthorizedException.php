<?php

declare(strict_types=1);

namespace Framework\Exception\Http;

/**
 * 401 Unauthorized
 */
class UnauthorizedException extends \Framework\Exception\HttpException
{
    public function __construct(string $message = "Unauthorized", ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, 0, $previous);
    }
}
