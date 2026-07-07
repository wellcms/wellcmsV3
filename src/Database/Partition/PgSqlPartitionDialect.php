<?php
declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

/**
 * PostgreSQL 12+ 分区方言实现。
 *
 * 使用 PG 声明式分区实现 RANGE 分区，通过 3 层架构模拟 MySQL 的
 * SUBPARTITION BY HASH：
 *   顶级表（RANGE） → 中间分区表（HASH） → 叶子分区表
 *
 * PHP 7.2 兼容：不使用 typed properties / union types。
 * 实现类无状态，可安全单例共享（Swoole 协程安全）。
 */
class PgSqlPartitionDialect implements PartitionDialectInterface
{
    /**
     * {@inheritdoc}
     */
    public function compilePartitionsQuery(string $fullTableName): string
    {
        $safe = $this->quoteLiteral($fullTableName);
        return "
            SELECT
                p.relname AS partition_name,
                pg_get_expr(c.relpartbound, c.oid) AS partition_description,
                pt.seq AS partition_ordinal_position
            FROM pg_class c
            CROSS JOIN LATERAL pg_partition_tree(c.relname::regclass) pt
            JOIN pg_class p ON p.oid = pt.relid
            WHERE c.relname = {$safe}
              AND pt.isleaf = false
              AND pt.level = 1
            ORDER BY pt.seq ASC
        ";
    }

    /**
     * {@inheritdoc}
     */
    public function compileCreatePartition(
        string $fullTableName,
        int $prevBoundary,
        string $partitionName,
        int $boundary,
        PartitionConfig $config
    ): array {
        $sqls = [];
        $safeMain = $this->quoteIdentifier($fullTableName);
        $safePart = $this->quoteIdentifier($partitionName);

        if ($config->subPartitions > 0 && $config->subPartitionColumn !== null) {
            $subCol = $this->quoteIdentifier($config->subPartitionColumn);
            $n = $config->subPartitions;

            // Step 1: 创建中间 HASH 分区表（RANGE 分区 + HASH 子分区）
            $sqls[] = sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s '
                . 'FOR VALUES FROM (%d) TO (%d) '
                . 'PARTITION BY HASH (%s)',
                $safePart, $safeMain, $prevBoundary, $boundary, $subCol
            );

            // Step 2: 创建 N 个叶子分区
            for ($i = 0; $i < $n; $i++) {
                $leafName = $this->quoteIdentifier($partitionName . '_s' . $i);
                $sqls[] = sprintf(
                    'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s '
                    . 'FOR VALUES WITH (modulus %d, remainder %d)',
                    $leafName, $safePart, $n, $i
                );
            }
        } else {
            $sqls[] = sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s '
                . 'FOR VALUES FROM (%d) TO (%d)',
                $safePart, $safeMain, $prevBoundary, $boundary
            );
        }

        return $sqls;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropPartitions(
        string $fullTableName,
        array $partitionNames
    ): array {
        $sqls = [];
        foreach ($partitionNames as $name) {
            $safeName = $this->quoteIdentifier($name);
            // 单步 DROP + CASCADE：
            // - 自动解除父子分区关系（等效于先 DETACH）
            // - 级联删除所有叶子子分区（_s0~_sN）
            // - 无 DETACH+DROP 两步之间的中间态风险
            $sqls[] = sprintf('DROP TABLE IF EXISTS %s CASCADE', $safeName);
        }
        return $sqls;
    }

    /**
     * {@inheritdoc}
     */
    public function compileSessionSetup(): array
    {
        return [
            "SET SESSION lock_timeout = '10s'",
            "SET SESSION statement_timeout = '10s'",
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * {@inheritdoc}
     */
    public function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
