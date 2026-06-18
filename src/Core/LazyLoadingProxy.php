<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Core;

// 赖加载代理类
class LazyLoadingProxy
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var string */
    private $className;
    /** @var object */
    private $realInstance = null;

    public function __construct(\Framework\Core\Container $container, string $className)
    {
        $this->container = $container;
        $this->className = $className;
    }

    public function __call(string $method, array $args)
    {
        if ($this->realInstance === null) {
            $this->realInstance = $this->container->get($this->className);
        }
        return $this->realInstance->$method(...$args);
    }
}
