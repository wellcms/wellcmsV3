<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

/**
 * 随机策略
 */
class RandomLoadBalancer implements \Framework\Database\Pool\LoadBalancer\LoadBalancerInterface
{
    public function select(array $poolEntries): ?\PDO
    {
        if (empty($poolEntries)) {
            return null;
        }
        $idx = array_rand($poolEntries);
        $pdo = $poolEntries[$idx]['connection'] ?? null;
        return $pdo instanceof \PDO ? $pdo : null;
    }
}
