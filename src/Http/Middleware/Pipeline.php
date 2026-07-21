<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Middleware;

use Framework\Http\Interfaces\ResponseInterface;

/**
 * PSR‑15 中间件管道
 */
class Pipeline implements \Framework\Http\Interfaces\RequestHandlerInterface
{
    /**
     * @var array
     */
    private $middlewares = [];

    /**
     * @var \Framework\Http\Interfaces\RequestHandlerInterface
     */
    private $lastHandler;

    public function __construct(array $middlewares, \Framework\Http\Interfaces\RequestHandlerInterface $lastHandler)
    {
        $this->middlewares = $middlewares;
        $this->lastHandler = $lastHandler;
    }

    public function handle(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 克隆队列以支持并发复用
        $queue = $this->middlewares;
        return $this->processNext($request, $queue);
    }

    private function processNext(\Framework\Http\Interfaces\ServerRequestInterface $request, array $queue): ResponseInterface
    {
        if (empty($queue)) {
            return $this->lastHandler->handle($request);
        }
        $middleware = array_shift($queue);
        // 将剩余队列包装为新的 Pipeline 传给下一个中间件
        $next = new self($queue, $this->lastHandler);
        return $middleware->process($request, $next);
    }
}
