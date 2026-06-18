<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Providers;

class LoggerServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        // 加载并缓存所有配置，需优先在入口注册 ConfigServiceProvider 类
        $container->bind(\Framework\Logger\LoggerInterface::class, function ($container) {
            $cfg = $container->get('loggerConfig');
            if ($cfg['channel'] === 'syslog') {
                return new \Framework\Logger\SysLogger($cfg['syslog']);
            }
            return new \Framework\Logger\FileLogger($cfg['file']);
        }, true, true);
    }

    public function boot(\Framework\Core\Container $container): void
    {
        // nothing
    }
}
