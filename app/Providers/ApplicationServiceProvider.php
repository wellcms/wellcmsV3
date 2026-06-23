<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class ApplicationServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        // hook app_Providers_ApplicationServiceProvider_register_start.php

        // 1. 基础工具类
        $container->set(\App\Factory\ControllerFactory::class, new \App\Factory\ControllerFactory($container));
        $container->set(\Framework\Http\Interfaces\ResponseFactoryInterface::class, \Framework\Http\Psr7\Factories\ResponseFactory::getInstance());
        $container->set(\Framework\Http\Interfaces\StreamFactoryInterface::class, new \Framework\Http\Psr7\Factories\StreamFactory());
        $container->set(\Framework\Http\Interfaces\UriFactoryInterface::class, \Framework\Http\Psr7\Factories\UriFactory::getInstance());
        $container->set(\Framework\Http\Interfaces\UploadedFileFactoryInterface::class, \Framework\Http\Psr7\Factories\UploadedFileFactory::getInstance());

        // PSR-7 ServerRequest (协程安全绑定)
        $container->bind(\Framework\Http\Interfaces\ServerRequestInterface::class, function () {
            return \Framework\Http\Psr7\RequestStack::getCurrent();
        }, false, true);

        // 响应发送器 (Kernel::run 优先从容器获取)
        $container->set(\Framework\Http\Psr7\ResponseSender::class, new \Framework\Http\Psr7\ResponseSender());

        // 内核 (Kernel) 绑定
        $container->bind(\Framework\Http\Interfaces\KernelInterface::class, \App\Core\Kernel::class, true, false);

        // 2. 路由与模板工具 (使用 bind 以延迟加载)
        $container->bind(\Framework\Http\Routing\UrlGeneratorInterface::class, function ($container) {
            $uri = $container->get(\Framework\Http\Interfaces\UriFactoryInterface::class)->createFromGlobals();
            $mode = (int)($container->get('appConfig')['url_rewrite_on'] ?? 0);
            return new \Framework\Http\Routing\CoreUrlGenerator($uri, $mode);
        }, true, false);

        $container->bind(\App\Controllers\Base\TemplateManager::class, function ($container) {
            $view = $container->get('viewConfig');
            $plugin = $container->get('pluginConfig');
            return new \App\Controllers\Base\TemplateManager(
                $container->get(\Framework\Cache\Interfaces\CacheInterface::class),
                $view['themes_path'],
                $plugin['plugins_path'],
                $view['device_prefixes']
            );
        }, true, false);

        $appConfig = $container->get('appConfig');
        $policy = isset($appConfig['template_error_policy']) ? $appConfig['template_error_policy'] : [];

        $container->bind(\App\Controllers\Base\ResponseFormatter::class, function ($c) use ($policy) {
            return new \App\Controllers\Base\ResponseFormatter(
                $c->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class),
                $c,
                $policy
            );
        }, true, false);

        // 新增：ErrorResponseBuilder
        $errorConfig = isset($appConfig['error_handling']) ? $appConfig['error_handling'] : [];
        $container->bind(\App\Services\ErrorResponseBuilder::class, function ($c) use ($errorConfig) {
            return new \App\Services\ErrorResponseBuilder(
                $c,
                (bool)(\defined('DEBUG') ? DEBUG : 0),
                $errorConfig
            );
        }, true, false);

        // 3. Session 管理系统 (单例注入并提前锁定配置)
        $container->bind(\App\Session\Handler\DatabaseSessionHandler::class, \App\Session\Handler\DatabaseSessionHandler::class, true, false);

        $container->bind(\App\Session\Service\SessionManager::class, function ($container) {
            $manager = new \App\Session\Service\SessionManager(
                $container->get(\App\Session\Handler\DatabaseSessionHandler::class),
                $container->get(\Framework\Cache\Interfaces\CacheInterface::class)
            );
            $manager->configure($container->get('sessionConfig')); // 初始化 SSOT 名称
            return $manager;
        }, true, false);

        // 4. 业务工具类
        $container->bind(\App\Utils\I18nDateFormatter::class, function ($container) {
            $cfg = $container->get('i18nConfig');
            return new \App\Utils\I18nDateFormatter($cfg['locale'] ?? 'zh', $cfg['timezone'] ?? 'UTC');
        }, true, false);

        $container->bind(\App\I18n\LanguageManager::class, function ($c) {
            return new \App\I18n\LanguageManager($c->get('i18nConfig'), $c->get(\App\Services\System\KeyValueService::class));
        }, true, false);

        $container->bind(\App\Interfaces\LanguageLoaderInterface::class, function ($c) {
            // 从当前请求栈中动态获取语言加载器，确保协程安全
            $request = \Framework\Http\Psr7\RequestStack::getCurrent();
            $loader = $request ? $request->getAttribute(\App\Interfaces\LanguageLoaderInterface::class) : null;
            if ($loader) return $loader;

            // 兜底策略：如果请求中不存在加载器（例如某些中间件提前报错或 cli 环境），则返回单例 LanguageManager
            // 注意：LanguageManager 内部使用 StatefulTrait 确保了协程安全
            return $c->get(\App\I18n\LanguageManager::class);
        }, false, true); // 注意：此处必须为 prototype (singleton=false)，因为请求在变，且 defer=true 避免 bootstrap 阶段解析

        $container->bind(\App\Controllers\Base\MessageController::class, \App\Controllers\Base\MessageController::class, false, true);

        $container->bind(\App\Services\Stats\RuntimeStats::class, function ($container) {
            $stats = new \App\Services\Stats\RuntimeStats(
                $container->get(\Framework\Cache\Interfaces\CacheInterface::class),
                $container->get('i18nConfig')['timezone'] ?? 'UTC'
            );

            // 注册核心统计项
            $stats->registerStat('users', function () use ($container) {
                return $container->get(\App\Services\Auth\UserService::class)->count();
            });

            // hook app_Providers_ApplicationServiceProvider_register_stats.php

            return $stats;
        }, true, true);

        $container->bind(\App\Services\Storage\StorageManager::class, \App\Services\Storage\StorageManager::class, true, false);
        $container->bind(\App\Services\Storage\UploadService::class, \App\Services\Storage\UploadService::class, false, false);

        $container->bind(\Framework\Session\SessionInterface::class, function ($container) {
            $request = $container->get(\Framework\Http\Interfaces\ServerRequestInterface::class);
            return $request ? $request->getAttribute(\Framework\Session\SessionInterface::class) : null;
        }, false, true);

        $container->bind(\App\Services\System\MenuService::class, \App\Services\System\MenuService::class, false, false);

        // 5. 自动升级系统服务 (主程序集成)
        $container->bind(\App\Services\Upgrade\Downloader::class, \App\Services\Upgrade\Downloader::class, true, false);

        $container->bind(\App\Services\Upgrade\Deployer::class, \App\Services\Upgrade\Deployer::class, true, false);
        $container->bind(\App\Services\Upgrade\ScriptRunner::class, \App\Services\Upgrade\ScriptRunner::class, true, false);
        $container->bind(\App\Services\Upgrade\UpgradeService::class, \App\Services\Upgrade\UpgradeService::class, true, false);

        // 6. 官方商店与扩展管理系统
        $container->bind(\App\Services\Market\MarketClient::class, \App\Services\Market\MarketClient::class, true, false);
        $container->bind(\App\Services\Market\RetryPolicy::class, \App\Services\Market\RetryPolicy::class, true, false);
        $container->bind(\App\Services\Market\MarketCircuitBreaker::class, \App\Services\Market\MarketCircuitBreaker::class, true, false);
        $container->bind(\App\Services\Market\MarketFallbackService::class, \App\Services\Market\MarketFallbackService::class, true, false);
        $container->bind(\App\Services\Extension\ExtensionInstaller::class, \App\Services\Extension\ExtensionInstaller::class, true, false);
        $container->bind(\App\Services\Extension\ExtensionManager::class, \App\Services\Extension\ExtensionManager::class, false, false);

        // 7. 数据库分区管理器
        $container->bind(\Framework\Database\Partition\PartitionRegistry::class, function ($container) {
            $cache = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
            $db = $container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
            return new \Framework\Database\Partition\PartitionRegistry($cache, $db);
        }, true, false);

        $container->bind(\Framework\Database\Partition\PartitionManager::class, function ($container) {
            $registry = $container->get(\Framework\Database\Partition\PartitionRegistry::class);
            $db = $container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
            $cache = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);

            // TaskManage 可能不可用（无 Redis），使用可选获取
            $taskManage = null;
            if ($container->has(\Framework\Scheduler\TaskManage::class)) {
                try {
                    $taskManage = $container->get(\Framework\Scheduler\TaskManage::class);
                } catch (\Throwable $e) {
                    // TaskManage 需要 Redis，忽略失败
                }
            }

            $dbConfig = $container->get('dbConfig');
            $prefix = isset($dbConfig['prefix']) ? $dbConfig['prefix'] : 'well_';

            return new \Framework\Database\Partition\PartitionManager(
                $registry,
                $db,
                $cache,
                $logger,
                $taskManage,
                $prefix
            );
        }, true, false);

        // Scheduler RedisCache：web 端从请求域名动态追加 key 前缀，多站点自动隔离
        // Swoole HTTP / FPM 均可通过 RequestStack::getCurrent() 获取当前域名
        // CLI scheduler 无请求，走 cachepre 原值或 env 前缀
        $container->bind(\Framework\Cache\Drivers\RedisCache::class, function ($c) {
            $cfg = $c->get('cacheConfig');
            $redisCfg = $cfg['stores']['redis'] ?? [];
            if (empty($redisCfg)) {
                throw new \RuntimeException("Scheduler requires 'redis' driver in config/cache.php");
            }
            $request = \Framework\Http\Psr7\RequestStack::getCurrent();
            $host = $request ? $request->getUri()->getHost() : '';
            if ($host !== '') {
                $redisCfg['cachepre'] = ($redisCfg['cachepre'] ?? '')
                    . str_replace('.', '_', $host) . '_';
            }
            return new \Framework\Cache\Drivers\RedisCache($redisCfg);
        }, false, true);

        // hook app_Providers_ApplicationServiceProvider_register_end.php
    }

    public function boot(\Framework\Core\Container $container): void
    {
        // 运行时无需额外操作，可用于预热、动态路由热加载等
        // hook app_Providers_ApplicationServiceProvider_boot_start.php
    }
}
