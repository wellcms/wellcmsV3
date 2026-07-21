<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

use Framework\Database\Pool\Node;

interface NodeLoadBalancerInterface
{
    /**
     * @param Node[] $nodes
     * @return Node|null
     */
    public function selectNode(array $nodes);
}
