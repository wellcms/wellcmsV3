<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

use Framework\Cache\Drivers\RedisCache;
use Framework\Core\Container;
use Framework\Scheduler\EventBus;
use Framework\Scheduler\CircuitBreaker;
use Framework\Scheduler\WorkerCoordinator;
use Framework\Scheduler\Queue\PersistenceQueue;
use Framework\Scheduler\Queue\CircuitBreakerQueue;
use Framework\Scheduler\RecoveryEngine;

class SchedulerServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // hook app_Providers_SchedulerServiceProvider_start.php

        // 加载 Scheduler 工业级配置（v2 服务注册前必须可用）
        $config = [];
        try {
            $config = $container->get('schedulerConfig') ?: [];
        } catch (\Throwable $e) {
            // 无配置则跳过 v2 服务
        }

        // 注册 RedisCache（非单例，确保每次使用正确的 cachepre）
        // cachepre 直接取自 config/Cache.php，CLI/Swoole/FPM 统一。
        // 多站点隔离通过环境变量 WELLCMS_SITE_ID 配置。
        $container->bind(RedisCache::class, function (Container $container) {
            $cfg = $container->get('cacheConfig');
            $redisCfg = $cfg['stores']['redis'] ?? [];
            if (empty($redisCfg)) {
                throw new \RuntimeException("Scheduler requires 'redis' driver to be enabled in config/cache.php");
            }

            return new RedisCache($redisCfg);
        }, false, true);

        // 注册 Logger 单例（供 TaskExecutor 及 HttpResultCallback 注入）
        $container->bind(\Framework\Scheduler\Logger::class, function (Container $container) {
            return new \Framework\Scheduler\Logger();
        }, true, true);

        // 注册任务队列 (非单例，确保每次使用正确的 RedisCache)
        $container->bind(\Framework\Scheduler\Interfaces\TaskQueueInterface::class, function (Container $container) use ($config) {
            $redis = $container->get(RedisCache::class);
            $queue = new \Framework\Scheduler\RedisTaskQueue($redis);

            // v2 未启用时直接返回原始 RedisQueue
            if (empty($config['v2_enabled'])) {
                return $queue;
            }

            // 构建事件总线（可选）
            $eventBus = null;
            if (!empty($config['event_bus']['enabled'])) {
                $eventBus = $container->get(EventBus::class);
            }

            // 第 1 层: PersistenceQueue（MySQL 持久化，可选）
            // P0 #1: 传入 RedisCache 用于直接操作哈希索引
            // v3.2: DatabaseQueue → TaskStorageInterface
            if (!empty($config['dual_write']['enabled'])) {
                $storage = $container->get(\Framework\Scheduler\Interfaces\TaskStorageInterface::class);
                $queue = new PersistenceQueue($queue, $storage, $redis, $eventBus);
            }

            // 第 2 层: CircuitBreakerQueue（熔断保护，可选，最外层）
            if (!empty($config['circuit_breaker']['enabled'])) {
                $breaker = $container->get(CircuitBreaker::class);
                $queue = new CircuitBreakerQueue($queue, $breaker, $eventBus);
            }

            return $queue;
        }, false, true);

        // 注册任务管理 (非单例，确保每次使用正确的 RedisCache)
        $container->bind(\Framework\Scheduler\TaskManage::class, function (Container $container) use ($config) {
            \Framework\Scheduler\TaskManage::setSchedulerConfig($config);
            $taskManage = new \Framework\Scheduler\TaskManage($container->get(RedisCache::class));
            try {
                $queue = $container->get(\Framework\Scheduler\Interfaces\TaskQueueInterface::class);
                $taskManage->setQueue($queue);
            } catch (\Throwable $e) {
                // PersistenceQueue 构建失败时降级为纯 Redis 队列。
                // 此时 createTask 不写入 MySQL，重启后该任务不可恢复。
                // 打印错误到 STDERR 以便 Supervisor 捕获，同时 error_log 记录。
                $msg = sprintf(
                    '[Scheduler] PersistenceQueue unavailable, degraded to Redis-only. '
                    . 'Tasks created in this session will NOT be persisted to MySQL: %s',
                    $e->getMessage()
                );
                fwrite(STDERR, $msg . PHP_EOL);
                error_log($msg);
            }

            // v3.4: 注入 TaskStorageInterface（cancelTasksByClass 用）
            if (!empty($config['v2_enabled'])) {
                try {
                    $storage = $container->get(\Framework\Scheduler\Interfaces\TaskStorageInterface::class);
                    $taskManage->setTaskStorage($storage);
                } catch (\Throwable $e) {
                    // v2 储存层未注册时静默降级（不影响核心流程）
                }
            }

            return $taskManage;
        }, false, true);

        // 注册执行器 (非单例，确保每次使用正确的 RedisCache)
        // bin/scheduler 中只 get() 一次，实际行为与单例一致
        $container->bind(\Framework\Scheduler\TaskExecutor::class, function (Container $container) use ($config) {
            $coordinator = null;
            try {
                $coordinator = $container->get(WorkerCoordinator::class);
            } catch (\Throwable $e) {
                // v2 未启用或配置关闭时允许缺失
            }

            $eventBus = null;
            try {
                $eventBus = $container->get(EventBus::class);
            } catch (\Throwable $e) {
                // v2 未启用或配置关闭时允许缺失
            }

            $recoveryEngine = null;
            try {
                $recoveryEngine = $container->get(RecoveryEngine::class);
            } catch (\Throwable $e) {
                // v2 未启用或配置关闭时允许缺失
            }

            return new \Framework\Scheduler\TaskExecutor(
                $container, // 注入容器，用于 job 实例化
                $container->get(RedisCache::class), // 注入 Redis 用于锁
                $container->get(\Framework\Scheduler\Interfaces\TaskQueueInterface::class),
                $container->get(\Framework\Scheduler\Logger::class), // 注入 Logger 单例
                $eventBus, // C-3: 可观测性事件总线
                $coordinator, // P0 #3: Worker 协调器心跳
                $recoveryEngine // P1 #11 / v3.2: FPM 模式僵尸检测
            );
        }, false, true);

        $container->bind(\Framework\Scheduler\Interfaces\ResultCallbackInterface::class, function ($container) {
            return new \Framework\Scheduler\HttpResultCallback();
        }, true, true);

        // CallbackJob: 从容器注入 HttpResultCallback，避免 new 硬编码
        $container->bind(\Framework\Scheduler\Jobs\CallbackJob::class, function ($container) {
            $httpCallback = null;
            try { $httpCallback = $container->get(\Framework\Scheduler\Interfaces\ResultCallbackInterface::class); } catch (\Throwable $e) {}
            return new \Framework\Scheduler\Jobs\CallbackJob(null, $httpCallback);
        }, false, false);

        // ── 新增: v2 工业级服务注册（仅在配置启用时激活）──
        if (!empty($config['v2_enabled'])) {
            $this->registerV2Services($container, $config);
        }

        // 插件可以通过这个钩子注册调度器服务
        // hook app_Providers_SchedulerServiceProvider_end.php
    }

    /**
     * 注册 v2 工业级服务
     *
     * @param Container $container
     * @param array     $config
     */
    private function registerV2Services(Container $container, array $config): void
    {
        // ── EventBus 新增 Logger ──
        $container->bind(EventBus::class, function (Container $c) {
            $logger = null;
            try { $logger = $c->get(\Framework\Scheduler\Logger::class); } catch (\Throwable $e) {}
            return new EventBus($c->get(RedisCache::class), $logger);
        }, true);

        // ── CircuitBreaker 不变 ──
        $container->bind(CircuitBreaker::class, function (Container $c) use ($config) {
            $cb = new CircuitBreaker($c->get(RedisCache::class));
            $cbCfg = $config['circuit_breaker'] ?? [];
            $cb->configure(
                (int)($cbCfg['failure_threshold'] ?? 10),
                (int)($cbCfg['window_seconds'] ?? 300),
                (int)($cbCfg['open_seconds'] ?? 300),
                (int)($cbCfg['half_open_ttl'] ?? 60)
            );
            return $cb;
        }, true);

        // ── WorkerCoordinator 精简版（保持 Redis 心跳） ──
        $container->bind(WorkerCoordinator::class, function (Container $c) use ($config) {
            $wc = new WorkerCoordinator($c->get(RedisCache::class));
            $wcCfg = $config['worker_coordinator'] ?? [];
            $wc->configure(
                (int)($wcCfg['heartbeat_ttl'] ?? 30)
                // zombie_threshold 不再传入 WorkerCoordinator，由 ZombieHandler 管理
            );
            return $wc;
        });

        // ── v3.2 新增: 绑定 TaskStorageInterface 到 DatabaseTaskStorage ──
        // v3.3: 注入 SchedulerTaskModel 替代 ProxyDriver
        $container->bind(
            \Framework\Scheduler\Interfaces\TaskStorageInterface::class,
            function (Container $c) {
                $logger = null;
                try { $logger = $c->get(\Framework\Scheduler\Logger::class); } catch (\Throwable $e) {}
                return new \App\Services\Scheduler\Storage\DatabaseTaskStorage(
                    $c->get(\App\Models\SchedulerTaskModel::class),
                    $logger
                );
            }
        );

        // ── v3.2 新增: 绑定 ZombieDetectorInterface 到 ZombieHandler ──
        // v3.3: 注入 SchedulerTaskModel 替代 ProxyDriver
        $container->bind(
            \Framework\Scheduler\Interfaces\ZombieDetectorInterface::class,
            function (Container $c) use ($config) {
                $logger = null;
                try { $logger = $c->get(\Framework\Scheduler\Logger::class); } catch (\Throwable $e) {}
                $handler = new \App\Services\Scheduler\Detection\ZombieHandler(
                    $c->get(\App\Models\SchedulerTaskModel::class),
                    $logger
                );
                $cfg = $config['worker_coordinator'] ?? [];
                $handler->configure((int)($cfg['zombie_threshold'] ?? 120));
                return $handler;
            }
        );

        // ── RecoveryEngine 绑定更新 ──
        $container->bind(RecoveryEngine::class, function (Container $c) {
            $logger = null;
            try { $logger = $c->get(\Framework\Scheduler\Logger::class); } catch (\Throwable $e) {}
            $eventBus = null;
            try { $eventBus = $c->get(EventBus::class); } catch (\Throwable $e) {}
            $zombieDetector = null;
            try { $zombieDetector = $c->get(\Framework\Scheduler\Interfaces\ZombieDetectorInterface::class); } catch (\Throwable $e) {}
            return new RecoveryEngine(
                $c->get(\Framework\Scheduler\Interfaces\TaskStorageInterface::class),
                $c->get(RedisCache::class),
                $zombieDetector,
                $logger,
                $eventBus
            );
        }, true);
    }

    public function boot(Container $container): void
    {
        // nothing
    }
}
