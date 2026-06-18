<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\ResponseInterface;

/**
 * Interface ResponseFactoryInterface
 *
 * PSR‑17 响应工厂接口
 */
interface ResponseFactoryInterface
{
    /**
     * 创建一个新的 Response
     *
     * @param int    $code         HTTP 状态码
     * @param string $reasonPhrase 状态短语
     * @return ResponseInterface
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
}
