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
                (bool)(\defined('DEBUG') ? \DEBUG : 0),
                $errorConfig
            );
        }, true, true);

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
        $container->bind(\App\Services\Storage\UploadService::class, static function ($container, array $params) {
            $service = $container->make(\App\Services\Storage\UploadService::class, $params);
            $request = $container->get(\Framework\Http\Interfaces\ServerRequestInterface::class);
            if ($request !== null) $service->captureContext($request);
            return $service;
        }, false, true);

        $container->bind(\Framework\Session\SessionInterface::class, function ($container) {
            $request = $container->get(\Framework\Http\Interfaces\ServerRequestInterface::class);
            return $request ? $request->getAttribute(\Framework\Session\SessionInterface::class) : null;
        }, false, true);

        $container->bind(\App\Services\System\MenuService::class, static function ($container, array $params) {
            return $container->make(\App\Services\System\MenuService::class, $params);
        }, false, true);

        // 5/6. 升级、商店与扩展管理按需创建真实对象，普通页面不预加载这组依赖。
        // 使用闭包延迟工厂，保留构造函数类型约束及原有单例/多例规则。
        // Swoole 只在启动时预热一次，保留其首次协程请求前的初始化时序。
        $deferManagement = !defined('SWOOLE_MODE') || !\SWOOLE_MODE;
        $managementServices = [
            \App\Services\Upgrade\Downloader::class => true,
            \App\Services\Upgrade\Deployer::class => true,
            \App\Services\Upgrade\ScriptRunner::class => true,
            \App\Services\Upgrade\UpgradeService::class => true,
            \App\Services\Market\MarketClient::class => true,
            \App\Services\Market\RetryPolicy::class => true,
            \App\Services\Market\MarketCircuitBreaker::class => true,
            \App\Services\Market\MarketFallbackService::class => true,
            \Framework\Utils\HttpClient::class => true,
            \App\Services\Extension\ExtensionInstaller::class => true,
            \App\Services\Extension\ExtensionOperationTokenService::class => true,
            \App\Services\Extension\ExtensionManager::class => false,
        ];
        foreach ($managementServices as $service => $singleton) {
            $container->bind($service, static function ($container, array $params) use ($service) {
                return $container->make($service, $params);
            }, $singleton, $deferManagement);
        }

        // 7. 数据库分区管理器
        // PartitionDialectInterface
        $container->bind(
            \Framework\Database\Partition\PartitionDialectInterface::class,
            function ($c) {
                $dbConfig = $c->get('dbConfig');
                $driver = $dbConfig['driver'] ?? 'mysql';

                // PG 版本预检（pg_partition_tree 是 PG 12+ 函数）
                if ($driver === 'pgsql') {
                    $db = $c->get(\Framework\Database\Interfaces\DatabaseInterface::class);
                    $version = $db->version();
                    // 解析前两个数字段，排除括号后缀如 "14.10 (Ubuntu ...)"
                    $majorVer = preg_match('/^(\d+\.\d+)/', $version, $m) ? $m[1] : $version;
                    if (version_compare($majorVer, '12.0', '<')) {
                        throw new \RuntimeException(sprintf(
                            'PartitionManager requires PostgreSQL 12+ (current: %s). '
                            . 'Older PG versions must manage partitions manually.',
                            $version
                        ));
                    }
                }

                return \Framework\Database\Partition\PartitionDialectFactory::createFromConfig(
                    $dbConfig
                );
            },
            false,  // 非单例？不——方言无状态，单例安全
            true    // defer: 延迟实例化
        );

        // PartitionManager
        $container->bind(
            \Framework\Database\Partition\PartitionManager::class,
            function ($c) {
                $dbConfig = $c->get('dbConfig');
                $dialect = $c->get(
                    \Framework\Database\Partition\PartitionDialectInterface::class
                );

                // TaskManage 可能不可用（无 Redis），使用可选获取
                $taskManage = null;
                if ($c->has(\Framework\Scheduler\TaskManage::class)) {
                    try {
                        $taskManage = $c->get(\Framework\Scheduler\TaskManage::class);
                    } catch (\Throwable $e) {
                        // TaskManage 需要 Redis，忽略失败
                    }
                }

                return new \Framework\Database\Partition\PartitionManager(
                    $c->get(\Framework\Database\Partition\PartitionRegistry::class),
                    $c->get(\Framework\Database\Interfaces\DatabaseInterface::class),
                    $c->get(\Framework\Cache\Interfaces\CacheInterface::class),
                    $c->get(\Framework\Logger\LoggerInterface::class),
                    $taskManage,
                    $dbConfig['prefix'] ?? 'well_',
                    $dialect
                );
            },
            false,
            true
        );

        // PartitionRegistry：仅冷发现读取被动结构声明，不加载插件代码或按启用状态过滤。
        $container->bind(
            \Framework\Database\Partition\PartitionRegistry::class,
            function ($c) {
                $dialect = $c->get(
                    \Framework\Database\Partition\PartitionDialectInterface::class
                );
                $declaredConfigProvider = static function (): array {
                    $files = glob(APP_PATH . 'plugins/*/config.json');
                    if ($files === false) throw new \RuntimeException('Partition manifest discovery failed');
                    $declared = [];
                    foreach ($files as $file) {
                        $json = file_get_contents($file);
                        if ($json === false || trim($json) === '') {
                            throw new \RuntimeException('Partition manifest is unreadable: ' . $file);
                        }
                        $manifest = \Framework\Utils\SecurityHelper::jsonDecode($json);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \RuntimeException('Partition manifest JSON is invalid: ' . $file);
                        }
                        if (!array_key_exists('partitions', $manifest)) continue;
                        if (!is_array($manifest['partitions'])) {
                            throw new \RuntimeException('Partition declarations must be a table map: ' . $file);
                        }
                        foreach ($manifest['partitions'] as $table => $args) {
                            if (!is_string($table) || $table === '' || isset($declared[$table])
                                || !is_array($args) || array_keys($args) !== [0, 1, 2, 3, 4, 5]) {
                                throw new \RuntimeException('Invalid or duplicate partition declaration: ' . $file);
                            }
                            $config = new \Framework\Database\Partition\PartitionConfig($table, ...$args);
                            if ($config->partitionColumn === ''
                                || !in_array($config->period, ['quarter', 'month'], true)
                                || $config->advanceCount < 1 || $config->retention < 0 || $config->subPartitions < 0
                                || $config->subPartitionColumn === ''
                                || (($config->subPartitionColumn === null) !== ($config->subPartitions === 0))) {
                                throw new \RuntimeException('Invalid partition policy: ' . $table . ' in ' . $file);
                            }
                            $declared[$table] = $config;
                        }
                    }
                    return $declared;
                };
                return new \Framework\Database\Partition\PartitionRegistry(
                    $c->get(\Framework\Cache\Interfaces\CacheInterface::class),
                    $c->get(\Framework\Database\Interfaces\DatabaseInterface::class),
                    $dialect,
                    $declaredConfigProvider,
                    static function () use ($c) {
                        return $c->get(\App\Services\System\KeyValueService::class)->settingGet('partition_registry_configs');
                    },
                    static function (array $configs) use ($c): void {
                        $kv = $c->get(\App\Services\System\KeyValueService::class);
                        if (!$kv->settingSet('partition_registry_configs', $configs)) {
                            throw new \RuntimeException('Unable to persist partition registry');
                        }
                    }
                );
            },
            false,
            true
        );

        // Scheduler RedisCache：使用 config/Cache.php 的 cachepre 原值，
        // 多站点隔离通过环境变量 WELLCMS_SITE_ID 配置。CLI/Swoole/FPM 统一。
        $container->bind(\Framework\Cache\Drivers\RedisCache::class, function ($c) {
            $cfg = $c->get('cacheConfig');
            $redisCfg = $cfg['stores']['redis'] ?? [];
            if (empty($redisCfg)) {
                throw new \RuntimeException("Scheduler requires 'redis' driver in config/cache.php");
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
