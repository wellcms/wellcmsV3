<?php

declare(strict_types=1);

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Http\Interfaces\ResponseInterface;

/**
 * WellCMS 3.0 核心内核接口
 */
interface KernelInterface
{
    /**
     * 处理请求并返回响应
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
