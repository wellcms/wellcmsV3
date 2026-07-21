<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};

/**
 * PSR‑15 请求处理器接口
 *
 * 任何最终处理请求并生成响应的组件，
 * 应实现此接口。
 *
 */
interface RequestHandlerInterface
{
    /**
     * 处理请求并返回响应。
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
