<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\MessageInterface;

/**
 * Interface ResponseInterface
 *
 * 表示客户端接收的 HTTP 响应（PSR‑7）
 */
interface ResponseInterface extends MessageInterface
{
    /** 获取状态码 */
    public function getStatusCode(): int;

    /**
     * 返回设置状态码后的副本
     *
     * @param int    $code
     * @param string $reasonPhrase
     * @return static
     */
    public function withStatus(int $code, string $reasonPhrase = ''): self;

    /** 获取状态短语 */
    public function getReasonPhrase(): string;
}
