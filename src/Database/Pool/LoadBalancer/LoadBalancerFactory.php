<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

/**
 * 负载均衡器工厂
 *
 * @deprecated 当前 DatabaseServiceProvider 直接使用配置中的类名反射实例化负载均衡器，本工厂不再被核心使用。
 *             保留仅作向后兼容，新代码可直接 new 对应策略类或通过 DI 注入。
 */
class LoadBalancerFactory
{
    /**
     * 根据类名或别名创建负载均衡器实例
     *
     * @param string $name 完整类名或简写别名：random|round_robin|weighted_random|weighted_round_robin|least_connections
     * @return LoadBalancerInterface
     * @throws \InvalidArgumentException
     */
    public static function make(string $name): LoadBalancerInterface
    {
        $map = [
            'random' => RandomLoadBalancer::class,
            'round_robin' => RoundRobinLoadBalancer::class,
            'weighted_random' => WeightedRandomLoadBalancer::class,
            'weighted_round_robin' => WeightedRoundRobinLoadBalancer::class,
            'least_connections' => LeastConnectionsLoadBalancer::class,
        ];

        $class = $map[strtolower($name)] ?? $name;

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Load balancer class not found: {$class}");
        }

        $instance = new $class();

        if (!($instance instanceof LoadBalancerInterface)) {
            throw new \InvalidArgumentException("Load balancer must implement LoadBalancerInterface: {$class}");
        }

        return $instance;
    }
}
