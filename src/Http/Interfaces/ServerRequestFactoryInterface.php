<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{ServerRequestInterface, UriInterface};

/**
 * Interface ServerRequestFactoryInterface
 *
 * PSR‑17 服务端请求工厂接口
 */
interface ServerRequestFactoryInterface
{
    /**
     * 创建一个新的 ServerRequest
     *
     * @param string              $method       HTTP 方法
     * @param UriInterface|string $uri          URI 对象或字符串
     * @param array               $serverParams $_SERVER 数据
     * @return ServerRequestInterface
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface;

    /** 
     * 从全局环境构建完整的 ServerRequest 
     */
    public function createFromGlobals(): ServerRequestInterface;
}
