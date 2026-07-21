<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Providers;

/**
 * 所有 ServiceProvider 都须实现此接口：
 * - register(): 向容器绑定服务
 * - boot():     在所有服务注册完后，可执行启动时逻辑（可选）
 */
interface ServiceProviderInterface
{
    /**
     * 向服务定位器注册服务
     *
     * @param \Framework\Core\Container $container
     * @return void
     */
    public function register(\Framework\Core\Container $container): void;

    /**
     * 在容器所有服务注册完毕后执行
     *
     * @param \Framework\Core\Container $container
     * @return void
     */
    public function boot(\Framework\Core\Container $container): void;
}
