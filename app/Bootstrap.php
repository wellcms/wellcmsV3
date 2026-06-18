<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App;

use Framework\Core\Container;

class Bootstrap
{
    public static function init(\Framework\Core\Container $container = null): Container
    {
        \Framework\Database\Collector\QueryCollector::startTime();

        $appPath = defined('APP_PATH') ? APP_PATH : null;
        if ($appPath === null) {
            $currDir = str_replace('\\', '/', __DIR__);
            if (strpos($currDir, '/storage/tmp/') !== false) {
                // 如果在编译缓存目录下，回溯到位次
                $appPath = str_replace('\\', '/', dirname(__DIR__, 4)) . '/';
            } else {
                $appPath = str_replace('\\', '/', dirname(__DIR__)) . '/';
            }
        }
        $installLock = $appPath . 'install/install.lock';
        $dbConfig = $appPath . 'config/Database.php';

        // 统一初始化守护：改用 Exception 驱动，确保在 Swoole 环境下也能被 ExceptionHandler 捕获并正确处理
        if (!file_exists($installLock) || !file_exists($dbConfig)) {
            throw new \Framework\Exception\Infra\SiteNotInstalledException();
        }

        if ($container === null) {
            $container = new Container();
        }

        // hook app_Bootstrap_start.php

        $providers = [
            // hook app_Bootstrap_providers_start.php
            // 核心层 Provider
            'Logger' => \Framework\Providers\LoggerServiceProvider::class,
            'Config' => \App\Providers\ConfigServiceProvider::class,
            'Database' => \App\Providers\DatabaseServiceProvider::class,
            // hook app_Bootstrap_providers_before.php
            // 核心模型服务层优先注册
            'Model' => \App\Providers\ModelServiceProvider::class,
            // hook app_Bootstrap_providers_center.php
            // 应用层 Provider
            'Application' => \App\Providers\ApplicationServiceProvider::class,
            // hook app_Bootstrap_providers_middle.php
            'Route' => \App\Providers\RouteServiceProvider::class,
            // hook app_Bootstrap_providers_after.php
            'Meta' => \App\Providers\MetaServiceProvider::class,
            // hook app_Bootstrap_providers_end.php
        ];

        // 如果是调度器模式，添加额外的服务提供者
        if (defined('SCHEDULER_MODE') && SCHEDULER_MODE) {
            // 添加调度器所需的服务提供者
            $providers['Scheduler'] = \App\Providers\SchedulerServiceProvider::class;
            // hook app_Bootstrap_scheduler.php
        }

        // hook app_Bootstrap_before.php

        // 绑定服务 & 初始化逻辑
        foreach ($providers as $providerClass) {
            $prov = new $providerClass();
            $prov->register($container);
            if (method_exists($prov, 'boot')) {
                $prov->boot($container);
            }
        }

        // hook app_Bootstrap_center.php

        /**
         * 中间件队列——顺序即执行顺序
         * 重构点：利用容器自动注入特性，简化参数传递。
         * 1. 对于 type-hint 能够识别的对象，不再手动 get() 注入，由 MiddlewareFactory 自动解析。
         * 2. 只保留无法自动识别的标量配置项或特殊 Key。
         */
        $middlewareQueue = [
            // hook app_Bootstrap_middleware_start.php
            'ErrorHandler' => [
                \App\Middleware\ErrorHandlerMiddleware::class,
                ['debug' => (bool)\DEBUG]
            ],
            // hook app_Bootstrap_middleware_before.php
            'RequestProcessor' => [
                \Framework\Http\Middleware\RequestProcessorMiddleware::class,
                [
                    'urlRewriteMode' => (int)($container->get('appConfig')['url_rewrite_on'] ?? 0),
                    'uploadConfig' => $container->get('uploadConfig'),
                ]
            ],
            'LanguageLoader' => [\App\Middleware\LanguageMiddleware::class, []],
            'Session' => [\App\Middleware\SessionMiddleware::class, []],
            'Runtime' => [\App\Middleware\RuntimeMiddleware::class, []],
            // hook app_Bootstrap_middleware_center.php
            'Throttle' => [
                \App\Middleware\ThrottleMiddleware::class,
                [
                    // 仅保留无法通过类型推导识别的 Config 阵列
                    'appConfig' => $container->get('appConfig'),
                    'cacheConfig' => $container->get('cacheConfig'),
                    'sessionConfig' => $container->get('sessionConfig'),
                ]
            ],
            // hook app_Bootstrap_middleware_middle.php
            'Router' => [
                \App\Middleware\RouterMiddleware::class,
                [
                    'compiledRouter' => $container->get(\Framework\Http\Router\CompiledRouter::class),
                ]
            ],
            'XssFilter' => [
                \App\Middleware\XssFilterMiddleware::class,
                [
                    'replace_original' => true,
                    'allow_html_fields' => ['content', 'message', 'data', 'remark', 'comment', 'brief', 'description'], // 可在配置中扩展
                ]
            ],
            // hook app_Bootstrap_middleware_after.php
            'MetaDispatcher' => [
                \App\Middleware\MetaDispatcherMiddleware::class,
                [], // 所有依赖 (ControllerFactory, MetaRegistry, MiddlewareFactory) 均已在构造函数中通过类型推导自动注入
            ],
            // hook app_Bootstrap_middleware_end.php
        ];

        // hook app_Bootstrap_middle.php

        $mwProv = new \App\Providers\MiddlewareServiceProvider($middlewareQueue);
        $mwProv->register($container);
        $mwProv->boot($container);
        unset($middlewareQueue);

        // hook app_Bootstrap_after.php

        $container->preResolve();

        // hook app_Bootstrap_end.php

        return $container;
    }
}
