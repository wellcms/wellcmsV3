<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Driver;

use Framework\Logger\LoggerInterface;
use PDO;

/**
 * 通用 PDO 驱动，支持 MySQL、PgSQL、Oracle、SQLite、SQLServer
 * · 主从读写分离自动分发，支持随机或指定从服务器
 * · 分表分库通过 shard 配置支持
 * · 支持持久/非持久连接
 * · 支持版本查询、事务、SQL 调试日志
 */
class PdoDriver implements \Framework\Database\Interfaces\DatabaseInterface, \Framework\Database\Interfaces\ConnectionFactoryInterface
{
    use \Framework\Database\Pool\CoroutineAwareTrait;

    /** @var \Framework\Database\Query\Builder */
    protected $builder;
    /** @var array 主库或单库配置 */
    protected $dbConfig;
    /** @var bool 是否使用持久连接 */
    public $prefix;
    protected /** @var int */
    static $gcCallCount = 0;

    /** @var bool 条件触发 GC */
    protected $conditionalGcEnabled = true;

    /** @var int GC 内存阈值（字节） */
    protected $gcMemoryThreshold = 52428800; // 50MB

    /** @var bool 事务内部标记，Swoole 环境下由于 PoolManager 是单例，此属性仅供 FPM 使用 */
    protected $inTransaction = false;

    /** @var string|null 默认事务隔离级别 */
    protected $defaultIsolationLevel = 'READ COMMITTED';

    /** @var \Framework\Database\Query\Grammar\GrammarInterface */
    protected $grammar;

    /** @var LoggerInterface|null */
    protected $logger;

    /** @var \Framework\Database\Pool\PoolInterface|null 子类 ProxyDriver 注入 */
    protected $pool;

    /** @var array [table => ConsistentHashRouter] */
    protected $shardRouters = [];

    /**
     * 构造
     * /cocnfig/Database.php
     * $dbConfig = array(
     *      'driver' => 'mysql',
     *      'prefix' => 'well_',
     *      'shards' => array(),
     *      'master' => array(
     *          'persistent' => false, // true长连接 false短连接
     *          'host' => '127.0.0.1',
     *          'port' => '3306',
     *          'database' => 'wellcms',
     *          'username' => 'root',
     *          'password' => '',
     *          'charset' => 'utf8mb4',
     *          'collation' => 'utf8mb4_unicode_ci',
     *          'prefix' => 'well_',
     *          'engine' => 'InnoDB',
     *      ),
     *      'slaves' => array(),
     *  );
     *
     * Builder 查询构造器
     */
    public function __construct(array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
        $this->prefix = $dbConfig['prefix'] ?? '';
        $this->grammar = $this->createGrammar($dbConfig['driver'] ?? 'mysql');
        $this->flattenShardingConfig();
        $this->initShardRouters();
    }

    /**
     * 将 config/Database.php 中的 sharding.tables 扁平化为
     * dbConfig['shard_routers'] 和 dbConfig['shards']
     */
    protected function flattenShardingConfig(): void
    {
        if (empty($this->dbConfig['sharding']['tables'])) {
            return;
        }
        foreach ($this->dbConfig['sharding']['tables'] as $table => $tableConfig) {
            $nodes = $tableConfig['nodes'] ?? [];
            if (empty($nodes)) {
                continue;
            }
            $this->dbConfig['shard_routers'][$table] = [
                'shard_key' => $tableConfig['shard_key'] ?? '',
                'nodes' => array_keys($nodes),
            ];
            foreach ($nodes as $shardId => $shardCfg) {
                $this->dbConfig['shards'][$shardId] = $shardCfg;
            }
        }
    }

    /**
     * 初始化分片路由器
     */
    protected function initShardRouters(): void
    {
        foreach ($this->dbConfig['shard_routers'] ?? [] as $table => $cfg) {
            $nodes = $cfg['nodes'] ?? [];
            if (!empty($nodes)) {
                $this->shardRouters[$table] = new \Framework\Database\Sharding\ConsistentHashRouter($nodes);
            }
        }
    }

    /**
     * 根据表名和上下文数据解析目标分片
     *
     * 使用说明：
     * 1. 当 config/Database.php 的 sharding.tables 中未配置该表的分片规则时，
     *    返回空字符串 ''，表示使用 default shard（未分片单库环境）。
     * 2. 当已配置分片规则时，依据 shard_key 和一致性哈希算法返回真实分片节点 ID，
     *    如 'shard_0'、'shard_1'。
     * 3. 事务方法（transaction / beginTransaction 等）的 $shard 参数应与此方法的
     *    返回值保持一致，确保事务绑定到正确的物理数据库节点。
     */
    protected function resolveShard(string $table, array $context = []): string
    {
        if (!isset($this->shardRouters[$table])) {
            return '';
        }
        $shardKey = $this->dbConfig['shard_routers'][$table]['shard_key'] ?? '';
        if (!$shardKey) {
            return '';
        }
        $shardValue = $context[$shardKey] ?? '';
        if ($shardValue === '' || $shardValue === null) {
            throw new \InvalidArgumentException("Missing shard key '{$shardKey}' for sharded table '{$table}'");
        }
        return $this->shardRouters[$table]->route($table, $shardValue);
    }

