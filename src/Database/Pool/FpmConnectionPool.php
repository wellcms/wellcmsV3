<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

use Framework\Exception\Infra\PoolException;

/**
 * FPM 阻塞环境连接池
 *
 * 基于数组管理从库连接，支持阻塞轮询等待空闲连接。
 */
class FpmConnectionPool extends AbstractPool
{
    /** @var array [spl_object_id => ['shard' => string]] 全局连接快速索引 */
    protected $globalConnectionRegistry = [];

    /** 获取连接 **/
    public function getConnection(string $role = 'slave', string $shard = ''): \PDO
    {
        if ($role === 'master' || $this->inTransaction($shard)) {
            return $this->getMaster($shard);
        }

        $this->totalRequests++;
        $shardPool = $this->getShardPool($shard);

        if (empty($shardPool->nodes)) {
            return $this->getMaster($shard);
        }

        $this->adjustPoolSize($shard);

        // 尝试复用空闲连接
        $idleSlots = [];
        $now = time();
        foreach ($shardPool->connections as $i => $slot) {
            if (false === $slot['in_use'] && $slot['connection'] instanceof \PDO) {
                $node = $this->findNode($shard, $slot['node_id'] ?? '');
                if ($node && $node->fusedUntil > $now) {
                    $oid = spl_object_id($slot['connection']);
                    $shardPool->connections[$i]['connection'] = null;
                    unset($shardPool->connections[$i]);
                    $node->activeConnections = max(0, $node->activeConnections - 1);
                    $node->totalConnections = max(0, $node->totalConnections - 1);
                    unset($shardPool->connectionRegistry[$oid]);
                    unset($this->globalConnectionRegistry[$oid]);
                    continue;
                }
                if ($now - $slot['last_used'] > 60) {
                    if (!$this->validateConnection($slot['connection'])) {
                        $oid = spl_object_id($slot['connection']);
                        $shardPool->connections[$i]['connection'] = null;
                        unset($shardPool->connections[$i]);
                        if ($node) {
                            $node->activeConnections = max(0, $node->activeConnections - 1);
                            $node->totalConnections = max(0, $node->totalConnections - 1);
                        }
                        unset($shardPool->connectionRegistry[$oid]);
                        unset($this->globalConnectionRegistry[$oid]);
                        continue;
                    }
                }
                $idleSlots[$i] = $slot;
            }
        }

        if (!empty($idleSlots)) {
            if ($this->loadBalancer) {
                $selectedPdo = $this->loadBalancer->select(array_values($idleSlots));
                if ($selectedPdo) {
                    foreach ($shardPool->connections as $idx => &$slot) {
                        if ($slot['connection'] === $selectedPdo) {
                            $slot['in_use'] = true;
                            $slot['last_used'] = $now;
                            $node = $this->findNode($shard, $slot['node_id'] ?? '');
                            if ($node) {
                                $node->activeConnections++;
                            }
                            $this->successful++;
                            return $selectedPdo;
                        }
                    }
                }
            }

            $keys = array_keys($idleSlots);
            $idx = reset($keys);
            $shardPool->connections[$idx]['in_use'] = true;
            $shardPool->connections[$idx]['last_used'] = $now;
            $node = $this->findNode($shard, $shardPool->connections[$idx]['node_id'] ?? '');
            if ($node) {
                $node->activeConnections++;
            }
            $this->successful++;
            return $shardPool->connections[$idx]['connection'];
        }

        if ((count($shardPool->connections) + $this->establishingConnections) < $this->maxConnections) {
            return $this->createSlave($shard);
        }

        // 等待空闲连接（FPM 阻塞轮询，指数退避以降低 CPU 消耗）
        $start = microtime(true);
        $deadline = $start + $this->timeout;
        $sleepUs = 10000; // 初始 10ms
        while (microtime(true) < $deadline) {
            // 优先快速检查是否有空位可新建连接
            if ((count($shardPool->connections) + $this->establishingConnections) < $this->maxConnections) {
                return $this->createSlave($shard);
            }
            foreach ($shardPool->connections as $i => &$slot) {
                if (false === $slot['in_use'] && $slot['connection'] instanceof \PDO) {
                    $node = $this->findNode($shard, $slot['node_id'] ?? '');
                    if ($node && $node->fusedUntil > $now) {
                        $oid = spl_object_id($slot['connection']);
                        $slot['connection'] = null;
                        unset($shardPool->connections[$i]);
                        $node->activeConnections = max(0, $node->activeConnections - 1);
                        $node->totalConnections = max(0, $node->totalConnections - 1);
                        unset($shardPool->connectionRegistry[$oid]);
                        unset($this->globalConnectionRegistry[$oid]);
                        if (count($shardPool->connections) < $this->maxConnections) {
                            return $this->createSlave($shard);
                        }
                        continue;
                    }
                    if ($now - $slot['last_used'] > 60) {
                        if (!$this->validateConnection($slot['connection'])) {
                            $oid = spl_object_id($slot['connection']);
                            $slot['connection'] = null;
                            unset($shardPool->connections[$i]);
                            if ($node) {
                                $node->activeConnections = max(0, $node->activeConnections - 1);
                                $node->totalConnections = max(0, $node->totalConnections - 1);
                            }
                            unset($shardPool->connectionRegistry[$oid]);
                            unset($this->globalConnectionRegistry[$oid]);
                            if (count($shardPool->connections) < $this->maxConnections) {
                                return $this->createSlave($shard);
                            }
                            continue;
                        }
                    }
                    $slot['in_use'] = true;
                    $slot['last_used'] = $now;
                    if ($node) {
                        $node->activeConnections++;
                    }
                    $this->successful++;
                    $this->totalWait += (microtime(true) - $start);
                    return $slot['connection'];
                }
            }
            usleep($sleepUs);
            if ($sleepUs < 50000) {
                $sleepUs = 50000;
            } elseif ($sleepUs < 100000) {
                $sleepUs = 100000;
            }
            $now = time();
        }

        $this->failed++;
        throw PoolException::poolError("No DB connection available for shard '{$shard}' after waiting {$this->timeout}s");
    }

