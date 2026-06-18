<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};

/**
 * PSR‑15 中间件接口
 *
 * 中间件必须实现此接口，并在 process() 方法中
 * 处理请求或委派给下一个中间件/处理器。
 *
 */
interface MiddlewareInterface
{
    /**
     * 处理传入的请求并返回响应，
     * 或委派给下一个中间件/处理器。
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
}