    /**
     * 批量操作时解析分片，若数据跨分片则抛出异常
     *
     * 使用说明：
     * 1. 与 resolveShard 行为一致，未配置分片时返回 ''。
     * 2. 已配置分片时，会逐行检查 shard_key 值是否全部落在同一节点；
     *    若跨分片则抛出异常，防止批量插入/更新在分布式环境下产生数据不一致。
     */
    protected function resolveBulkShard(string $table, array $rows, string $keyColumn = ''): string
    {
        if (empty($rows) || !isset($this->shardRouters[$table])) {
            return '';
        }
        $shardKey = $this->dbConfig['shard_routers'][$table]['shard_key'] ?? '';
        if (!$shardKey) {
            return '';
        }
        $shards = [];
        $hasEmpty = false;
        foreach ($rows as $row) {
            $shardValue = $row[$shardKey] ?? ($keyColumn && isset($row[$keyColumn]) ? $row[$keyColumn] : '');
            if ($shardValue === '' || $shardValue === null) {
                throw new \InvalidArgumentException("Missing shard key '{$shardKey}' in bulk operations for sharded table '{$table}'");
            }
            $shards[$this->shardRouters[$table]->route($table, $shardValue)] = true;
        }
        if (count($shards) > 1) {
            throw new \RuntimeException("Cross-shard bulk operation is not supported for table '{$table}'");
        }
        return $shards ? key($shards) : $table;
    }

    protected function createGrammar(string $driver): \Framework\Database\Query\Grammar\GrammarInterface
    {
        switch ($driver) {
            case 'mysql':
                return new \Framework\Database\Query\Grammar\MySqlGrammar();
            case 'pgsql':
                return new \Framework\Database\Query\Grammar\PgSqlGrammar();
            case 'sqlsrv':
                return new \Framework\Database\Query\Grammar\SqlServerGrammar();
            case 'oci':
            case 'oracle':
                return new \Framework\Database\Query\Grammar\OracleGrammar();
            case 'sqlite':
                return new \Framework\Database\Query\Grammar\SQLiteGrammar();
            default:
                throw new \InvalidArgumentException("Unsupported driver: {$driver}");
        }
    }

    /**
     * 取连接：$role = 'master'|'slave'; $shard 可选分片标识
     */
    public function connect(string $role = 'master', string $shard = ''): PDO
    {
        // 如果是在协程环境中，且不是由 Pool 调用（为了简单起见，驱动层不再自行深度缓存，
        // 而是利用协程上下文防止单次请求内的重复创建），
        // 但为了支持 Pool 的创建逻辑，我们允许通过上下文标记来强制创建新连接。
        $ctx = self::coroContext();
        $isCoroutine = $ctx !== null;

        // 构建当前请求所需的 DSN 配置
        $cfg = $this->dbConfig;
        if ($shard && isset($cfg['shards'][$shard])) {
            $cfg['shards'][$shard]['driver'] = $cfg['driver'];
            $cfg = $cfg['shards'][$shard];
        }

        if ($role === 'master') {
            $masterCfg = $cfg['master'];
            $masterCfg['driver'] = $cfg['driver'];

            if ($isCoroutine) {
                $cacheKey = "db_master_{$shard}";
                if (!isset($ctx->$cacheKey)) {
                    $ctx->$cacheKey = $this->createPdo($masterCfg);
                    $map = $ctx->dbConnectionMap ?? [];
                    $map[spl_object_id($ctx->$cacheKey)] = $cacheKey;
                    $ctx->dbConnectionMap = $map;
                }
                return $ctx->$cacheKey;
            }

            // FPM 模式下不再使用静态变量缓存连接，防止跨请求污染与连接泄漏
            return $this->createPdo($masterCfg);
        }

        // 从库逻辑：驱动层只负责“返回一个可用连接”，不维护复杂的池逻辑（由 PoolInterface 维护）
        // 这里的逻辑主要为不使用连接池的基础场景提供支持
        $slaves = $cfg['slaves'] ?? [];
        if (empty($slaves)) {
            return $this->connect('master', $shard);
        }

        $sconf = $slaves[array_rand($slaves)];
        $sconf['driver'] = $cfg['driver'];

        return $this->createPdo($sconf);
    }

    /**
     * 专门为连接池提供的工厂方法，绕过任何内部缓存，确保每次返回物理上的新连接
     */
    public function createFreshConnection(string $role = 'master', string $shard = ''): PDO
    {
        $cfg = $this->dbConfig;
        if ($shard && isset($cfg['shards'][$shard])) {
            $cfg['shards'][$shard]['driver'] = $cfg['driver'];
            $cfg = $cfg['shards'][$shard];
        }

        if ($role === 'master') {
            $mcfg = $cfg['master'];
            $mcfg['driver'] = $cfg['driver'];
            return $this->createPdo($mcfg);
        }

        $slaves = $cfg['slaves'] ?? [];
        if (empty($slaves)) {
            return $this->createFreshConnection('master', $shard);
        }

        // 随机选择一个配置进行创建
        $sconf = $slaves[array_rand($slaves)];
        $sconf['driver'] = $cfg['driver'];
        return $this->createPdo($sconf);
    }

    protected function buildDriver(): \Framework\Database\Query\Builder
    {
        return new \Framework\Database\Query\Builder($this->grammar);
    }

    protected function resetBuilder(): void
    {
        $this->builder = $this->buildDriver();
    }

    /**
     * 条件触发 GC，减少内联重复逻辑
     */
    protected function maybeCollectGarbage(): void
    {
        if (!($this->conditionalGcEnabled ?? true)) {
            return;
        }
        if (memory_get_usage(true) <= ($this->gcMemoryThreshold ?? (50 * 1024 * 1024))) {
            return;
        }
        gc_collect_cycles();
        self::$gcCallCount++;
    }

    /**
     * 按原始节点配置创建物理连接，绕过任何缓存与随机选择。
     */
    public function createConnectionFromConfig(array $nodeConfig): PDO
    {
        if (empty($nodeConfig['driver'])) {
            $nodeConfig['driver'] = $this->dbConfig['driver'] ?? 'mysql';
        }
        return $this->createPdo($nodeConfig);
    }

