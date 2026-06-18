<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

use Framework\Database\Interfaces\DatabaseInterface;
use Framework\Cache\Interfaces\CacheInterface;

/**
 * 分区注册表管理。
 *
 * 职责：
 * 1. 存储已注册表的配置清单（缓存驱动持久化）
 * 2. 首次运行时扫描 information_schema 发现存量分区表，补充注册
 * 3. 提供清单给 PartitionManager::maintain() 遍历
 *
 * 设计原理：
 * - 不新增数据库表来存储注册信息（避免"DDL 管理 DDL"的递归）
 * - 注册信息本质上是编译时声明，非运行时数据
 * - 缓存丢失时通过 discoverExisting() 从 information_schema 自动恢复
 *
 * PHP 7.2 兼容：不使用 typed properties / union types / named arguments。
 *
 * 线程安全说明（Swoole）：
 * - register() 只在 install.php 中调用（非请求生命周期），协程安全
 * - getAll() 在请求中读取缓存，协程间仅共享缓存连接，无写冲突
 */
class PartitionRegistry
{
    const CACHE_KEY = 'partition_registry_configs';

    /** @var CacheInterface */
    private $cache;

    /** @var DatabaseInterface */
    private $db;

    /**
     * @param CacheInterface    $cache
     * @param DatabaseInterface $db
     */
    public function __construct(CacheInterface $cache, DatabaseInterface $db)
    {
        $this->cache = $cache;
        $this->db = $db;
    }

    /**
     * 注册一张表的分区配置。
     * 由插件 install.php 或主程序启动时调用。
     * 幂等：同一 table 重复调用以后者为准。
     *
     * @param PartitionConfig $config
     * @return void
     */
    public function register(PartitionConfig $config)
    {
        $all = $this->loadAll();
        $all[$config->table] = $config;
        $this->saveAll($all);
    }

    /**
     * 获取所有已注册的分区配置。
     *
     * @return array<string, PartitionConfig> 以 table 名为键
     */
    public function getAll(): array
    {
        return $this->loadAll();
    }

    /**
     * 根据表名获取分区配置。
     *
     * @param string $table 纯表名
     * @return PartitionConfig|null
     */
    public function get(string $table)
    {
        $all = $this->loadAll();
        return isset($all[$table]) ? $all[$table] : null;
    }

    /**
     * 注销一张表的分区管理。
     * 由插件 uninstall.php 调用。
     *
     * @param string $table
     * @return void
     */
    public function unregister(string $table)
    {
        $all = $this->loadAll();
        if (isset($all[$table])) {
            unset($all[$table]);
            $this->saveAll($all);
        }
    }

    /**
     * 扫描 information_schema 发现存量 RANGE 分区表，
     * 自动为其创建默认配置。
     * 首次部署 PartitionManager 时用于接管现有分区表。
     *
     * @param string $prefix 表前缀（如 'well_'），用于 information_schema 匹配
     * @return int 发现的存量分区表数量
     */
    public function discoverExisting(string $prefix): int
    {
        $prefixEscaped = str_replace('_', '\\_', $prefix);
        $pdo = $this->db->createFreshConnection('master');

        // 获取当前数据库名
        $stmt = $pdo->query("SELECT DATABASE()");
        $dbName = $stmt->fetchColumn();

        // Step 1: 查询所有 RANGE 分区表（排除纯 HASH）
        // MySQL 5.7 兼容：SUBPARTITION_COUNT 不可用，改用 COUNT DISTINCT 统计子分区
        // COUNT(DISTINCT PARTITION_NAME) 正确统计父分区数，排除子分区行的干扰
        $sql = "SELECT TABLE_NAME, PARTITION_METHOD, SUBPARTITION_METHOD,
                       COUNT(DISTINCT PARTITION_NAME) AS total_par,
                       SUM(CASE WHEN SUBPARTITION_NAME IS NOT NULL THEN 1 ELSE 0 END) AS total_sub
                FROM information_schema.PARTITIONS
                WHERE TABLE_SCHEMA = " . $pdo->quote($dbName) . "
                  AND TABLE_NAME LIKE " . $pdo->quote($prefixEscaped . '%') . "
                  AND PARTITION_METHOD IS NOT NULL
                  AND PARTITION_METHOD IN ('RANGE', 'RANGE COLUMNS')
                GROUP BY TABLE_NAME, PARTITION_METHOD, SUBPARTITION_METHOD";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $count = 0;
        $all = $this->loadAll();

        // PDO 强制列名小写（PDO::CASE_LOWER），所有键名必须用小写
        $colTable = 'table_name';
        $colSubMethod = 'subpartition_method';
        $colTotalPar = 'total_par';
        $colTotalSub = 'total_sub';

        foreach ($rows as $row) {
            $tableName = isset($row[$colTable]) ? $row[$colTable] : null;
            if ($tableName === null) {
                continue;
            }

            // 去掉前缀得到纯表名
            if ($prefix !== '' && strncmp($tableName, $prefix, strlen($prefix)) === 0) {
                $tableName = substr($tableName, strlen($prefix));
            }

            // 跳过已注册的表
            if (isset($all[$tableName])) {
                continue;
            }

            $partitionColumn = 'created_at';

            // 检测子分区（MySQL 5.7 兼容）
            $subPartitionColumn = null;
            $subPartitions = 0;
            if (!empty($row[$colSubMethod])) {
                $totalPar = (int)$row[$colTotalPar];
                $totalSub = (int)$row[$colTotalSub];
                if ($totalPar > 0 && $totalSub > 0) {
                    // subpartition_count = total_sub / total_par（每个父分区下的子分区数）
                    $subPartitions = (int)($totalSub / $totalPar);
                    $subPartitionColumn = 'thread_id';
                }
            }

            $config = new PartitionConfig(
                $tableName,
                $partitionColumn,
                'quarter',
                4,
                8,
                $subPartitionColumn,
                $subPartitions
            );

            $all[$tableName] = $config;
            $count++;
        }

        if ($count > 0) {
            $this->saveAll($all);
        }

        return $count;
    }

    /**
     * 从缓存加载全部配置。
     *
     * @return array<string, PartitionConfig>
     */
    private function loadAll(): array
    {
        $data = $this->cache->get(self::CACHE_KEY);
        if (!is_array($data)) {
            return array();
        }
        // 缓存驱动可能以数组形式返回（JSON 序列化），需要恢复为 PartitionConfig 对象
        foreach ($data as $table => $config) {
            if (is_array($config)) {
                $data[$table] = new PartitionConfig(
                    isset($config['table']) ? $config['table'] : $table,
                    isset($config['partitionColumn']) ? $config['partitionColumn'] : 'created_at',
                    isset($config['period']) ? $config['period'] : 'quarter',
                    isset($config['advanceCount']) ? (int)$config['advanceCount'] : 4,
                    isset($config['retention']) ? (int)$config['retention'] : 8,
                    isset($config['subPartitionColumn']) ? $config['subPartitionColumn'] : null,
                    isset($config['subPartitions']) ? (int)$config['subPartitions'] : 0
                );
            }
        }
        return $data;
    }

    /**
     * 将全部配置持久化到缓存。
     *
     * @param array<string, PartitionConfig> $configs
     * @return void
     */
    private function saveAll(array $configs)
    {
        // TTL 设为 0 表示永不过期（依赖缓存驱动的 LRU 淘汰策略）
        $this->cache->set(self::CACHE_KEY, $configs, 0);
    }
}
