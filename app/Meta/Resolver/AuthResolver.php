<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Meta\Resolver;

class AuthResolver implements \App\Interfaces\MetaMiddlewareResolverInterface
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    public function supports(string $key, $value): bool
    {
        return 'requiresAuth' === $key && true === $value;
    }

    public function create(string $key, $value): \Framework\Http\Interfaces\MiddlewareInterface
    {
        return new \App\Middleware\AuthMiddleware($this->container);
    }
}
