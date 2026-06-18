<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class MetaServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        try {
            $container->bind(\App\Meta\MetaRegistry::class, function ($container) {
                $reg = new \App\Meta\MetaRegistry();
                $reg->addResolver(new \App\Meta\Resolver\AuthResolver($container));
                $reg->addResolver(new \App\Meta\Resolver\CsrfResolver($container));
                $reg->addResolver(new \App\Meta\Resolver\UserPermResolver($container));
                $reg->addResolver(new \App\Meta\Resolver\TokenResolver($container));
                $reg->addResolver(new \App\Meta\Resolver\AdminSignInResolver($container));
                // 可扩展更多 Meta 路由中间件
                // hook app_Providers_MetaServiceProvider_register.php
                return $reg;
            }, true, true);
        } catch (\Throwable $e) {
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (defined('DEBUG') && DEBUG >= 2) throw $e;
        }
    }

    public function boot(\Framework\Core\Container $container): void {}

    // hook app_Providers_MetaServiceProvider.php
}