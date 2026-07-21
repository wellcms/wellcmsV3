<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

/**
 * 轮询策略
 */
class RoundRobinLoadBalancer implements \Framework\Database\Pool\LoadBalancer\LoadBalancerInterface, \Framework\Database\Pool\LoadBalancer\NodeLoadBalancerInterface
{
    use \Framework\Database\Pool\CoroutineAwareTrait;

    /** @var int 非协程环境下使用的轮询指针 */
    protected $index = 0;

    /** @var array 非协程环境下 selectNode 用的当前权重缓存 */
    protected $nodeWeights = [];

    private function getContextKey(string $suffix): string
    {
        return 'lb_rr_' . $suffix . '_' . spl_object_id($this);
    }

    public function select(array $poolEntries): ?\PDO
    {
        $count = count($poolEntries);
        if ($count === 0) {
            return null;
        }
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $key = $this->getContextKey('index');
            $index = $ctx->$key ?? 0;
            $ctx->$key = ($index + 1) % $count;
        } else {
            $index = $this->index % $count;
            $this->index = ($index + 1) % $count;
        }
        $pdo = $poolEntries[$index]['connection'] ?? null;
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

        $ctx = self::coroContext();
        $key = $this->getContextKey('node_weights');
        $nodeWeights = [];
        if ($ctx !== null) {
            $nodeWeights = $ctx->$key ?? [];
        } else {
            $nodeWeights = $this->nodeWeights;
        }

        $total = 0;
        $best = null;
        foreach ($nodes as $node) {
            $nodeWeights[$node->nodeId] = ($nodeWeights[$node->nodeId] ?? 0) + $node->weight;
            $total += $node->weight;
            if ($best === null || $nodeWeights[$node->nodeId] > $nodeWeights[$best->nodeId]) {
                $best = $node;
            }
        }
        if ($best) {
            $nodeWeights[$best->nodeId] -= $total;
        }

        if ($ctx !== null) {
            $ctx->$key = $nodeWeights;
        } else {
            $this->nodeWeights = $nodeWeights;
        }

        return $best;
    }
}
