<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Meta\Resolver;

class TokenResolver implements \App\Interfaces\MetaMiddlewareResolverInterface
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    public function supports(string $key, $value): bool
    {
        return 'requiresToken' === $key && is_array($value) && true === $value['enable'];
    }

    public function create(string $key, $value): \Framework\Http\Interfaces\MiddlewareInterface
    {
        return new \App\Middleware\TokenMiddleware(
            $this->container,
            $this->container->get(\App\Services\Auth\TokenService::class),
            $value['ttl'] ?? 600
        );
    }
}
