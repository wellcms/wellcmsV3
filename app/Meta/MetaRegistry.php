<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Meta;

class MetaRegistry
{
    /** @var array */
    private $resolvers = [];

    public function addResolver(\App\Interfaces\MetaMiddlewareResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @return array
     */
    public function getMiddleware(string $key, $value)
    {
        foreach ($this->resolvers as $r) {
            if ($r->supports($key, $value)) {
                return $r->create($key, $value);
            }
        }
        return null;
    }
}
