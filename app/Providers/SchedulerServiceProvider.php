<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

use Framework\Cache\Drivers\RedisCache;
use Framework\Core\Container;

class SchedulerServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // hook app_Providers_SchedulerServiceProvider_start.php

        // 注册 RedisCache（非单例，确保每次使用正确的 cachepre）
        // web 端从请求域名动态追加前缀实现多站点自动隔离
        // CLI scheduler 无请求，走 cachepre 原值或 env 前缀
        $container->bind(RedisCache::class, function (Container $container) {
            $cfg = $container->get('cacheConfig');
            $redisCfg = $cfg['stores']['redis'] ?? [];
            if (empty($redisCfg)) {
                throw new \RuntimeException("Scheduler requires 'redis' driver to be enabled in config/cache.php");
            }

            $request = \Framework\Http\Psr7\RequestStack::getCurrent();
            $host = $request ? $request->getUri()->getHost() : '';
            if ($host !== '') {
                $redisCfg['cachepre'] = ($redisCfg['cachepre'] ?? '')
                    . str_replace('.', '_', $host) . '_';
            }

            return new RedisCache($redisCfg);
        }, false, true);

        // 注册 Logger 单例（供 TaskExecutor 及 HttpResultCallback 注入）
        $container->bind(\Framework\Scheduler\Logger::class, function (Container $container) {
            return new \Framework\Scheduler\Logger();
        }, true, true);

        // 注册任务队列 (非单例，确保每次使用正确的 RedisCache)
        $container->bind(\Framework\Scheduler\Interfaces\TaskQueueInterface::class, function (Container $container) {
            return new \Framework\Scheduler\RedisTaskQueue($container->get(RedisCache::class));
        }, false, true);

        // 注册任务管理 (非单例，确保每次使用正确的 RedisCache)
        $container->bind(\Framework\Scheduler\TaskManage::class, function (Container $container) {
            return new \Framework\Scheduler\TaskManage($container->get(RedisCache::class));
        }, false, true);

        // 注册执行器 (非单例，确保每次使用正确的 RedisCache)
        // bin/scheduler 中只 get() 一次，实际行为与单例一致
        $container->bind(\Framework\Scheduler\TaskExecutor::class, function (Container $container) {
            return new \Framework\Scheduler\TaskExecutor(
                $container, // 注入容器，用于 job 实例化
                $container->get(RedisCache::class), // 注入 Redis 用于锁
                $container->get(\Framework\Scheduler\Interfaces\TaskQueueInterface::class),
                $container->get(\Framework\Scheduler\Logger::class) // 注入 Logger 单例
            );
        }, false, true);

        $container->bind(\Framework\Scheduler\Interfaces\ResultCallbackInterface::class, function ($container) {
            return new \Framework\Scheduler\HttpResultCallback();
        }, true, true);

        // 插件可以通过这个钩子注册调度器服务
        // hook app_Providers_SchedulerServiceProvider_end.php
    }

    public function boot(Container $container): void
    {
        // nothing
    }
}
