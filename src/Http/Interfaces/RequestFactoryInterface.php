<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{RequestInterface, UriInterface};

/**
 * Interface RequestFactoryInterface
 *
 * PSR‑17 请求工厂接口
 */
interface RequestFactoryInterface
{
    /**
     * 创建一个新的 Request
     *
     * @param string                    $method HTTP 方法
     * @param UriInterface|string       $uri    URI 对象或字符串
     * @return RequestInterface
     */
    public function createRequest(string $method, $uri): RequestInterface;
}
