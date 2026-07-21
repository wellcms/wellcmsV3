<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

/**
 * 加权轮询策略
 */
class WeightedRoundRobinLoadBalancer implements \Framework\Database\Pool\LoadBalancer\LoadBalancerInterface, \Framework\Database\Pool\LoadBalancer\NodeLoadBalancerInterface
{
    use \Framework\Database\Pool\CoroutineAwareTrait;

    /** @var array [int => int] 非协程环境下 select() 用的当前权重缓存 */
    protected $currentWeights = [];

    /** @var array [nodeId => current_weight] 非协程环境下 selectNode() 用的当前权重缓存 */
    protected $nodeWeights = [];

    private function getContextKey(string $suffix): string
    {
        return 'lb_wrr_' . $suffix . '_' . spl_object_id($this);
    }

    public function select(array $poolEntries): ?\PDO
    {
        if (empty($poolEntries)) {
            return null;
        }

        $ctx = self::coroContext();
        $key = $this->getContextKey('current_weights');
        $currentWeights = [];
        if ($ctx !== null) {
            $currentWeights = $ctx->$key ?? [];
        } else {
            $currentWeights = $this->currentWeights;
        }

        $best = null;
        $maxWeight = -PHP_INT_MAX;
        $total = 0;

        foreach ($poolEntries as $i => $entry) {
            $w = $entry['weight'] ?? 1;
            $currentWeights[$i] = ($currentWeights[$i] ?? 0) + $w;
            $total += $w;
            if ($currentWeights[$i] > $maxWeight) {
                $maxWeight = $currentWeights[$i];
                $best = $entry;
            }
        }

        if ($best !== null) {
            $bestIdx = array_search($best, $poolEntries, true);
            if ($bestIdx !== false) {
                $currentWeights[$bestIdx] -= $total;
            }
        }

        if ($ctx !== null) {
            $ctx->$key = $currentWeights;
        } else {
            $this->currentWeights = $currentWeights;
        }

        $pdo = $best['connection'] ?? null;
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
