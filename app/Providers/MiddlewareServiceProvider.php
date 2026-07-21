<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class MiddlewareServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    /** 中间件队列配置
     * @var array
    */
    private $queue;
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * 注册中间件工厂与队列
     */
    public function register(\Framework\Core\Container $container): void
    {
        try {
            // 1. MiddlewareFactory 单例注册
            $container->set(\Framework\Http\Middleware\MiddlewareFactory::class, new \Framework\Http\Middleware\MiddlewareFactory($container));
            /* $container->bind(MiddlewareFactory::class, function ($container) {
                return new MiddlewareFactory($container);
            }); */

            // 2. 将队列注入 Container 以供 Kernel::run() 使用
            $container->bind('Middleware.queue', function () {
                return $this->queue; // 返回中间件队列数组
            });
        } catch (\Throwable $e) {
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (\defined('DEBUG') && \DEBUG >= 2) throw $e;
        }
    }

    /**
     * 启动后挂载钩子：插件可以动态注入中间件
     */
    public function boot(\Framework\Core\Container $container): void {}
}