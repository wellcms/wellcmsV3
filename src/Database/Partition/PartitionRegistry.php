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
 * 1. 存储已注册表的配置清单（宿主 KV 持久化，缓存仅用于加速）
 * 2. 首次运行时扫描 information_schema 发现存量分区表，补充注册
 * 3. 提供清单给 PartitionManager::maintain() 遍历
 *
 * 设计原理：
 * - 不新增数据库表来存储注册信息（避免"DDL 管理 DDL"的递归）
 * - 注册信息本质上是编译时声明，非运行时数据
 * - 缓存丢失时通过 discoverExisting() 发现物理表，优先使用宿主提供的准确声明
 * - 声明和完整登记均缺失时明确失败，禁止从物理目录猜测保留期限等政策
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

    /** 物理分区缓存与 DDL 失效代际；旧代数据由 TTL 回收。 */
    private const PHYSICAL_KEY = 'partition:physical:';

    public function readPhysicalPartitions(string $fullTable, callable $reader): array
    {
        $generation = (string)$this->cache->get(self::PHYSICAL_KEY . $fullTable . ':generation', '');
        $key = self::PHYSICAL_KEY . $fullTable . ':rows:' . $generation;
        $partitions = $this->cache->get($key);
        if (is_array($partitions)) return $partitions;

        $partitions = $reader();
        $this->cache->set($key, $partitions, 86400);
        return $partitions;
    }

    public function invalidatePhysicalPartitions(string $fullTable): void
    {
        // 读取与 DDL 可能并发；旧查询完成后只能写入旧代，不能覆盖新代。
        $this->cache->set(self::PHYSICAL_KEY . $fullTable . ':generation', bin2hex(random_bytes(8)));
    }

    public function invalidateAllPhysicalPartitions(): void
    {
        foreach ($this->getAll() as $config) {
            $this->invalidatePhysicalPartitions($this->db->prefix . $config->table);
        }
    }

    /** @var CacheInterface */
    private $cache;

    /** @var DatabaseInterface */
    private $db;

    /** @var PartitionDialectInterface */
    private $dialect;

    /** @var callable|null 冷发现时返回 array<string, PartitionConfig>；不在构造/普通读取执行 */
    private $declaredConfigProvider;

    /** @var callable|null 宿主持久配置读取，未保存时返回 null */
    private $configReader;

    /** @var callable|null 宿主持久配置写入，失败必须抛异常 */
    private $configWriter;

    /**
     * @param CacheInterface                 $cache
     * @param DatabaseInterface              $db
     * @param PartitionDialectInterface|null $dialect 分区方言（null 时自动检测）
     * @param callable|null                 $declaredConfigProvider 宿主的被动声明来源
     * @param callable|null                 $configReader 读取持久化数组，缺失返回 null
     * @param callable|null                 $configWriter 写入持久化数组，失败抛异常
     */
    public function __construct(
        CacheInterface $cache,
        DatabaseInterface $db,
        ?PartitionDialectInterface $dialect = null,
        ?callable $declaredConfigProvider = null,
        ?callable $configReader = null,
        ?callable $configWriter = null
    ) {
        if (($configReader === null) !== ($configWriter === null)) {
            throw new \InvalidArgumentException('Partition persistence requires both reader and writer');
        }
        $this->configReader = $configReader;
        $this->configWriter = $configWriter;
        $this->cache = $cache;
        $this->db = $db;
        $this->dialect = $dialect ?? PartitionDialectFactory::createFromConnection($db);
        $this->declaredConfigProvider = $declaredConfigProvider;
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
        $this->validateConfig($config, $config->table);
        $all = $this->loadAll();
        $all[$config->table] = $config;
        $this->saveAll($all);
        $this->invalidatePhysicalPartitions($this->db->prefix . $config->table);
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
        $this->invalidatePhysicalPartitions($this->db->prefix . $table);
        $all = $this->loadAll();
        if (isset($all[$table])) {
            unset($all[$table]);
            $this->saveAll($all);
        }
    }

    /**
     * 从实际主库核验分区结构，按完整声明或持久登记恢复策略。
     * 不从物理结构猜测周期、预建数或删除期限；整批核验通过才保存。
     * $table 非 null 时只核验指定表，供单表维护使用。
     */
    public function discoverExisting(string $prefix, ?string $table = null): int
    {
        $provider = $this->declaredConfigProvider;
        $declared = $provider === null ? array() : $provider();
        $repairs = $table === null ? $declared : array_intersect_key($declared, array($table => true));
        $all = $this->loadAll($repairs);
        $scopes = array();
        if ($table !== null) {
            $scopes[$this->db->resolveShard($table)] = array($table);
        } else {
            $scopes[''] = array();
            foreach (array_keys($all + $declared) as $name) {
                $shard = $this->db->resolveShard($name);
                if ($shard !== '') $scopes[$shard][] = $name;
            }
        }

        $count = 0;
        foreach ($scopes as $shard => $tables) {
            $excluded = array();
            if ($shard === '' && $table === null) {
                foreach ($scopes as $owner => $ownedTables) {
                    if ($owner !== '') $excluded = array_merge($excluded, $ownedTables);
                }
            }
            $pdo = $this->db->createFreshConnection('master', $shard);
            foreach ($this->readPartitionMetadata($pdo, $prefix, $tables, $excluded) as $row) {
                $fullName = $row['table_name'] ?? null;
                if (!is_string($fullName) || $fullName === '' || strncmp($fullName, $prefix, strlen($prefix)) !== 0) {
                    throw new \RuntimeException('Partition metadata has no valid prefixed table name');
                }
                $name = substr($fullName, strlen($prefix));
                if (!in_array($row['partition_method'] ?? '', array('RANGE', 'RANGE COLUMNS'), true)) {
                    throw new \RuntimeException('Unsupported time-partition method for ' . $name . ': ' . (string)($row['partition_method'] ?? 'missing'));
                }
                if ($this->db->resolveShard($name) !== $shard) {
                    throw new \RuntimeException('Partition table is on an unexpected master: ' . $name);
                }
                $config = $declared[$name] ?? $all[$name] ?? null;
                if ($config === null) {
                    throw new \RuntimeException('Partition policy unresolved for ' . $name
                        . ': provide partitionColumn, period, advanceCount, retention and subpartition policy');
                }
                $this->validateConfig($config, $name);
                $this->validateStructure($row, $config);
                if (!isset($all[$name]) || get_object_vars($all[$name]) !== get_object_vars($config)) {
                    $all[$name] = $config;
                    $count++;
                }
            }
        }
        // 包含旧缓存的首次迁移；未知表/冲突/查询错误均在此之前失败，不发布部分结果。
        if ($all) $this->saveAll($all);
        return $count;
    }

    /** 两种数据库返回同一结构合同；只读取当前 schema 的 RANGE 根表。 */
    private function readPartitionMetadata(\PDO $pdo, string $prefix, array $tables, array $excluded): array
    {
        $pattern = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $prefix) . '%';
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tableColumn = $driver === 'pgsql' ? 'c.relname' : 'p.TABLE_NAME';
        $filter = $tableColumn . ' LIKE ' . $pdo->quote($pattern);
        if ($tables) {
            $names = array();
            foreach ($tables as $name) $names[] = $pdo->quote($prefix . $name);
            $filter .= ' AND ' . $tableColumn . ' IN (' . implode(', ', $names) . ')';
        }
        if ($excluded) {
            // 整表绑定后的默认库同名旧副本不属于当前路由，实际归属节点仍须逐表核验。
            $names = array();
            foreach ($excluded as $name) $names[] = $pdo->quote($prefix . $name);
            $filter .= ' AND ' . $tableColumn . ' NOT IN (' . implode(', ', $names) . ')';
        }
        if ($driver === 'pgsql') {
            $rangeFilter = $tables ? '' : " AND pt.partstrat = 'r'";
            $sql = "SELECT c.relname AS table_name,
                        CASE pt.partstrat WHEN 'r' THEN 'RANGE' WHEN 'h' THEN 'HASH' WHEN 'l' THEN 'LIST' ELSE 'UNSUPPORTED' END AS partition_method,
                        a.attname AS partition_expression, t.typname AS partition_type,
                        CASE WHEN child.sub_parents = 0 THEN NULL
                             WHEN child.hash_parents = child.total_par THEN 'HASH'
                             ELSE 'UNSUPPORTED' END AS subpartition_method,
                        child.sub_expression AS subpartition_expression,
                        child.max_sub_expression AS max_subpartition_expression,
                        child.total_par, child.min_sub, child.max_sub
                    FROM pg_class c
                    JOIN pg_namespace ns ON ns.oid = c.relnamespace
                    JOIN pg_partitioned_table pt ON pt.partrelid = c.oid
                    LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = pt.partattrs[0]
                        AND pt.partnatts = 1 AND pt.partexprs IS NULL
                    LEFT JOIN pg_type t ON t.oid = a.atttypid
                    LEFT JOIN LATERAL (
                        SELECT COUNT(*) AS total_par, COUNT(h.partrelid) AS sub_parents,
                            COUNT(*) FILTER (WHERE h.partstrat = 'h' AND h.partnatts = 1 AND h.partexprs IS NULL) AS hash_parents,
                            MIN(ha.attname) AS sub_expression, MAX(ha.attname) AS max_sub_expression,
                            MIN((SELECT COUNT(*) FROM pg_inherits sub WHERE sub.inhparent = i.inhrelid)) AS min_sub,
                            MAX((SELECT COUNT(*) FROM pg_inherits sub WHERE sub.inhparent = i.inhrelid)) AS max_sub
                        FROM pg_inherits i
                        LEFT JOIN pg_partitioned_table h ON h.partrelid = i.inhrelid
                        LEFT JOIN pg_attribute ha ON ha.attrelid = h.partrelid AND ha.attnum = h.partattrs[0]
                        WHERE i.inhparent = c.oid
                    ) child ON true
                    WHERE ns.nspname = current_schema() AND c.relispartition = false
                        {$rangeFilter} AND {$filter}";
        } elseif ($driver === 'mysql') {
            $rangeFilter = $tables ? '' : " AND p.PARTITION_METHOD IN ('RANGE', 'RANGE COLUMNS')";
            $sql = "SELECT p.TABLE_NAME, p.PARTITION_METHOD, p.PARTITION_EXPRESSION,
                        col.DATA_TYPE AS partition_type, p.SUBPARTITION_METHOD, p.SUBPARTITION_EXPRESSION,
                        p.SUBPARTITION_EXPRESSION AS max_subpartition_expression,
                        COUNT(DISTINCT p.PARTITION_NAME) AS total_par,
                        SUM(CASE WHEN p.SUBPARTITION_NAME IS NOT NULL THEN 1 ELSE 0 END) AS total_sub
                    FROM information_schema.PARTITIONS p
                    LEFT JOIN information_schema.COLUMNS col ON col.TABLE_SCHEMA = p.TABLE_SCHEMA
                        AND col.TABLE_NAME = p.TABLE_NAME
                        AND col.COLUMN_NAME = REPLACE(p.PARTITION_EXPRESSION, '`', '')
                    WHERE p.TABLE_SCHEMA = DATABASE() AND {$filter}
                        AND p.PARTITION_METHOD IS NOT NULL
                        {$rangeFilter}
                    GROUP BY p.TABLE_NAME, p.PARTITION_METHOD, p.PARTITION_EXPRESSION,
                        col.DATA_TYPE, p.SUBPARTITION_METHOD, p.SUBPARTITION_EXPRESSION";
        } else {
            throw new \RuntimeException('Unsupported partition database driver: ' . $driver);
        }
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 当前维护器只支持整数 Unix 秒单列 RANGE 与可选的单列 HASH 子分区。 */
    private function validateStructure(array $row, PartitionConfig $config): void
    {
        $column = trim((string)($row['partition_expression'] ?? ''), '`');
        $subColumn = trim((string)($row['subpartition_expression'] ?? ''), '`');
        $hasSub = !empty($row['subpartition_method']);
        $parents = (int)($row['total_par'] ?? 0);
        $minimum = isset($row['min_sub']) ? (int)$row['min_sub'] : null;
        $maximum = isset($row['max_sub']) ? (int)$row['max_sub'] : null;
        if (isset($row['total_sub']) && $parents > 0) {
            $total = (int)$row['total_sub'];
            $minimum = $total % $parents === 0 ? intdiv($total, $parents) : -1;
            $maximum = $minimum;
        }
        if (!in_array($row['partition_method'] ?? '', array('RANGE', 'RANGE COLUMNS'), true)
            || !in_array($row['partition_type'] ?? '', array('int', 'bigint', 'int4', 'int8'), true)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)
            || $column !== $config->partitionColumn
            || ($config->subPartitions > 0) !== $hasSub
            || ($hasSub && (($row['subpartition_method'] ?? '') !== 'HASH'
                || $subColumn !== $config->subPartitionColumn
                || ($row['max_subpartition_expression'] ?? '') !== ($row['subpartition_expression'] ?? '')
                || $minimum !== $config->subPartitions || $maximum !== $config->subPartitions))) {
            throw new \RuntimeException('Physical partition layout disagrees with policy for ' . $config->table
                . ': column=' . $column . ', subcolumn=' . $subColumn . ', subcount=' . (string)$minimum);
        }
    }

    /** 外部声明、缓存和 KV 在进入内部维护前统一校验，不补齐缺失政策。 */
    private function validateConfig(PartitionConfig $config, string $table): void
    {
        if ($table === '' || $config->table !== $table || $config->partitionColumn === ''
            || !in_array($config->period, array('quarter', 'month'), true)
            || $config->advanceCount < 1 || $config->retention < 0 || $config->subPartitions < 0
            || $config->subPartitionColumn === ''
            || (($config->subPartitionColumn === null) !== ($config->subPartitions === 0))) {
            throw new \RuntimeException('Invalid partition policy for ' . $table);
        }
    }

    /**
     * 优先从宿主 KV 加载；首次升级接收尚未丢失的缓存策略。
     *
     * @return array<string, PartitionConfig>
     */
    private function loadAll(array $declarations = array()): array
    {
        $data = $this->cache->get(self::CACHE_KEY);
        if ($this->configReader !== null) {
            $stored = ($this->configReader)();
            if ($stored !== null && !is_array($stored)) {
                throw new \RuntimeException('Invalid persisted partition registry');
            }
            if ($stored !== null) {
                $data = $stored;
            }
        }
        if (!is_array($data)) {
            return array();
        }
        // 完整登记只做类型恢复；缺字段不再用默认值冒充原政策。
        foreach ($data as $table => $config) {
            if (is_array($config)) {
                foreach (array('table', 'partitionColumn', 'period', 'advanceCount', 'retention', 'subPartitionColumn', 'subPartitions') as $field) {
                    if (!array_key_exists($field, $config)) {
                        if (isset($declarations[$table])) {
                            // 只允许完整的明确声明修复残缺旧记录，随后仍须核验物理结构。
                            $config = $declarations[$table];
                            break;
                        }
                        throw new \RuntimeException('Incomplete stored partition policy for ' . $table . ': ' . $field);
                    }
                }
                if (is_array($config)) {
                    $config = new PartitionConfig($config['table'], $config['partitionColumn'], $config['period'],
                        $config['advanceCount'], $config['retention'], $config['subPartitionColumn'], $config['subPartitions']);
                }
            }
            if (!$config instanceof PartitionConfig) {
                throw new \RuntimeException('Invalid stored partition policy for ' . $table);
            }
            $this->validateConfig($config, $table);
            $data[$table] = $config;
        }
        return $data;
    }

    /**
     * 先持久化到宿主 KV，再发布缓存。
     *
     * @param array<string, PartitionConfig> $configs
     * @return void
     */
    private function saveAll(array $configs)
    {
        if ($this->configWriter !== null) {
            $data = array();
            foreach ($configs as $table => $config) {
                $data[$table] = is_array($config) ? $config : get_object_vars($config);
            }
            // 写入失败不得发布仅存在于缓存的结构策略。
            ($this->configWriter)($data);
        }
        // 缓存允许丢失；宿主从 KV 恢复完整策略。
        $this->cache->set(self::CACHE_KEY, $configs, 0);
    }
}
