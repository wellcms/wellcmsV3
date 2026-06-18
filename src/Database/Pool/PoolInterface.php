<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

interface PoolInterface
{
    // 读写分离
    public function getConnection(string $role = 'slave', string $shard = ''): \PDO;
    public function releaseConnection(\PDO $connection): void;

    // 生命周期
    public function preWarm(int $num, string $shard = ''): void;
    public function closeAll(): void;

    // 动态扩缩容
    public function adjustPoolSize(string $shard = ''): void;

    // 事务（均走主库）
    public function beginTransaction(string $shard = ''): bool;
    public function commit(string $shard = ''): bool;
    public function rollback(string $shard = ''): bool;
    public function inTransaction(string $shard = ''): bool;
    public function getTransactionLevel(string $shard = ''): int;

    // 健康与告警
    public function checkHealth(string $shard = ''): array;
    public function monitor(): void;

    // 工业级增强：故障上报与负载追踪
    public function reportError(\PDO $connection, \Throwable $e): void;
    public function trackQueryStart(\PDO $connection): void;
    public function trackQueryEnd(\PDO $connection): void;

    // 紧急修复：仅驱逐当前失效连接（避免 handleConnectionLoss 清空全池）
    public function evictCurrentConnection(string $shard = ''): void;

    // 监控指标
    public function stats(): array;
}
