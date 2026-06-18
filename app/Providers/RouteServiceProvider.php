<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class RouteServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void {}

    public function boot(\Framework\Core\Container $container): void
    {
        // 在所有服务注册完毕后安全加载路由配置
        $routesConfig = include \App\Core\Compile::include(APP_PATH . 'app/Routes/Routes.php');

        $routeObjects = [];
        foreach ($routesConfig as $r) {
            $route = new \Framework\Http\Router\Route($r['methods'], $r['path'], $r['handler']);

            // 挂载中间件
            foreach ($r['middleware'] ?? [] as $mw) {
                if (empty($mw)) continue;
                $route->middleware($mw['class'], $mw['params'] ?? []);
            }

            // 附加元数据
            if (!empty($r['meta'])) {
                $route->setMeta($r['meta']);
            }

            $routeObjects[] = $route;
        }

        // 将路由数组注入 CompiledRouter 服务
        $container->set(\Framework\Http\Router\CompiledRouter::class, new \Framework\Http\Router\CompiledRouter($routeObjects));
    }
}
