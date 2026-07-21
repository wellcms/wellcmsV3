<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Factory;

class ControllerFactory
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    /**
     * 根据类名实例化控制器：
     * 利用容器的深度依赖注入 (Auto-wiring) 自动解析控制器构造函数。
     * 确保 ServerRequestInterface、ResponseFormatter 等关键上下文依赖被正确注入。
     * @return object
     */
    public function create(string $class)
    {
        // 直接利用容器的自动解析能力，确保所有依赖（包括 ServerRequestInterface）正确注入
        return $this->container->get($class);
    }
}
