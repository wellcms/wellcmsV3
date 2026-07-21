<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

use Framework\Database\Pool\Node;

/**
 * 加权随机策略（无状态，协程安全）
 */
class WeightedRandomLoadBalancer implements LoadBalancerInterface, NodeLoadBalancerInterface
{
    public function select(array $poolEntries): ?\PDO
    {
        if (empty($poolEntries)) {
            return null;
        }

        $totalWeight = 0;
        foreach ($poolEntries as $entry) {
            $totalWeight += $entry['weight'] ?? 1;
        }

        $rand = random_int(1, $totalWeight);
        foreach ($poolEntries as $entry) {
            $rand -= $entry['weight'] ?? 1;
            if ($rand <= 0) {
                $pdo = $entry['connection'] ?? null;
                return $pdo instanceof \PDO ? $pdo : null;
            }
        }

        $pdo = $poolEntries[0]['connection'] ?? null;
        return $pdo instanceof \PDO ? $pdo : null;
    }

    /**
     * @return array
     */
    public function selectNode(array $nodes)
    {
        if (empty($nodes)) {
            return null;
        }

        $totalWeight = 0;
        foreach ($nodes as $node) {
            $totalWeight += $node->weight;
        }

        $rand = random_int(1, $totalWeight);
        foreach ($nodes as $node) {
            $rand -= $node->weight;
            if ($rand <= 0) {
                return $node;
            }
        }

        return $nodes[0];
    }
}
