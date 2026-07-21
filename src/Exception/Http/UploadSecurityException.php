<?php

declare(strict_types=1);

namespace Framework\Exception\Http;

/**
 * 上传安全校验失败异常 (400)
 */
class UploadSecurityException extends \Framework\Exception\HttpException
{
    public function __construct(string $message = 'File upload validation failed', ?\Throwable $previous = null, int $code = 0)
    {
        parent::__construct(400, $message, $code, $previous);
    }
}
