<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Core;

use Framework\Core\Container;
use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};

/**
 * WellCMS 3.0 请求处理内核
 *
 * 实现了 KernelInterface，支持 FPM 静态调用与 Swoole 对象化调用。
 */
class Kernel implements \Framework\Http\Interfaces\KernelInterface
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * 处理请求并获取响应 (支持对象化调用)
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // 1. 从 container 中读取中间件队列
            $rawQueue = $this->container->get('Middleware.queue') ?: [];

            // 2. 使用 MiddlewareFactory 延迟实例化
            $factory = $this->container->get(\Framework\Http\Middleware\MiddlewareFactory::class);

            $middlewareInstances = [];
            foreach ($rawQueue as $key => $item) {
                // $item 可能是 "ClassName" 或 [ClassName, params]
                if (is_array($item)) {
                    $middlewareInstances[$key] = $factory->create($item[0], $item[1]);
                } else {
                    $middlewareInstances[$key] = $factory->create($item);
                }
            }

            // 3. 构建并执行管道
            $pipeline = new \Framework\Http\Middleware\Pipeline($middlewareInstances, new class implements \Framework\Http\Interfaces\RequestHandlerInterface {
                public function handle(ServerRequestInterface $req): ResponseInterface
                {
                    throw new \RuntimeException('No route matched. Ensure RouterMiddleware is placed before Kernel terminates the pipeline.');
                }
            });

            // 4. 执行并返回响应
            return $pipeline->handle($request);
        } finally {
            // 清理现场
            \Framework\Database\Collector\QueryCollector::clear();
        }
    }

    /**
     * 运行请求处理流程 (兼容 FPM 静态调用)
     *
     * @param Container $container
     * @param ServerRequestInterface|null $request
     * @return void
     */
    public static function run(Container $container, ?ServerRequestInterface $request = null): void
    {
        // 1. 构造 PSR-7 请求，如果未传入
        if ($request === null) {
            $request = \Framework\Http\Psr7\Factories\ServerRequestFactory::getInstance()->createFromGlobals();
        }

        // 2. 实例化内核并处理请求
        $kernel = new self($container);
        $response = $kernel->handle($request);

        // 3. 发送响应
        $sender = $container->has(\Framework\Http\Psr7\ResponseSender::class)
            ? $container->get(\Framework\Http\Psr7\ResponseSender::class)
            : new \Framework\Http\Psr7\ResponseSender();

        $sender->send($response);
    }
}