    /**
     * 建立单个 PDO 连接
     */
    public function createPdo(array $cfg): PDO
    {
        $persistent = (bool)($cfg['persistent'] ?? false);
        $driver = strtolower($cfg['driver']);
        $dsn = $this->buildDsn($driver, $cfg);
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => $persistent,
            PDO::ATTR_CASE => PDO::CASE_LOWER, // 强制列名小写，提升跨数据库兼容性
            PDO::ATTR_STRINGIFY_FETCHES => false, // 禁止将数值转为字符串
            PDO::ATTR_EMULATE_PREPARES => false, // 使用原生预处理，利于类型识别
        ];

        false === $persistent && $opts[PDO::ATTR_TIMEOUT] = $cfg['timeout'] ?? 5;

        // ===== Layer 1 修复：MySQL 跨数据库语义归一化 =====
        // MySQL 默认返回 changed rows（值未变时返回 0），与其他数据库的 matched rows 语义不一致。
        // 启用 FOUND_ROWS 使 MySQL 的 rowCount() 返回 WHERE 匹配到的行数，统一全驱动行为。
        // 兼容 PHP 7.2（PDO::MYSQL_ATTR_FOUND_ROWS）至 PHP 8.4+（Pdo\Mysql::ATTR_FOUND_ROWS）。
        if ($driver === 'mysql') {
            $foundRowsAttr = PHP_VERSION_ID >= 80400 && \defined('Pdo\Mysql::ATTR_FOUND_ROWS')
                ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
                : PDO::MYSQL_ATTR_FOUND_ROWS;
            $opts[$foundRowsAttr] = true;
        }
        // =====================================================

        $pdo = new PDO($dsn, $cfg['username'] ?? null, $cfg['password'] ?? null, $opts);

        // Oracle 性能预取优化 (Stage 3 遗漏补充)
        if ($driver === 'oci' || $driver === 'oracle') {
            try {
                $pdo->setAttribute(PDO::ATTR_PREFETCH, $cfg['prefetch'] ?? 100);
            } catch (\PDOException $e) {
                // 部分 PDO 驱动不支持此属性，忽略
            }
        }

        // 初始化环境词法（字符集与校对集）
        $charset = $cfg['charset'] ?? 'utf8mb4';
        $collation = $cfg['collation'] ?? 'utf8mb4_unicode_ci';
        $pdo->exec($this->grammar->compileSetCharset($charset, $collation));

        // 初始化 Schema/搜索路径 (Stage 3 增强)
        $schema = $cfg['schema'] ?? $cfg['search_path'] ?? null;
        if ($schema) {
            $pdo->exec($this->grammar->compileSchema((string)$schema));
        }