    /** 释放读连接 **/
    public function releaseConnection(\PDO $conn): void
    {
        $oid = spl_object_id($conn);

        // O(1) 快速路径：通过全局索引定位 shard
        if (isset($this->globalConnectionRegistry[$oid])) {
            $shard = $this->globalConnectionRegistry[$oid]['shard'];
            $pool = $this->getShardPool($shard);
            foreach ($pool->connections as $i => &$slot) {
                if ($slot['connection'] === $conn) {
                    $node = $this->findNode($shard, $slot['node_id'] ?? '');
                    if ($node && $node->fusedUntil > time()) {
                        $slot['connection'] = null;
                        unset($pool->connections[$i]);
                        $pool->connections = array_values($pool->connections);
                        $node->activeConnections = max(0, $node->activeConnections - 1);
                        $node->totalConnections = max(0, $node->totalConnections - 1);
                        unset($pool->connectionRegistry[$oid]);
                        unset($this->globalConnectionRegistry[$oid]);
                        return;
                    }
                    if ($node) {
                        $node->activeConnections = max(0, $node->activeConnections - 1);
                    }
                    $slot['in_use'] = false;
                    $slot['last_used'] = time();
                    return;
                }
            }
            // 连接不在池中（可能已被清理），移除失效索引
            unset($this->globalConnectionRegistry[$oid]);
        }

        // Fallback：遍历所有 shard 查找主库连接或遗留的从库连接
        foreach ($this->shardPools as $shard => $pool) {
            // 主库连接（FPM 模式）
            if ($pool->master === $conn) {
                if ($this->inTransaction($shard)) {
                    return;
                }
                if ($conn->inTransaction()) {
                    try {
                        $conn->rollBack();
                    } catch (\Throwable $e) {
                        $this->logError("DB rollback failed on release", ['exception' => $e]);
                    }
                }
                $pool->master = null;
                unset($pool->connectionRegistry[spl_object_id($conn)]);
                return;
            }
        }
    }

    /** 预热 **/
    public function preWarm(int $num, string $shard = ''): void
    {
        $pool = $this->getShardPool($shard);
        if (empty($pool->nodes)) {
            return;
        }

        $totalWeight = 0;
        foreach ($pool->nodes as $node) {
            $totalWeight += $node->weight;
        }

        foreach ($pool->nodes as $node) {
            $alloc = max(1, (int) round($num * $node->weight / $totalWeight));
            for ($i = 0; $i < $alloc; ++$i) {
                if (count($pool->connections) >= $this->maxConnections) {
                    break 2;
                }
                $pdo = $this->createSlave($shard);
                $this->releaseConnection($pdo);
            }
        }

        // 预热 master 连接，防止高并发写场景下连接风暴
        for ($i = 0; $i < $num; ++$i) {
            if (count($pool->connections) >= $this->maxConnections) {
                break;
            }
            $this->createSlave($shard, 'master');
        }
    }

