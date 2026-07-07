<?php
declare(strict_types=1);

namespace Framework\Database\Partition;

use Framework\Database\Interfaces\DatabaseInterface;

/**
 * 分区方言工厂。
 *
 * 职责：根据数据库配置或 PDO 连接创建对应的分区方言实现。
 * 解除 PartitionManager / PartitionRegistry 与具体驱动的耦合。
 *
 * PHP 7.2 兼容：不使用 typed properties / union types。
 */
class PartitionDialectFactory
{
    /**
     * 从数据库配置创建方言实例。
     *
     * @param array $dbConfig config/database.php 中的数据库配置
     * @return PartitionDialectInterface
     * @throws \RuntimeException 驱动不支持时抛出
     */
    public static function createFromConfig(array $dbConfig): PartitionDialectInterface
    {
        $driver = isset($dbConfig['driver']) ? $dbConfig['driver'] : 'mysql';

        if ($driver === 'mysql') {
            return new MySqlPartitionDialect();
        }

        if ($driver === 'pgsql') {
            return new PgSqlPartitionDialect();
        }

        throw new \RuntimeException(sprintf(
            'Unsupported partition driver: %s. Supported: mysql, pgsql.',
            $driver
        ));
    }

    /**
     * 从数据库连接自动检测创建方言实例（开发/测试/CLI 兜底）。
     *
     * @param DatabaseInterface $db
     * @return PartitionDialectInterface
     * @throws \RuntimeException 驱动不支持时抛出
     */
    public static function createFromConnection(DatabaseInterface $db): PartitionDialectInterface
    {
        $pdo = $db->createFreshConnection('master');
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            return new MySqlPartitionDialect();
        }

        if ($driver === 'pgsql') {
            return new PgSqlPartitionDialect();
        }

        throw new \RuntimeException(sprintf(
            'Unsupported partition PDO driver: %s. Supported: mysql, pgsql.',
            $driver
        ));
    }
}