        return $pdo;
    }

    /**
     * 构建 PDO DSN 字符串
     */
    protected function buildDsn(string $driver, array $cfg): string
    {
        switch ($driver) {
            case 'mysql':
                $host = $this->sanitizeDsnValue($cfg['host'] ?? '');
                $port = (int)($cfg['port'] ?? 3306);
                $database = $this->sanitizeDsnValue($cfg['database'] ?? '');
                $dsn = "mysql:host={$host};port={$port};dbname={$database}";
                $dsn .= ';charset=' . (!empty($cfg['charset']) ? $this->sanitizeDsnValue($cfg['charset']) : 'utf8mb4');
                return $dsn;
            case 'pgsql':
                $host = $this->sanitizeDsnValue($cfg['host'] ?? '');
                $port = (int)($cfg['port'] ?? 5432);
                $database = $this->sanitizeDsnValue($cfg['database'] ?? '');
                return "pgsql:host={$host};port={$port};dbname={$database}";
            case 'sqlsrv':
                $host = $this->sanitizeDsnValue($cfg['host'] ?? '');
                $port = (int)($cfg['port'] ?? 1433);
                $database = $this->sanitizeDsnValue($cfg['database'] ?? '');
                return "sqlsrv:Server={$host},{$port};Database={$database}";
            case 'oci':
            case 'oracle':
                $host = $this->sanitizeDsnValue($cfg['host'] ?? '');
                $port = (int)($cfg['port'] ?? 1521);
                $database = $this->sanitizeDsnValue($cfg['database'] ?? '');
                $charset = !empty($cfg['charset']) ? $this->sanitizeDsnValue($cfg['charset']) : 'AL32UTF8';
                return "oci:dbname=//{$host}:{$port}/{$database};charset={$charset}";
            case 'sqlite':
                $path = $cfg['path'] ?? '';
                if (strpos($path, '\0') !== false || preg_match('#\.\.#', $path) || preg_match('#/\./#', $path)) {
                    throw new \InvalidArgumentException('Invalid SQLite database path');
                }
                $allowedBase = realpath(APP_PATH . 'storage/database');
                $realPath = realpath($path);
                if ($realPath !== false) {
                    // 防御 TOCTOU与符号链接：校验路径真实存在、在允许目录内、且非符号链接
                    if ($allowedBase === false || strpos($realPath, $allowedBase) !== 0 || is_link($path)) {
                        throw new \InvalidArgumentException('SQLite path outside allowed directory or is a symlink');
                    }
                    return "sqlite:{$realPath}";
                }
                // 允许在 allowedBase 内创建新文件
                $dir = realpath(dirname($path));
                if ($allowedBase === false || $dir === false || strpos($dir, $allowedBase) !== 0 || is_link(dirname($path))) {
                    throw new \InvalidArgumentException('SQLite path outside allowed directory or is a symlink');
                }
                return "sqlite:{$path}";
            default:
                throw new \InvalidArgumentException("Unsupported driver: {$driver}");
        }
    }

    /**
     * 过滤 DSN 值中的非法字符，防止配置污染导致的 DSN 注入
     */
    protected function sanitizeDsnValue(string $value): string
    {
        if (strpos($value, "\0") !== false) {
            throw new \InvalidArgumentException('DSN value contains NULL byte');
        }

        // 移除换行、回车、控制字符、DSN 分隔符
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f;]/', '', $value);
        // 规范化反斜杠为斜杠（防止路径逃逸或转义注入）
        $value = str_replace('\\', '/', $value);

        return $value;
    }

    /**
     * 插入单条并返回最后 insertId
     * @return void
     */
    public function insert(string $table, array $data)
    {
        $shard = $this->resolveShard($table, $data);
        $this->resetBuilder();
        $fullTable = $this->prefix . $table;
        [$sql, $params] = $this->builder->from($fullTable)->insert($data)->toSqlWithBindings();
        $pdo = $this->connect('master', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);

            // 优先检查数据中是否已包含主键 ID (手动指定主键场景)
            $manualPk = null;
            if (isset($data['id'])) {
                $manualPk = $data['id'];
            } elseif (!empty($this->dbConfig['primary_keys'][$table])) {
                $pk = $this->dbConfig['primary_keys'][$table];
                if (isset($data[$pk])) {
                    $manualPk = $data[$pk];
                }
            }

            if ($manualPk !== null) {
                $result = is_numeric($manualPk) ? (int)$manualPk : $manualPk;
            } else {
                // 只有在未手动指定 ID 时，才尝试从数据库获取
                try {
                    $sequence = $this->grammar->getSequenceName($fullTable);
                    $lastId = $pdo->lastInsertId($sequence);
                    $result = ($lastId !== false && $lastId !== '0' && $lastId !== '') ? (is_numeric($lastId) ? (int)$lastId : $lastId) : (int)$stmt->rowCount();
                } catch (\Throwable $e) {
                    // 如果获取失败（如序列不存在），回退到 rowCount
                    $result = (int)$stmt->rowCount();
                }
            }

            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($data, $sql, $params);
        }
    }

    /**
     * 插入多条
     */
    public function bulkInsert(string $table, array $rows): int
    {
        $shard = $this->resolveBulkShard($table, $rows);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->bulkInsert($rows)->toSqlWithBindings();
        $pdo = $this->connect('master', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = $stmt !== false ? (int)$stmt->rowCount() : 0;
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($rows, $sql, $params);
        }
    }

    /**
     * 查询单行
     */
    public function queryOne(string $table, array $where = [], array $orderBy = [], array $fields = ['*']): array
    {
        $shard = $this->resolveShard($table, $where);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->select($fields)->where($where)->orderBy($orderBy)->limit(1)->toSqlWithBindings();
        $pdo = $this->connect('slave', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = $stmt->fetch();
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result !== false ? $result : [];
        } finally {
            $this->release($pdo);
            unset($where, $orderBy, $fields, $sql, $params);
        }
    }

    /**
     * 查询多行
     *
     * 注意：本方法默认使用 OFFSET 分页，适用于中小数据量场景。
     * 千万级大数据表请使用游标分页，避免 OFFSET 性能瓶颈（参见项目游标分页铁律）。
     */
    /**
     * 将 PDO fetchAll 结果中的流资源（如 pgsql BYTEA）转换为字符串
     */
    protected function normalizeFetchResult(array $rows): array
    {
        foreach ($rows as $i => $row) {
            foreach ($row as $k => $v) {
                if (\is_resource($v) && \get_resource_type($v) === 'stream') {
                    $rows[$i][$k] = \stream_get_contents($v);
                }
            }
        }
        return $rows;
    }

    public function query(string $table, array $where = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array
    {
        $shard = $this->resolveShard($table, $where);
        $offset = ($page - 1) * $pageSize;
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->select($fields)->where($where)->orderBy($orderBy)->limit($pageSize)->offset($offset)->toSqlWithBindings();
        $pdo = $this->connect('slave', $shard);

        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = $this->normalizeFetchResult($stmt->fetchAll());
            $stmt = null;
            $this->maybeCollectGarbage();
            empty($result) ? $result = [] : $key && $result = array_column($result, null, $key);
            return $result;
        } finally {
            $this->release($pdo);
            unset($where, $orderBy, $fields, $sql, $params);
        }
    }

    /**
     * 更新
     */
    public function update(string $table, array $where, array $data): int
    {
        $shard = $this->resolveShard($table, $where);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->update($data)->where($where)->toSqlWithBindings();
        $pdo = $this->connect('master', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = (int)$stmt->rowCount();
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($where, $data, $sql, $params);
        }
    }

    /**
     * 批量更新
     * $rows 格式：[
     *   ['id'=>1, 'views+'=>5, 'name'=>'A'],
     *   ['id'=>2, 'views+'=>3, 'name'=>'B'],
     * ]
     */
    public function bulkUpdate(string $table, array $rows, string $keyColumn = 'id', array $wheres = []): int
    {
        $shard = $this->resolveBulkShard($table, $rows, $keyColumn);
        $this->resetBuilder();
        list($sql, $params) = $this->builder->from($this->prefix . $table)->where($wheres)->bulkUpdate($rows, $keyColumn)->toSqlWithBindings();
        $pdo = $this->connect('master', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = (int)$stmt->rowCount();
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($rows, $sql, $params);
        }
    }

    /**
     * 删除
     */
    public function delete(string $table, array $where): int
    {
        $shard = $this->resolveShard($table, $where);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->delete()->where($where)->toSqlWithBindings();
        $pdo = $this->connect('master', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $result = (int)$stmt->rowCount();
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($where, $sql, $params);
        }
    }

    /**
     * 统计
     */
    public function count(string $table, array $where = []): int
    {
        $shard = $this->resolveShard($table, $where);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->select(["COUNT(*) AS num"])->where($where)->toSqlWithBindings();
        $pdo = $this->connect('slave', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $row = $stmt->fetch();
            $result = (int)($row['num'] ?? 0);
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($row, $where, $sql, $params);
        }
    }

    /** 最大 ID */
    public function maxid(string $table, string $field = '*', array $where = []): int
    {
        if ($field === '*') $field = 'id';

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new \InvalidArgumentException("Invalid field name: {$field}");
        }

        $shard = $this->resolveShard($table, $where);
        $this->resetBuilder();
        [$sql, $params] = $this->builder->from($this->prefix . $table)->select(["MAX({$field}) AS maxid"])->where($where)->limit(1)->toSqlWithBindings();
        $pdo = $this->connect('slave', $shard);
        try {
            $stmt = $this->logAndExec($pdo, $sql, $params);
            $row = $stmt->fetch();
            $result = (int)($row['maxid'] ?? 0);
            $stmt = null;
            $this->maybeCollectGarbage();
            return $result;
        } finally {
            $this->release($pdo);
            unset($row, $where, $sql, $params);
        }
    }

    /** 清空表（增强安全）
 * @param array $tables
 */
    public function truncate($tables): int
    {
        $allowedTables = $this->getAllTables();
        $tables = is_array($tables) ? $tables : [$tables];
        $totalAffected = 0;

        foreach ($tables as $table) {
            if (!$this->isValidTableName($table)) {
                throw new \InvalidArgumentException("Invalid table name: {$table}");
            }

            $fullTableName = $this->prefix . $table;
            if (!in_array($fullTableName, $allowedTables)) {
                throw new \InvalidArgumentException("Table {$table} is not allowed to truncate");
            }

            $pdo = $this->connect('master', $this->resolveShard($table, []));
            $sql = $this->grammar->compileTruncate($fullTableName);

            try {
                $totalAffected += (int)$pdo->exec($sql);

                // SQLite 序列重置特化处理
                if ($this->dbConfig['driver'] === 'sqlite') {
                    $stmt = $pdo->prepare("DELETE FROM sqlite_sequence WHERE name = ?");
                    $stmt->execute([$fullTableName]);
                }
            } catch (\Exception $e) {
                $this->release($pdo);
                $this->logError("Truncate table {$table} failed after processing previous tables", ['processed' => $totalAffected, 'exception' => $e]);
                throw new \RuntimeException("Truncate table {$table} failed: {$e->getMessage()}", 0, $e);
            }

            $this->release($pdo);
        }

        return $totalAffected;
    }

    /**
     * 验证表名是否合法（防止SQL注入）
     */
    private function isValidTableName(string $table): bool
    {
        // 只允许字母、数字、下划线、连字符
        return preg_match('/^[a-zA-Z0-9_-]+$/', $table) === 1;
    }

    /**
     * 获取所有数据库表（白名单验证）
     */
    private function getAllTables(): array
    {
        $pdo = null;
        try {
            $pdo = $this->connect('master');
            $stmt = $this->logAndExec($pdo, $this->grammar->compileTableListing(), []);
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            return $tables;
        } catch (\Exception $e) {
            $this->logError("Failed to get all tables", ['exception' => $e]);
            throw new \RuntimeException("Failed to get all tables: " . $e->getMessage(), 0, $e);
        } finally {
            if ($pdo instanceof PDO) {
                $this->release($pdo);
            }
        }
    }

    /** 是否支持 InnoDB */
    public function isSupportInnodb(string $table = ''): bool
    {
        if ($this->dbConfig['driver'] !== 'mysql') return true; // 非 MySQL 默认认为支持事务/对应引擎

        $pdo = $this->connect('master', $this->resolveShard($table, []));
        try {
            $stmt = $this->logAndExec($pdo, 'SHOW ENGINES', []);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                if (strtolower($r['Engine']) === 'innodb' && strtoupper($r['Support']) === 'YES') {
                    return true;
                }
            }
            return false;
        } finally {
            $this->release($pdo);
        }
    }

    public function close(): void
    {
        // 清理当前运行模式下的连接缓存
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $map = $ctx->dbConnectionMap ?? [];
            foreach ($map as $oid => $cacheKey) {
                if (strpos((string)$cacheKey, 'db_master_') === 0) {
                    unset($ctx->$cacheKey, $map[$oid]);
                }
            }
            $ctx->dbConnectionMap = $map;
        } else {
            // FPM 模式下已移除 static 连接缓存，无需额外清理。
            // 若使用持久连接（persistent=true），由 PDO 自身管理生命周期。
        }
    }

    /**
     * 设置统一日志通道
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function logError(string $message, array $context = []): void
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->error($message, $context);
        } else {
            error_log($message);
        }
    }

    /**
     * 从 DDL 语句中提取目标表名
     */
    protected function extractTableFromDdl(string $sql): ?string
    {
        $patterns = [
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?/i',
            '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?/i',
            '/^ALTER\s+TABLE\s+(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?/i',
            '/^TRUNCATE\s+(?:TABLE\s+)?(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?/i',
            '/^RENAME\s+TABLE\s+(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?\s+TO/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, ltrim($sql), $m)) {
                return $m[2] ?? $m[1] ?? null;
            }
        }
        return null;
    }

    // 执行 SQL 操作，并返回受影响的行数
    public function exec(string $sql): int
    {
        if (empty($sql)) return 0;

        // 检测是否为 DDL（先去除注释，避免注释绕过）
        $normalizedSql = preg_replace(['/\/\*[\s\S]*?\*\//', '/--[^\n]*/'], '', $sql);
        $upperSql = strtoupper(ltrim($normalizedSql));
        $isDDL = false;
        $ddlKeywords = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];
        foreach ($ddlKeywords as $kw) {
            if (0 === strpos($upperSql, $kw)) {
                $isDDL = true;
                break;
            }
        }

        if ($isDDL) {
            $table = $this->extractTableFromDdl($sql);
            if ($table !== null && !$this->isValidTableName($table)) {
                throw new \InvalidArgumentException("Invalid table name in DDL: {$table}");
            }
        }

        if ($isDDL && $this->dbConfig['driver'] === 'pgsql') {
            // 自适应检测 btree_gist 扩展，使用实例级缓存避免跨连接污染
            $btreeCacheKey = 'pgHasBtreeGist_' . md5(serialize($this->dbConfig['master'] ?? []));
            if (!isset($this->dbConfig[$btreeCacheKey])) {
                $pdo = null;
                try {
                    $pdo = $this->connect('master');
                    $check = $pdo->query("SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'");
                    $this->dbConfig[$btreeCacheKey] = (bool)$check->fetch();
                } catch (\Throwable $e) {
                    $this->dbConfig[$btreeCacheKey] = false;
                } finally {
                    if ($pdo instanceof PDO) {
                        $this->release($pdo);
                    }
                }
            }
        }

        $sqls = $isDDL ? $this->grammar->prepareSchema($sql) : [$sql];
        $totalAffected = 0;

        foreach ($sqls as $s) {
            $pdo = $this->connect('master');
            $startTime = microtime(true);
            try {
                $n = (int)$pdo->exec($s);
                $totalAffected += $n;

                $endTime = microtime(true);
                $elapsed = $endTime - $startTime;
                $entry = sprintf('[%0.4f] %s', $elapsed, $s);
                \Framework\Database\Collector\QueryCollector::add($entry);
            } finally {
                $this->release($pdo);
            }
        }

        return $totalAffected;
    }

    /**
     * 查找数据表是否存在
     * $db->findTable('user');
     * @param string $table
     * @return bool true 存在 / false 不存在
     */
    public function findTable(string $table): bool
    {
        if (!$this->isValidTableName($table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        $pdo = $this->connect('slave', $this->resolveShard($table, []));
        try {
            $fullTable = $this->prefix . $table;
            $stmt = $this->logAndExec($pdo, $this->grammar->compileTableExists($fullTable), []);
            $result = $stmt->fetch();
            if (empty($result)) return false;
            foreach ($result as $v) {
                if (strtolower($v) === strtolower($fullTable)) return true;
            }
            return false;
        } finally {
            $this->release($pdo);
        }
    }

    /**
     * 查找数据表字段是否存在
     * $db->findField('user', 'username');
     * @param string $table
     * @param string $field
     * @return bool true 存在 / false 不存在
     */
    public function findField(string $table, string $field): bool
    {
        if (!$this->isValidTableName($table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new \InvalidArgumentException("Invalid field name: {$field}");
        }
        $pdo = $this->connect('slave', $this->resolveShard($table, []));
        try {
            $fullTable = $this->prefix . $table;
            $stmt = $this->logAndExec($pdo, $this->grammar->compileColumnListing($fullTable), []);
            $rows = $stmt->fetchAll();
            if (empty($rows)) return false;
            foreach ($rows as $row) {
                // 聚合多种 DB 的 Field 标识符名
                $colName = $row['field'] ?? $row['Field'] ?? $row['name'] ?? $row['column_name'] ?? $row['COLUMN_NAME'] ?? null;
                if ($colName && strcasecmp((string)$colName, $field) === 0) return true;
            }
            return false;
        } finally {
            $this->release($pdo);
        }
    }

    /**
     * 查找数据表字段索引是否存在(需要完整索引字符串)
     * $db->findIndex('user', 'gid'); 返回false
     * $db->findIndex('user', 'gid_uid'); 返回true
     * @param string $table
     * @param string $index
     * @return bool
     */
    public function findIndex(string $table, string $index): bool
    {
        if (!$this->isValidTableName($table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $index)) {
            throw new \InvalidArgumentException("Invalid index name: {$index}");
        }
        $pdo = $this->connect('slave', $this->resolveShard($table, []));
        try {
            $fullTable = $this->prefix . $table;
            $stmt = $this->logAndExec($pdo, $this->grammar->compileIndexListing($fullTable), []);
            $result = $stmt->fetchAll();
            if (empty($result)) return false;
            foreach ($result as $v) {
                // 兼容不同 DB 的 索引键名 Key_name(MySQL), name(SQLite), indexname(PgSQL), key_name(LowCase)
                $key = (string)($v['key_name'] ?? $v['Key_name'] ?? $v['name'] ?? $v['indexname'] ?? $v['Key'] ?? $v['INDEX_NAME'] ?? '');

                if (strcasecmp($key, $index) === 0) return true;

                // PostgreSQL 专用：适配方言层自动添加的 idx_{table}_ 前缀
                if ($this->dbConfig['driver'] === 'pgsql') {
                    if (strcasecmp($key, "idx_{$fullTable}_{$index}") === 0) return true;
                }
            }
            return false;
        } finally {
            $this->release($pdo);
        }
    }

    // === 以下为 SQL 构造 & 执行辅助 ===

    protected function logAndExec(PDO $pdo, string $sql, array $params = []): \PDOStatement
    {
        // 记录开始时间
        $start = microtime(true);
        try {
            $stmt = $pdo->prepare($sql);

            // 安全绑定：自动识别并处理原始二进制字节流 (针对 PostgreSQL 22021 错误加固)
            foreach ($params as $k => $v) {
                $paramIndex = is_int($k) ? $k + 1 : $k;
                if (is_string($v) && (strpos($v, "\0") !== false || (strlen($v) < 1024 && extension_loaded('mbstring') && !mb_check_encoding($v, 'UTF-8')))) {
                    // 对于原始二进制数据，使用 LOB 模式绕过字节截断或字符集校验
                    $stmt->bindValue($paramIndex, $v, \PDO::PARAM_LOB);
                } else {
                    $stmt->bindValue($paramIndex, $v);
                }
            }

            $stmt->execute();

            // 成功追踪
            \defined('DEBUG') && \DEBUG > 1 && $this->debugWriteLog('SUCCESS', $sql, $params, microtime(true) - $start);
        } catch (\Throwable $e) {
            // 关键：记录导致事务失败或操作失败的真实异常
            \defined('DEBUG') && \DEBUG > 1 && $this->debugWriteLog('ERROR: ' . $e->getMessage(), $sql, $params, microtime(true) - $start);
            throw $e;
        }

        if (\defined('SCHEDULER_MODE') && \SCHEDULER_MODE) return $stmt;

        $elapsed = microtime(true) - $start;
        $fullSql = $this->interpolate($sql, $params);

        $maxLength = 128;
        if (strlen($fullSql) > $maxLength) {
            $fullSql = substr($fullSql, 0, $maxLength) . '... [truncated]';
        }
        $formatted = sprintf('[%0.4f] %s', $elapsed, $fullSql);

        \Framework\Database\Collector\QueryCollector::slide(100, 50);

        \Framework\Database\Collector\QueryCollector::add($formatted);

        return $stmt;
    }

    protected function interpolate(string $sql, array $params): string
    {
        $index = 0;
        return preg_replace_callback(
            "/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'|\"[^\"\\\\]*(?:\\\\.[^\"\\\\]*)*\"|\\?/",
            function ($matches) use (&$index, $params) {
                if ($matches[0] === '?') {
                    return isset($params[$index]) ? $this->quote($params[$index++]) : '?';
                }
                return $matches[0];
            },
            $sql
        );
    }

    // 对不同类型值做了转义/加引号（仅用于日志，不直接执行）
    protected function quote($v): string
    {
        if (is_null($v)) return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_numeric($v)) return (string)$v;
        $s = (string)$v;
        // 若包含不可见字符或非 UTF-8，标记为二进制，避免日志复制执行风险
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $s) !== 0 || (extension_loaded('mbstring') && !mb_check_encoding($s, 'UTF-8'))) {
            return "'[BINARY]'";
        }
        return "'" . addslashes($s) . "'";
    }

    /**
     * 释放连接（钩子方法，供 ProxyDriver 重写）
     * @param PDO $pdo
     */
    protected function release(PDO $pdo): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $map = $ctx->dbConnectionMap ?? [];
            $oid = spl_object_id($pdo);
            if (isset($map[$oid])) {
                $key = $map[$oid];
                unset($ctx->$key, $map[$oid]);
                $ctx->dbConnectionMap = $map;
                return;
            }
            // Fallback for legacy or slave connections
            foreach ($ctx as $key => $val) {
                if ($val === $pdo && strpos((string)$key, 'db_') === 0) {
                    unset($ctx->$key);
                    break;
                }
            }
        }
    }

    /**
     * 开始事务
     *
     * 重要约束：
     * 1. $shard 指定事务绑定的物理分片节点。在连接池模式下，事务状态会与该 shard 关联。
     * 2. 未启用分片（sharding.tables 为空）时，必须传 ''（默认值），表示 default shard。
     * 3. 已启用分片时，应显式传入与主操作表一致的真实 shard ID（如 'shard_0'），
     *    可通过 resolveShard($table, $where) 获取。
     * 4. 事务开启后，若后续数据库操作尝试连接不同的 shard，会触发跨分片检测异常：
     *    "Cross-shard query violation: Active transaction on 'X', attempted operation on 'Y'."
     * 5. 因此，涉及多表的原子操作必须在同一事务 shard 内完成。
     *
     * @param string $shard 分片标识，未分片环境传 ''，分片环境传真实 shard ID
     * @throws \PDOException
     */
    public function beginTransaction(string $shard = ''): bool
    {
        $pdo = $this->connect('master', $shard);
        $ok = $pdo->beginTransaction();
        $this->setTransactionState(true);

        return $ok;
    }

    /**
     * 提交事务
     *
     * 使用说明：
     * 1. $shard 必须与 beginTransaction() 传入的值完全一致。
     * 2. 在 ProxyDriver / 连接池模式下，commit 会释放该 shard 对应的 master 连接，
     *    并将连接交还连接池。
     * 3. 不要在 commit 之前切换 shard 进行其他数据库操作，否则会导致跨分片异常。
     *
     * @param string $shard 分片标识，需与 beginTransaction 保持一致
     * @throws \PDOException
     */
    public function commit(string $shard = ''): bool
    {
        $pdo = $this->connect('master', $shard);
        try {
            return $pdo->commit();
        } finally {
            $this->setTransactionState(false);
            $this->release($pdo);
        }
    }

    /**
     * 回滚事务
     *
     * 使用说明：
     * 1. $shard 必须与 beginTransaction() 传入的值完全一致。
     * 2. 回滚后，连接池会释放该 shard 的 master 连接，事务状态重置为未开启。
     * 3. 建议在 catch 块中调用，确保异常时数据一致性。
     *
     * @param string $shard 分片标识，需与 beginTransaction 保持一致
     * @throws \PDOException
     */
    public function rollback(string $shard = ''): bool
    {
        $pdo = $this->connect('master', $shard);
        try {
            return $pdo->rollBack();
        } finally {
            $this->setTransactionState(false);
            $this->release($pdo);
        }
    }

    /**
     * 是否在事务中
     *
     * 使用说明：
     * 1. 在 Swoole 协程环境下，检测当前协程上下文的事务状态。
     * 2. 在 FPM / CLI 阻塞环境下，检测当前 PDO 实例的事务状态。
     * 3. 若需要判断某个特定 shard 是否已开启事务，应在业务层自行维护事务层级映射；
     *    本方法返回的是当前执行上下文的全局事务状态。
     */
    public function inTransaction(string $shard = ''): bool
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            return $ctx->inTransaction ?? false;
        }
        return $this->inTransaction;
    }

    /**
     * 设置事务状态（协程安全）
     * @param bool $state
     */
    protected function setTransactionState(bool $state): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $ctx->inTransaction = $state;
        } else {
            $this->inTransaction = $state;
        }
    }

    /**
     * 设置事务隔离级别
     *
     * @param string $level 如 'READ COMMITTED', 'SERIALIZABLE' 等
     * @param string $shard
     */
    public function setIsolationLevel(string $level, string $shard = ''): void
    {
        $level = strtoupper($level);
        $allowed = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];
        if (!in_array($level, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid isolation level: {$level}");
        }

        $ctx = self::coroContext();
        if ($ctx !== null) {
            $ctx->dbIsolationLevel = $level;
        }

        $pdo = $this->connect('master', $shard);
        try {
            $sql = "SET SESSION TRANSACTION ISOLATION LEVEL {$level}";
            $this->logAndExec($pdo, $sql, []);
        } finally {
            $this->release($pdo);
        }
    }

    /**
     * 带重试的事务执行模板
     *
     * 使用说明与最佳实践：
     * 1. $shard 是事务绑定的物理分片标识。显式声明 shard 是防御跨分片异常的核心手段。
     * 2. 未启用分片时，应传 ''（默认值）：
     *    $db->transaction(function () { ... });
     *    或显式：$db->transaction(function () { ... }, 3, '');
     * 3. 已启用分片时，必须传主操作表对应的真实 shard ID，通常通过 resolveShard 获取：
     *    $shard = $db->resolveShard('user', ['user_id' => $userId]);
     *    $db->transaction(function () { ... }, 3, $shard);
     * 4. 事务回调内部涉及的所有表操作，必须落在同一 shard 内。若事务在 shard '' 上开启，
     *    但内部某张表的 resolveShard 返回了 'forum_thread'（旧版本的误报行为），
     *    ProxyDriver::connect 会抛出 "Cross-shard query violation"。
     * 5. 本方法已内置连接断开重试机制（maxAttempts 次），重试时会清理失效连接。
     *
     * @param callable $callback 事务回调，接收当前 DatabaseInterface 实例
     * @param int $maxAttempts 最大重试次数，默认 3
     * @param string $shard 分片标识，未分片环境传 ''，分片环境传真实 shard ID
     * @return mixed 回调返回值
     * @throws \Throwable
     */
    public function transaction(callable $callback, int $maxAttempts = 3, string $shard = '')
    {
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $this->beginTransaction($shard);
                try {
                    $result = $callback($this);
                    $this->commit($shard);
                    return $result;
                } catch (\Throwable $e) {
                    $this->rollback($shard);
                    throw $e;
                }
            } catch (\Throwable $e) {
                // 如果是连接断开导致的异常，且还有重试机会，则清理连接并重试
                if ($this->isConnectionLost($e) && $attempt < $maxAttempts) {
                    $this->handleConnectionLoss($shard);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Database transaction failed after max attempts.');
    }

    /**
     * 判断是否为物理连接丢失
     */
    protected function isConnectionLost(\Throwable $e): bool
    {
        // Swoole 环境下，此变量在 Worker 进程生命周期内只初始化 1 次，性能最优
        static $pattern = null;
        if (null === $pattern) {
            $lostMessages = [
                'server has gone away',
                'no connection to the server',
                'Lost connection',
                'is dead or not responding',
                'decide it is dead',
                'Connection refused',
                'closing connection',
                'decode error',
                'SSL connection has been closed unexpected',
                'Error while sending',
                'server closed the connection unexpectedly',
            ];
            // 拼接并转义，确保特殊字符不干扰正则，尾部加 /i 实现大小写不敏感
            $pattern = '/' . implode('|', array_map(function ($m) {
                return preg_quote($m, '/');
            }, $lostMessages)) . '/i';
        }

        return (bool)preg_match($pattern, $e->getMessage());
    }

    /**
     * 处理连接丢失：清理当前驱动持有的 PDO 引用
     */
    protected function handleConnectionLoss(string $shard = ''): void
    {
        $this->close();
        if (isset($this->pool) && $this->pool instanceof \Framework\Database\Pool\PoolInterface) {
            $this->pool->evictCurrentConnection($shard);
        }
        gc_collect_cycles();
    }

    /**
     * 获取数据库版本
     */
    public function version(string $role = 'master', string $shard = ''): string
    {
        $pdo = $this->connect($role, $shard);
        try {
            return $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } finally {
            $this->release($pdo);
        }
    }

    /**
     * 实现物理日志记录（调试用）
     * @param array $status
     * @param string $sql
     * @param array $params
     * @param int $time
     */
    private function debugWriteLog($status, $sql, $params, $time): void{
        $appPath = \defined('APP_PATH') ? \APP_PATH : (\dirname(__DIR__, 3) . '/');
        if (empty($appPath) || !is_dir($appPath . 'storage/')) {
            error_log("APP_PATH invalid or storage directory missing, cannot write SQL debug log");
            return;
        }
        // 确保目录存在
        $logDir = $appPath . 'storage/logs/';
        if (!is_dir($logDir)) {
            $old = umask(0);
            $ok = @mkdir($logDir, 0755, true);
            umask($old);
            if (!$ok) {
                error_log("Failed to create SQL debug log directory: {$logDir}");
                return;
            }
        }

        $logFile = $logDir . 'sql_debug.log';
        if (file_exists($logFile) && filesize($logFile) > 52428800) {
            file_put_contents($logFile, '');
        }
        $date = date('Y-m-d H:i:s');
        $content = sprintf(
            "[%s] [%s] [%.4fs] SQL: %s | Params: %s\n",
            $date,
            $status,
            $time,
            $sql,
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $written = @file_put_contents($logFile, $content, FILE_APPEND);
        if ($written !== false && file_exists($logFile)) {
            @chmod($logFile, 0664);
        }
        if ($written === false) {
            error_log("Failed to write SQL debug log to: {$logFile}");
        }
    }
}