    /** 关闭全部 **/
    public function closeAll(): void
    {
        foreach ($this->shardPools as $shard => $pool) {
            $pool->master = null;
            foreach ($pool->connections as &$slot) {
                $slot['connection'] = null;
            }
            $pool->connections = [];
            $pool->connectionRegistry = [];
            // 重置节点统计，防止计数器漂移
            foreach ($pool->nodes as $node) {
                $node->activeConnections = 0;
                $node->totalConnections = 0;
                $node->activeQueries = 0;
                $node->errorCount = 0;
                $node->fusedUntil = 0;
            }
        }
        $this->globalConnectionRegistry = [];
    }

    /** 调整连接池大小 **/
    public function adjustPoolSize(string $shard = ''): void
    {
        $pool = $this->getShardPool($shard);
        $util = $this->calcUtilization($shard);
        $count = count($pool->connections) + $this->establishingConnections;

        if ($util > $this->adjustThreshold && $count < $this->maxConnections) {
            $pdo = $this->createSlave($shard);
            $this->releaseConnection($pdo);
        } elseif ($util < ($this->adjustThreshold * 0.5) && $count > $this->minConnections) {
            $oldestIdx = -1;
            $oldestTime = time();
            foreach ($pool->connections as $i => $slot) {
                if (!$slot['in_use'] && $slot['last_used'] < $oldestTime) {
                    $oldestTime = $slot['last_used'];
                    $oldestIdx = $i;
                }
            }
            if ($oldestIdx !== -1) {
                $node = $this->findNode($shard, $pool->connections[$oldestIdx]['node_id'] ?? '');
                if ($node) {
                    $node->activeConnections = max(0, $node->activeConnections - 1);
                    $node->totalConnections = max(0, $node->totalConnections - 1);
                }
                $conn = $pool->connections[$oldestIdx]['connection'] ?? null;
                if ($conn instanceof \PDO) {
                    unset($pool->connectionRegistry[spl_object_id($conn)]);
                    unset($this->globalConnectionRegistry[spl_object_id($conn)]);
                }
                $pool->connections[$oldestIdx]['connection'] = null;
                unset($pool->connections[$oldestIdx]);
                $pool->connections = array_values($pool->connections);
            }
        }
    }

    /**
     * 检测池中所有从连接的健康状态，并尝试恢复失效连接
     */
    public function checkAndRecoverConnections(string $shard = ''): void
    {
        $now = time();
        $pool = $this->getShardPool($shard);

        foreach ($pool->connections as $key => $connData) {
            $pdo = $connData['connection'];
            if (!($pdo instanceof \PDO)) {
                unset($pool->connections[$key]);
                continue;
            }
            $inUse = $connData['in_use'] ?? false;
            $lastUsed = $connData['last_used'] ?? 0;
            $nodeId = $connData['node_id'] ?? '';

            if (!$this->validateConnection($pdo)) {
                if (!$inUse || ($now - $lastUsed) > $this->timeout) {
                    $pdo = null;
                    unset($pool->connections[$key]);
                    if ($nodeId) {
                        $node = $this->findNode($shard, $nodeId);
                        if ($node) {
                            $node->activeConnections = max(0, $node->activeConnections - 1);
                            $node->totalConnections = max(0, $node->totalConnections - 1);
                        }
                    }
                    unset($pool->connectionRegistry[spl_object_id($connData['connection'])]);
                    unset($this->globalConnectionRegistry[spl_object_id($connData['connection'])]);
                    continue;
                }

                $oid = spl_object_id($connData['connection']);
                $pool->connections[$key]['connection'] = null;
                unset($pool->connections[$key]);
                if ($nodeId) {
                    $node = $this->findNode($shard, $nodeId);
                    if ($node) {
                        $node->activeConnections = max(0, $node->activeConnections - 1);
                        $node->totalConnections = max(0, $node->totalConnections - 1);
                    }
                }
                unset($pool->connectionRegistry[$oid]);
                unset($this->globalConnectionRegistry[$oid]);
                $this->attemptConnectionRecovery($nodeId, $shard);
            }
        }

        $pool->connections = array_values($pool->connections);
    }

