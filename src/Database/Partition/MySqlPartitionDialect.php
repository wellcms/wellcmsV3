<?php
declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

/**
 * MySQL 分区方言实现。
 *
 * PHP 7.2 兼容：不使用 typed properties / union types。
 * 实现类无状态，可安全单例共享（Swoole 协程安全）。
 */
class MySqlPartitionDialect implements PartitionDialectInterface
{
    /**
     * {@inheritdoc}
     */
    public function compilePartitionsQuery(string $fullTableName): string
    {
        $safe = $this->quoteLiteral($fullTableName);
        return "SELECT PARTITION_NAME, PARTITION_DESCRIPTION, "
             . "MIN(PARTITION_ORDINAL_POSITION) AS PARTITION_ORDINAL_POSITION "
             . "FROM information_schema.PARTITIONS "
             . "WHERE TABLE_SCHEMA = DATABASE() "
             . "  AND TABLE_NAME = {$safe} "
             . "  AND PARTITION_NAME IS NOT NULL "
             . "GROUP BY PARTITION_NAME, PARTITION_DESCRIPTION "
             . "ORDER BY MIN(PARTITION_ORDINAL_POSITION) ASC";
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
        $safeTable = $this->quoteIdentifier($fullTableName);
        $safeName  = $this->quoteIdentifier($partitionName);

        if ($config->subPartitions > 0) {
            $newSubs  = $this->buildSubpartitionDefs($partitionName, $config->subPartitions);
            $pmaxSubs = $this->buildSubpartitionDefs('pmax', $config->subPartitions);
            return [sprintf(
                'ALTER TABLE %s REORGANIZE PARTITION pmax INTO ('
                . 'PARTITION %s VALUES LESS THAN (%d) (%s), '
                . 'PARTITION pmax VALUES LESS THAN MAXVALUE (%s)'
                . ')',
                $safeTable, $safeName, $boundary, $newSubs, $pmaxSubs
            )];
        }

        return [sprintf(
            'ALTER TABLE %s REORGANIZE PARTITION pmax INTO ('
            . 'PARTITION %s VALUES LESS THAN (%d), '
            . 'PARTITION pmax VALUES LESS THAN MAXVALUE'
            . ')',
            $safeTable, $safeName, $boundary
        )];
    }

    /**
     * {@inheritdoc}
     */
    public function compileDropPartitions(
        string $fullTableName,
        array $partitionNames
    ): array {
        $safeTable = $this->quoteIdentifier($fullTableName);
        $names = array_map(function ($n) {
            return $this->quoteIdentifier($n);
        }, $partitionNames);

        return [sprintf(
            'ALTER TABLE %s DROP PARTITION %s',
            $safeTable,
            implode(', ', $names)
        )];
    }

    /**
     * {@inheritdoc}
     */
    public function compileSessionSetup(): array
    {
        return [
            "SET SESSION lock_wait_timeout = 10",
            "SET SESSION innodb_lock_wait_timeout = 10",
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * {@inheritdoc}
     */
    public function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * 构建子分区定义字符串。
     *
     * @param string $parentName  父分区名
     * @param int    $subCount    子分区数
     * @return string 如 "SUBPARTITION `p2027Q1_sp0`, SUBPARTITION `p2027Q1_sp1`, ..."
     */
    private function buildSubpartitionDefs(string $parentName, int $subCount): string
    {
        $parts = [];
        for ($i = 0; $i < $subCount; $i++) {
            $parts[] = sprintf(
                'SUBPARTITION %s',
                $this->quoteIdentifier($parentName . '_sp' . $i)
            );
        }
        return implode(', ', $parts);
    }
}
