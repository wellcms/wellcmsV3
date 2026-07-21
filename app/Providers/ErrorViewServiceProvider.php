<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Providers;

use App\View\Error\ConfiguredErrorViewModel;
use App\View\Error\ErrorViewModelInterface;
use App\View\Error\ErrorViewRenderer;
use Framework\Providers\ServiceProviderInterface;
use Framework\Core\Container;

/**
 * 错误视图服务提供者。
 *
 * 注册统一错误视图渲染器与视图模型，确保 ExceptionHandler 和 ErrorResponseBuilder
 * 共享同一渲染管线。
 */
class ErrorViewServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // 统一错误视图渲染器：零依赖，可直接实例化
        $container->set(ErrorViewRenderer::class, new ErrorViewRenderer());

        // 错误视图模型：容器就绪时从 appConfig 构造配置默认值
        $container->bind(ErrorViewModelInterface::class, function (Container $c) {
            $appConfig = [];
            try {
                if ($c->has('appConfig')) {
                    $appConfig = $c->get('appConfig');
                }
            } catch (\Throwable $e) {
                error_log('ErrorViewServiceProvider appConfig fallback: ' . $e->getMessage());
            }

            $defaultData = [
                'website' => [
                    'current' => [
                        'view' => $appConfig['view_url'] ?? '/views/',
                    ],
                    'static_version' => $appConfig['static_version'] ?? '',
                ],
                'data' => [
                    'redirect' => [
                        'url' => $appConfig['path'] ?? '/',
                    ],
                ],
            ];

            return new ConfiguredErrorViewModel($defaultData);
        }, true, false);
    }

    public function boot(Container $container): void
    {
        // no-op
    }
}