    /**
     * 尝试重建单个失效连接
     */
    protected function attemptConnectionRecovery(string $nodeId, string $shard = ''): void
    {
        $shardPool = $this->getShardPool($shard);
        if (count($shardPool->connections) >= $this->maxConnections) {
            return;
        }
        $node = $this->findNode($shard, $nodeId);
        if (!$node) {
            return;
        }

        $retries = 3;

        for ($i = 0; $i < $retries; $i++) {
            try {
                $newPdo = $this->factory->createConnectionFromConfig($node->config);
                if ($this->validateConnection($newPdo)) {
                    $shardPool->connectionRegistry[spl_object_id($newPdo)] = [
                        'node_id'    => $nodeId,
                        'role'       => 'slave',
                        'created_at' => time(),
                    ];
                    $shardPool->connections[] = [
                        'node_id'        => $nodeId,
                        'connection'     => $newPdo,
                        'last_used'      => time(),
                        'in_use'         => false,
                        'weight'         => $node->weight,
                        'active_queries' => 0,
                    ];
                    $this->globalConnectionRegistry[spl_object_id($newPdo)] = ['shard' => $shard];
                    $node->totalConnections++;
                    return;
                }
            } catch (\Exception $e) {
                $this->logError("Connection recovery for node '{$nodeId}' attempt {$i} failed", ['exception' => $e]);
            }
        }

        $node->fusedUntil = time() + 30;
        $node->errorCount = 0;
    }

    protected function createSlave(string $shard = '', string $role = 'slave'): \PDO
    {
        $this->establishingConnections++;
        try {
            $shardPool = $this->getShardPool($shard);
            $nodes = array_values(array_filter($shardPool->nodes, function (Node $n) {
                return $n->fusedUntil <= time();
            }));

            if ($role === 'slave' && empty($nodes) && !empty($shardPool->nodes)) {
                return $this->getMaster($shard);
            }

            $targetNode = null;
            if ($role === 'slave' && $this->loadBalancer instanceof LoadBalancer\NodeLoadBalancerInterface) {
                $targetNode = $this->loadBalancer->selectNode($nodes);
            }

            if (!$targetNode) {
                $targetNode = $role === 'master' ? null : ($nodes[0] ?? null);
            }

            if ($role === 'master' || !$targetNode) {
                $pdo = $this->driver->createFreshConnection('master', $shard);
                if ($role === 'master') {
                    $shardPool->master = $pdo;
                }
                return $pdo;
            }

            $pdo = $this->factory->createConnectionFromConfig($targetNode->config);

            $shardPool->connectionRegistry[spl_object_id($pdo)] = [
                'node_id'     => $targetNode->nodeId,
                'role'        => $role,
                'created_at'  => time(),
            ];

            $targetNode->activeConnections++;
            $targetNode->totalConnections++;

            $shardPool->connections[] = [
                'node_id'        => $targetNode->nodeId,
                'connection'     => $pdo,
                'last_used'      => time(),
                'in_use'         => true,
                'weight'         => $targetNode->weight,
                'active_queries' => 0,
            ];

            $this->globalConnectionRegistry[spl_object_id($pdo)] = ['shard' => $shard];

            return $pdo;
        } finally {
            $this->establishingConnections--;
        }
    }

    public function reportError(\PDO $connection, \Throwable $e): void
    {
        $oid = spl_object_id($connection);
        if (isset($this->globalConnectionRegistry[$oid])) {
            $shard = $this->globalConnectionRegistry[$oid]['shard'];
            $pool = $this->getShardPool($shard);
            if (isset($pool->connectionRegistry[$oid])) {
                $nodeId = $pool->connectionRegistry[$oid]['node_id'];
                if ($nodeId !== 'master') {
                    $node = $this->findNode($shard, $nodeId);
                    if ($node) {
                        $node->errorCount++;
                        if ($node->errorCount >= 5) {
                            $node->fusedUntil = time() + 30;
                            $node->errorCount = 0;
                            $this->logError("DB node '{$nodeId}' of shard '{$shard}' fused.", ['node_id' => $nodeId, 'shard' => $shard]);
                        }
                    }
                }
                return;
            }
        }

        // Fallback：遍历所有 shard
        foreach ($this->shardPools as $shard => $pool) {
            if (isset($pool->connectionRegistry[$oid])) {
                $nodeId = $pool->connectionRegistry[$oid]['node_id'];
                if ($nodeId !== 'master') {
                    $node = $this->findNode($shard, $nodeId);
                    if ($node) {
                        $node->errorCount++;
                        if ($node->errorCount >= 5) {
                            $node->fusedUntil = time() + 30;
                            $node->errorCount = 0;
                            $this->logError("DB node '{$nodeId}' of shard '{$shard}' fused.", ['node_id' => $nodeId, 'shard' => $shard]);
                        }
                    }
                }
                return;
            }
        }
    }

