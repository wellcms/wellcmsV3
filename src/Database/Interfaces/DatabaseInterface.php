<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Interfaces;

use PDO;

interface DatabaseInterface
{
    public function connect(string $role = 'master', string $shard = ''): PDO;

    /**
     * 为连接池提供物理上的全新连接，绕过驱动内部缓存。
     *
     * @param string $role
     * @param string $shard
     * @return PDO
     */
    public function createFreshConnection(string $role = 'master', string $shard = ''): PDO;

    /** 关闭所有连接，释放资源 */
    public function close(): void;

    // --- 读写操作 ---
    /**
     * @return void
     */
    public function insert(string $table, array $data);
    public function bulkInsert(string $table, array $rows): int;

    public function queryOne(string $table, array $where = [], array $orderBy = [], array $fields = ['*']): array;
    public function query(string $table, array $where = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array;
    /**
     * 更新记录
     *
     * @return int WHERE 条件匹配到的行数（值 >= 0）。
     *              注意：执行失败时抛异常，不会返回 false。
     *              所有驱动（MySQL/PgSQL/SQLite/SQL Server/Oracle）
     *              均返回匹配行数，行为已统一。
     */
    public function update(string $table, array $where, array $data): int;

    /**
     * 批量更新
     *
     * @return int 匹配到的总行数（值 >= 0）。执行失败抛异常。
     */
    public function bulkUpdate(string $table, array $rows, string $keyColumn = 'id', array $wheres = []): int;

    /**
     * 删除
     *
     * @return int WHERE 条件匹配到的行数（值 >= 0）。执行失败抛异常。
     */
    public function delete(string $table, array $where): int;
    public function count(string $table, array $where = []): int;
    public function maxid(string $table, string $field = '*', array $where = []): int;
    /**
     * @param array $tables
     */
    public function truncate($tables): int;
    public function isSupportInnodb(string $table = ''): bool;
    public function exec(string $sql): int;

    // --- 事务管理 ---
    public function beginTransaction(string $shard = ''): bool;
    public function commit(string $shard = ''): bool;
    public function rollback(string $shard = ''): bool;
    public function inTransaction(string $shard = ''): bool;
    public function transaction(callable $callback, int $maxAttempts = 3, string $shard = '');

    // --- 分片 & 隔离级别 ---
    public function setIsolationLevel(string $level, string $shard = ''): void;

    /** 获取数据库服务器版本 */
    public function version(string $role = 'master', string $shard = ''): string;
}
