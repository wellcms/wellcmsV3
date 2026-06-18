<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

use Framework\Database\Pool\Node;

/**
 * 最少连接数策略
 */
class LeastConnectionsLoadBalancer implements \Framework\Database\Pool\LoadBalancer\LoadBalancerInterface, \Framework\Database\Pool\LoadBalancer\NodeLoadBalancerInterface
{
    public function select(array $poolEntries): ?\PDO
    {
        $best = null;
        $minReq = PHP_INT_MAX;
        foreach ($poolEntries as $entry) {
            $req = $entry['active_queries'] ?? 0;
            if ($req < $minReq) {
                $minReq = $req;
                $best = $entry['connection'] ?? null;
            }
        }
        return $best instanceof \PDO ? $best : null;
    }

    /**
     * @return array
     */
    public function selectNode(array $nodes)
    {
        $best = null;
        $minConn = PHP_INT_MAX;
        foreach ($nodes as $node) {
            $conn = $node->activeConnections ?? 0;
            if ($conn < $minConn) {
                $minConn = $conn;
                $best = $node;
            }
        }
        return $best;
    }
}