    public function trackQueryStart(\PDO $connection): void
    {
        $oid = spl_object_id($connection);
        if (isset($this->globalConnectionRegistry[$oid])) {
            $shard = $this->globalConnectionRegistry[$oid]['shard'];
            $pool = $this->getShardPool($shard);
            foreach ($pool->connections as &$slot) {
                if ($slot['connection'] === $connection) {
                    $slot['active_queries'] = ($slot['active_queries'] ?? 0) + 1;
                    return;
                }
            }
            return;
        }

        // Fallback：遍历所有 shard
        foreach ($this->shardPools as $shard => $pool) {
            foreach ($pool->connections as &$slot) {
                if ($slot['connection'] === $connection) {
                    $slot['active_queries'] = ($slot['active_queries'] ?? 0) + 1;
                    return;
                }
            }
        }
    }

    public function trackQueryEnd(\PDO $connection): void
    {
        $oid = spl_object_id($connection);
        if (isset($this->globalConnectionRegistry[$oid])) {
            $shard = $this->globalConnectionRegistry[$oid]['shard'];
            $pool = $this->getShardPool($shard);
            foreach ($pool->connections as &$slot) {
                if ($slot['connection'] === $connection) {
                    $slot['active_queries'] = max(0, ($slot['active_queries'] ?? 0) - 1);
                    if ($slot['node_id']) {
                        $node = $this->findNode($shard, $slot['node_id']);
                        if ($node && $node->fusedUntil <= time()) {
                            $node->errorCount = 0;
                        }
                    }
                    return;
                }
            }
            return;
        }

        // Fallback：遍历所有 shard
        foreach ($this->shardPools as $shard => $pool) {
            foreach ($pool->connections as &$slot) {
                if ($slot['connection'] === $connection) {
                    $slot['active_queries'] = max(0, ($slot['active_queries'] ?? 0) - 1);
                    if ($slot['node_id']) {
                        $node = $this->findNode($shard, $slot['node_id']);
                        if ($node && $node->fusedUntil <= time()) {
                            $node->errorCount = 0;
                        }
                    }
                    return;
                }
            }
        }
    }

    protected function calcUtilization(string $shard = ''): float
    {
        $pool = $this->getShardPool($shard);
        $total = count($pool->connections);
        if ($total === 0) return 0.0;
        $busy = 0;
        foreach ($pool->connections as $slot) {
            if ($slot['in_use']) $busy++;
        }
        return $busy / $total;
    }

    public function evictCurrentConnection(string $shard = ''): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $masters = $ctx->dbMasters ?? [];
            if ($shard !== '') {
                if (isset($masters[$shard])) {
                    $pdo = $masters[$shard];
                    $pool = $this->getShardPool($shard);
                    if ($pool->master === $pdo) $pool->master = null;
                    unset($pool->connectionRegistry[spl_object_id($pdo)]);
                    unset($masters[$shard]);
                    $ctx->dbMasters = $masters;
                }
            } else {
                foreach ($masters as $s => $pdo) {
                    $pool = $this->getShardPool($s);
                    if ($pool->master === $pdo) $pool->master = null;
                    unset($pool->connectionRegistry[spl_object_id($pdo)]);
                }
                $ctx->dbMasters = [];
            }
            return;
        }
        if ($shard !== '') {
            $pool = $this->getShardPool($shard);
            if ($pool->master) {
                // force unset regardless of transaction
                unset($pool->connectionRegistry[spl_object_id($pool->master)]);
                $pool->master = null;
            }
        } else {
            foreach ($this->shardPools as $s => $pool) {
                if ($pool->master) {
                    unset($pool->connectionRegistry[spl_object_id($pool->master)]);
                    $pool->master = null;
                }
            }
        }
    }

    protected function getConnectionCount(string $shard): int
    {
        return count($this->getShardPool($shard)->connections);
    }

    public function stats(): array
    {
        $out = [];
        foreach ($this->shardPools as $shard => $pool) {
            $total = count($pool->connections);
            $busy = 0;
            foreach ($pool->connections as $slot) {
                if ($slot['in_use']) $busy++;
            }
            $out[$shard] = [
                'total_requests' => $this->totalRequests,
                'successful' => $this->successful,
                'failed' => $this->failed,
                'connections_total' => $total,
                'connections_active' => $busy,
                'connections_idle' => $total - $busy,
                'utilization' => $total > 0 ? $busy / $total : 0.0,
            ];
        }
        return $out;
    }
}
