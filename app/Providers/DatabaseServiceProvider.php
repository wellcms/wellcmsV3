<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

use Framework\Core\Container;
use Framework\Database\ProxyDriver;
use Framework\Database\Driver\PdoDriver;
use Framework\Database\Interfaces\ConnectionFactoryInterface;
use Framework\Database\Pool\{FpmConnectionPool, CoroutineConnectionPool};
use Framework\Database\Pool\LoadBalancer\LoadBalancerInterface;

class DatabaseServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $dbConfig = $container->get('dbConfig');
        $poolCfg = $dbConfig['pool'] ?? [];
        $isCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
        $poolEnabled = $isCoroutine
            ? ($poolCfg['coroutine']['enabled'] ?? true)
            : ($poolCfg['fpm']['enabled'] ?? true);

        if ($poolEnabled) {
            // 从配置中读取负载均衡策略，而非强制写死
            $lbClass = $isCoroutine
                ? ($poolCfg['coroutine']['load_balancer'] ?? \Framework\Database\Pool\LoadBalancer\WeightedRandomLoadBalancer::class)
                : ($poolCfg['fpm']['load_balancer'] ?? \Framework\Database\Pool\LoadBalancer\WeightedRandomLoadBalancer::class);
            $container->set(LoadBalancerInterface::class, new $lbClass());

            // 注册代理驱动 (取消延迟加载以确保构造函数类型检查通过)
            $container->bind(ProxyDriver::class, function (Container $container) {
                $dbConfig = $container->get('dbConfig');
                if (empty($dbConfig)) {
                    throw new \Framework\Exception\Infra\SiteNotInstalledException("Database configuration is missing.");
                }

                $isCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
                $activePool = $isCoroutine
                    ? $container->get(CoroutineConnectionPool::class)
                    : $container->get(FpmConnectionPool::class);

                return new ProxyDriver($dbConfig, $activePool);
            }, true, false);

            $container->bind(\Framework\Database\Interfaces\DatabaseInterface::class, ProxyDriver::class);

            // 注册协程连接池
            $container->bind(CoroutineConnectionPool::class, function (Container $container) {
                $dbConfig = $container->get('dbConfig');
                $poolCfg = $dbConfig['pool'] ?? [];
                $coroutineCfg = $poolCfg['coroutine'] ?? $poolCfg['fpm'] ?? [];
                return new CoroutineConnectionPool(
                    $container->get(PdoDriver::class),
                    $dbConfig['master'],
                    $dbConfig['slaves'],
                    $container->get(LoadBalancerInterface::class),
                    $coroutineCfg['min_connections'] ?? 2,
                    $coroutineCfg['max_connections'] ?? 8,
                    $coroutineCfg['timeout'] ?? 5,
                    $coroutineCfg['threshold'] ?? 20,
                    $coroutineCfg['adjust_threshold'] ?? 0.75,
                    null,
                    null,
                    $coroutineCfg['health_check_interval'] ?? 60000
                );
            }, true, false);

            // 注册基础连接池
            $container->bind(FpmConnectionPool::class, function (Container $container) {
                $dbConfig = $container->get('dbConfig');
                $poolCfg = $dbConfig['pool'] ?? [];
                $fpmCfg = $poolCfg['fpm'] ?? $poolCfg['coroutine'] ?? [];
                return new FpmConnectionPool(
                    $container->get(PdoDriver::class),
                    $dbConfig['master'],
                    $dbConfig['slaves'],
                    $container->get(LoadBalancerInterface::class),
                    $fpmCfg['min_connections'] ?? 2,
                    $fpmCfg['max_connections'] ?? 8,
                    $fpmCfg['timeout'] ?? 5,
                    $fpmCfg['threshold'] ?? 20,
                    $fpmCfg['adjust_threshold'] ?? 0.75
                );
            }, true, false);
        } else {
            // 连接池禁用时直接回退到原始 PdoDriver
            $container->bind(\Framework\Database\Interfaces\DatabaseInterface::class, PdoDriver::class);
        }

        // PdoDriver 注册（连接池禁用时也必需）
        $container->bind(PdoDriver::class, function (Container $container) {
            return new PdoDriver($container->get('dbConfig'));
        }, true, false);

        // 同时注册 ConnectionFactoryInterface，连接池依赖此接口进行点对点建连
        $container->bind(ConnectionFactoryInterface::class, PdoDriver::class);

        // 注入统一日志通道（仅注入当前实际激活的池，避免误实例化 CoroutineConnectionPool 导致 Swoole Timer 泄漏）
        if ($container->has(\Framework\Logger\LoggerInterface::class)) {
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);
            $container->get(PdoDriver::class)->setLogger($logger);
            $isCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
            $poolClass = $isCoroutine ? CoroutineConnectionPool::class : FpmConnectionPool::class;
            if ($container->has($poolClass)) {
                $container->get($poolClass)->setLogger($logger);
            }
        }
    }

    public function boot(Container $container): void
    {
        $dbConfig = $container->get('dbConfig');
        if (empty($dbConfig) || empty($dbConfig['slaves'])) return;

        // 预热连接池
        $db = $container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
        if ($db instanceof ProxyDriver) {
            $db->pool->preWarm(3);
        }
    }
}
