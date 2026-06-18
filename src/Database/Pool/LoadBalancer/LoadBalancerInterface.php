<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool\LoadBalancer;

/**
 * 负载均衡策略接口
 */
interface LoadBalancerInterface
{
    /**
     * 从可用连接列表中选择一个 PDO 连接
     *
     * @param array $poolEntries 每项格式：[
     *     'connection' => PDO,
     *     'connectionRequests' => int,
     *     // … 其它字段 …
     * ]
     * @return \PDO|null
     */
    public function select(array $poolEntries): ?\PDO;
}
