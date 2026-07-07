<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Database\Partition;

use Framework\Database\Interfaces\DatabaseInterface;
use Framework\Cache\Interfaces\CacheInterface;
use Framework\Logger\LoggerInterface;

/**
 * 数据库分区管理器。
 *
 * 职责：
 * 1. 执行分区 DDL（ADD / DROP / REORGANIZE）
 * 2. 提供幂等的 maintain() 入口
 * 3. 提供请求驱动的惰性维护入口 maintainIfNeeded()
 *
 * DDL 安全：
 * - 所有 DDL 前获取 Redis 分布式锁，防止并发
 * - 使用 createFreshConnection() 获取专用 PDO 连接执行所有 DDL，
 *   避免连接池中 MySQL GET_LOCK 不生效
 * - 对 pmax 的 REORGANIZE 可能导致 MDL 阻塞，
 *   建议通过 Scheduler Job 在低峰期执行
 *
 * 双模式（参见设计文档 §七）：
 * - FPM：maintainIfNeeded() 可直接执行 DDL
 * - Swoole：maintainIfNeeded() 仅投递任务到 Scheduler，不阻塞事件循环
 *
 * 不使用 StatefulTrait：PartitionManager 是 DDL 管理器，
 * 不持有请求范围状态，容器中全局单例。
 *
 * PHP 7.2 兼容：不使用 typed properties / union types / named arguments。
 */
class PartitionManager
{
    /** @var PartitionRegistry */
    private $registry;

    /** @var DatabaseInterface */
    private $db;

    /** @var CacheInterface */
    private $cache;

    /** @var array  §11.4 熔断：每张表的连续失败次数 [tableName => count] */
    private $consecutiveFailures = array();

    /** @var int  §11.4 熔断阈值：连续失败超过此数跳过该表 */
    const FAILURE_THRESHOLD = 3;

    /** @var LoggerInterface */
    private $logger;

    /** @var object|null TaskManage 实例（Swoole 模式惰性检查投递任务使用） */
    private $taskManage;

    /** @var string 表前缀，如 'well_' */
    private $prefix;

    /** @var PartitionDialectInterface */
    private $dialect;

    /** @var string 缓存锁 Key */
    const LOCK_KEY = 'ddl:partition:maintain';

    /** @var string 最后维护时间缓存 Key */
    const LAST_RUN_KEY = 'partition:last_maintain_at';

    /**
     * @param PartitionRegistry              $registry
     * @param DatabaseInterface              $db
     * @param CacheInterface                 $cache
     * @param LoggerInterface                $logger
     * @param object|null                    $taskManage 可选的 TaskManage 实例
     * @param string                         $prefix     表前缀
     * @param PartitionDialectInterface|null $dialect    分区方言（null 时自动检测）
     */
    public function __construct(
        PartitionRegistry $registry,
        DatabaseInterface $db,
        CacheInterface $cache,
        LoggerInterface $logger,
        $taskManage = null,
        string $prefix = '',
        ?PartitionDialectInterface $dialect = null
    ) {
        $this->registry = $registry;
        $this->db = $db;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->taskManage = $taskManage;
        $this->prefix = $prefix;
        // 容器注入优先；null 时工厂自动检测（开发/测试兜底）
        $this->dialect = $dialect ?? PartitionDialectFactory::createFromConnection($db);
    }

    /**
     * §7.1 判断当前运行模式。
     *
     * @return bool true 表示 Swoole 环境，false 表示 FPM。
     */
    public static function isSwoole(): bool
    {
        return \defined('SWOOLE_VERSION') && \extension_loaded('swoole');
    }

    // ---------------------------------------------------------------
    //  注册委托
    // ---------------------------------------------------------------

    /**
     * 注册一张表的分区配置。
     *
     * @param PartitionConfig $config
     * @return void
     */
    public function register(PartitionConfig $config)
    {
        $this->registry->register($config);
    }

    /**
     * 注销一张表的分区管理。
     *
     * @param string $table
     * @return void
     */
    public function unregister(string $table)
    {
        $this->registry->unregister($table);
    }

    /**
     * 扫描 information_schema 接管存量分区表。
     *
     * @return int 发现的存量分区表数量
     */
    public function adoptExistingTables(): int
    {
        return $this->registry->discoverExisting($this->prefix);
    }

    // ---------------------------------------------------------------
    //  核心维护入口
    // ---------------------------------------------------------------

    /**
     * 全量维护：遍历注册表，创建未来分区，删除过期分区。
     * 幂等、可重入。
     *
     * @param bool $dryRun true 时只输出计划不执行 DDL
     * @return MaintenanceResult
     */
    public function maintain(bool $dryRun = false): MaintenanceResult
    {
        // hook Framework_Database_Partition_PartitionManager_maintain_start.php
        $result = new MaintenanceResult();
        $result->trigger = $this->detectTrigger();

        $startTime = microtime(true);

        // 获取 Redis 分布式锁
        $lockToken = $this->cache->lock(self::LOCK_KEY, 300);
        if (!$lockToken) {
            $this->logger->warning('PartitionMaintain skipped: lock not acquired');
            return $result;
        }

        $pdo = null;
        try {
            // 获取专用连接，所有 DDL 使用同一连接
            $pdo = $this->db->createFreshConnection('master');
            // §11.2 DDL 超时保护：防止 DDL 无限阻塞业务查询
            foreach ($this->dialect->compileSessionSetup() as $sql) {
                $pdo->exec($sql);
            }

            $this->logger->info('PartitionMaintain started', array(
                'dry_run' => $dryRun,
                'trigger' => $result->trigger,
            ));

            $configs = $this->registry->getAll();
            // 注册表为空时自动扫描 information_schema 接管存量表
            if (empty($configs)) {
                $this->registry->discoverExisting($this->prefix);
                $configs = $this->registry->getAll();
            }
            foreach ($configs as $table => $config) {
                $result->tablesScanned++;
                $this->maintainOneTable($pdo, $config, $dryRun, $result);
            }

            // 更新最后维护时间
            if (!$dryRun) {
                $this->cache->set(self::LAST_RUN_KEY, time(), 86400);
            }
        } catch (\Throwable $e) {
            $result->errors++;
            $result->errorDetails[] = array(
                'table' => '*global*',
                'error' => $e->getMessage(),
            );
            $this->logger->error('PartitionMaintain global error', array(
                'error' => $e->getMessage(),
            ));
        } finally {
            if ($lockToken) {
                $this->cache->unlock(self::LOCK_KEY, (string)$lockToken);
            }
        }

        $result->executionMs = (microtime(true) - $startTime) * 1000;

        $this->logger->info('PartitionMaintain completed', array(
            'trigger'            => $result->trigger,
            'tables_scanned'     => $result->tablesScanned,
            'partitions_created' => $result->partitionsCreated,
            'partitions_dropped' => $result->partitionsDropped,
            'errors'             => $result->errors,
            'error_details'      => $result->errors > 0 ? $result->errorDetails : null,
            'execution_ms'       => round($result->executionMs, 2),
        ));

        // hook Framework_Database_Partition_PartitionManager_maintain_end.php
        return $result;
    }

    /**
     * 单表维护：指定一张表执行 maintain 逻辑。
     *
     * @param string $table  纯表名
     * @param bool   $dryRun
     * @return MaintenanceResult
     */
    public function maintainOne(string $table, bool $dryRun = false): MaintenanceResult
    {
        $result = new MaintenanceResult();
        $result->trigger = $this->detectTrigger();

        $config = $this->registry->get($table);
        if ($config === null) {
            $this->logger->warning('PartitionMaintainOne: table not registered', array(
                'table' => $table,
            ));
            return $result;
        }

        $startTime = microtime(true);
        $lockToken = $this->cache->lock(self::LOCK_KEY, 300);

        $pdo = null;
        try {
            $pdo = $this->db->createFreshConnection('master');
            // §11.2 DDL 超时保护：防止 DDL 无限阻塞业务查询
            foreach ($this->dialect->compileSessionSetup() as $sql) {
                $pdo->exec($sql);
            }
            $result->tablesScanned = 1;
            $this->maintainOneTable($pdo, $config, $dryRun, $result);
        } catch (\Throwable $e) {
            $result->errors++;
            $result->errorDetails[] = array(
                'table' => $table,
                'error' => $e->getMessage(),
            );
            $this->logger->error('PartitionMaintainOne failed', array(
                'table' => $table,
                'error' => $e->getMessage(),
            ));
        } finally {
            if ($lockToken) {
                $this->cache->unlock(self::LOCK_KEY, (string)$lockToken);
            }
        }

        $result->executionMs = (microtime(true) - $startTime) * 1000;
        return $result;
    }

    /**
     * 请求驱动惰性维护。
     *
     * 99.9% 的请求直接短路返回，不进 DB。
     *
     * 短路条件：
     *   1. 距离下个分区边界 > 30 天 → 跳过
     *   2. 上次执行在 1 小时内 → 跳过
     *   3. 获取 Redis 锁失败（有其他请求/Job 在执行）→ 跳过
     *
     * 模式区别：
     *   - FPM：条件满足时直接执行 maintain()
     *   - Swoole：条件满足时通过 taskManage->createTask() 投递任务，
     *     实际 DDL 由 Scheduler 异步执行，不阻塞事件循环
     *
     * @return bool true 表示本次执行了维护（或成功投递了任务）
     */
    public function maintainIfNeeded(): bool
    {
        // Step 1: 检查距离下个边界是否 < 30 天
        $nextBoundary = $this->estimateNextBoundary();
        if (time() < $nextBoundary - 86400 * 30) {
            return false;
        }

        // Step 2: 检查上次执行时间
        $lastRun = (int)$this->cache->get(self::LAST_RUN_KEY, 0);
        if (time() - $lastRun < 3600) {
            return false;
        }

        // Step 3: 尝试获取锁
        $lockToken = $this->cache->lock('partition:lazy:flag', 3600);
        if (!$lockToken) {
            return false;
        }

        try {
            if (self::isSwoole() && $this->taskManage !== null) {
                // Swoole 模式：投递任务到 Scheduler
                $this->taskManage->createTask(array(
                    'className'   => \App\Jobs\PartitionMaintainJob::class,
                    'methodName'  => 'handle',
                    'args'        => array(),
                    'scheduledAt' => time() + 10,
                    'dedupeKey'   => 'partition:maintain:from_lazy',
                    'timeout'     => 300,
                    'maxRetries'  => 2,
                    'retryDelay'  => 60,
                ));
                $this->logger->info('PartitionMaintainIfNeeded: dispatched to Scheduler');
                return true;
            }

            // FPM 模式：直接执行
            $this->maintain();
            return true;
        } finally {
            $this->cache->unlock('partition:lazy:flag', (string)$lockToken);
        }
    }

    // ---------------------------------------------------------------
    //  状态查询
    // ---------------------------------------------------------------

    /**
     * 获取所有注册表的分区状态摘要。
     *
     * @return array<string, array> 以 table 名为键的状态数组
     */
    public function getStatus(): array
    {
        $status = array();
        $configs = $this->registry->getAll();

        foreach ($configs as $table => $config) {
            $status[$table] = array(
                'table'             => $config->table,
                'partition_column'  => $config->partitionColumn,
                'period'            => $config->period,
                'sub_partition'     => $config->subPartitionColumn,
                'sub_partitions'    => $config->subPartitions,
                'advance_count'     => $config->advanceCount,
                'retention'         => $config->retention,
            );
        }

        return $status;
    }

    // ---------------------------------------------------------------
    //  内部方法
    // ---------------------------------------------------------------

    /**
     * 维护单张表的分区。
     *
     * @param \PDO              $pdo    钉住的专用连接
     * @param PartitionConfig   $config
     * @param bool              $dryRun
     * @param MaintenanceResult $result
     * @return void
     */
    private function maintainOneTable(\PDO $pdo, PartitionConfig $config, bool $dryRun, MaintenanceResult $result)
    {
        $fullTableName = $this->prefix . $config->table;

        // §11.4 熔断检查：连续失败超过阈值则跳过该表
        if (isset($this->consecutiveFailures[$fullTableName])
            && $this->consecutiveFailures[$fullTableName] >= self::FAILURE_THRESHOLD) {
            $this->logger->warning('PartitionMaintain: table skipped due to consecutive failures', array(
                'table'  => $fullTableName,
                'count'  => $this->consecutiveFailures[$fullTableName],
            ));
            return;
        }

        // 1. 查询当前分区列表
        $partitions = $this->fetchPartitions($pdo, $fullTableName);
        if (empty($partitions)) {
            $this->logger->warning('PartitionMaintain: table has no partitions or not found', array(
                'table' => $fullTableName,
            ));
            return;
        }

        // 2. 找出最高分区边界
        $maxBoundary = 0;
        $hasPmax = false;
        foreach ($partitions as $p) {
            if ($p['description'] === 'MAXVALUE') {
                $hasPmax = true;
                continue;
            }
            $boundary = (int)$p['description'];
            if ($boundary > $maxBoundary) {
                $maxBoundary = $boundary;
            }
        }

        if (!$hasPmax) {
            $this->logger->warning('PartitionMaintain: no pmax partition found, skip', array(
                'table' => $fullTableName,
            ));
            return;
        }

        // 3. 计算需要预创建的分区数（当前已有未来分区数量）
        $now = time();
        $expectedBoundary = $this->calculateBoundaryAfterAdvance($now, $config);

        // 防御：无数据分区或已有分区远在保留期外时，锚定到合理起始边界。
        // 条件：maxBoundary=0（无数据分区）或 maxBoundary 比当前保留期的分区还老
        // 场景：修复后首次运行发现 p2005Q4 这样的历史遗留分区时，强制锚定到当前起算点
        if ($maxBoundary === 0) {
            $periods = $config->retention > 0 ? $config->retention : 8;
            $anchor = PartitionPeriod::ago($now, $config->period, $periods);
            if ($anchor > 0) {
                $maxBoundary = $anchor;
            }
        } else {
            // 已有遗留分区时（如从历史错误中恢复），确保 maxBoundary 不低于保留期边界
            $lifeAnchor = $config->retention > 0
                ? PartitionPeriod::ago($now, $config->period, $config->retention)
                : PartitionPeriod::ago($now, $config->period, 8);
            if ($lifeAnchor > 0 && $maxBoundary < $lifeAnchor) {
                $this->logger->info('PartitionMaintain: boundary lifted from legacy partition', array(
                    'table'       => $fullTableName,
                    'previous'    => $maxBoundary,
                    'corrected'   => $lifeAnchor,
                ));
                $maxBoundary = $lifeAnchor;
            }
        }

        // §11.4 熔断：记录处理前的错误数，用于判断本次操作是否新增错误
        $errorsBefore = $result->errors;

        // 4. 先删除过期分区（为后续创建释放分区槽位，防止 legacy 分区过多导致 MySQL 1499）
        if ($config->retention > 0) {
            $this->dropExpiredPartitions($pdo, $fullTableName, $config, $now, $dryRun, $result);
        }

        // 如果 maxBoundary 已达到预期边界，跳过创建
        if ($maxBoundary < $expectedBoundary) {
            $this->createFuturePartitions($pdo, $fullTableName, $config, $maxBoundary, $expectedBoundary, $dryRun, $result);
        }

        // §11.4 熔断：更新连续失败计数
        if ($result->errors > $errorsBefore) {
            if (!isset($this->consecutiveFailures[$fullTableName])) {
                $this->consecutiveFailures[$fullTableName] = 0;
            }
            $this->consecutiveFailures[$fullTableName]++;
            if ($this->consecutiveFailures[$fullTableName] >= self::FAILURE_THRESHOLD) {
                $this->logger->error('PartitionMaintain: circuit breaker triggered for table', array(
                    'table' => $fullTableName,
                    'count' => $this->consecutiveFailures[$fullTableName],
                ));
            }
        } else {
            // 成功处理，重置熔断计数
            $this->consecutiveFailures[$fullTableName] = 0;
        }
    }

    /**
     * 查询表的所有分区信息。
     *
     * @param \PDO   $pdo
     * @param string $fullTableName 带前缀的表名
     * @return array
     */
    private function fetchPartitions(\PDO $pdo, string $fullTableName): array
    {
        try {
            $sql = $this->dialect->compilePartitionsQuery($fullTableName);
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $result = array();
            foreach ($rows as $row) {
                $result[] = array(
                    'name'        => $row['partition_name'],
                    'description' => $this->normalizePartitionDescription(
                        $row['partition_description']
                    ),
                    'position'    => (int)$row['partition_ordinal_position'],
                );
            }
            return $result;
        } catch (\Throwable $e) {
            // 单表查询失败不应中断全局维护
            $this->logger->warning('PartitionMaintain: failed to query partitions', array(
                'table' => $fullTableName,
                'error' => $e->getMessage(),
            ));
            return array();
        }
    }

    /**
     * 归一化分区边界描述。
     * MySQL: 数值（如 '1735689600'）或 'MAXVALUE'
     * PgSQL: 'FOR VALUES FROM (1735689600) TO (1743465600)' → 提取 TO 的值
     *        'DEFAULT' → 'MAXVALUE'
     *
     * @param string $raw
     * @return string
     */
    private function normalizePartitionDescription(string $raw): string
    {
        $upper = strtoupper(trim($raw));

        // 纯数值或 MAXVALUE（MySQL 标准格式）
        if (is_numeric($upper) || $upper === 'MAXVALUE') {
            return $upper;
        }

        // PG: 'FOR VALUES FROM (X) TO (Y)' → 取 TO 后的值
        if (preg_match('/TO\s*\((\d+)\)/i', $raw, $m)) {
            return $m[1];
        }

        // PG DEFAULT
        if (stripos($raw, 'DEFAULT') !== false) {
            return 'MAXVALUE';
        }

        return $raw;
    }

    /**
     * 从带前缀的完整表名中去除前缀，返回纯表名。
     * 用于构造 PG 分区子表名。
     *
     * @param string $fullTableName
     * @return string
     */
    private function stripPrefix(string $fullTableName): string
    {
        if ($this->prefix !== '' && strncmp($fullTableName, $this->prefix, strlen($this->prefix)) === 0) {
            return substr($fullTableName, strlen($this->prefix));
        }
        return $fullTableName;
    }

    /**
     * 计算最远需要的分区边界时间戳。
     * = 从现在开始 + advanceCount 个周期后的边界。
     *
     * @param int              $now
     * @param PartitionConfig  $config
     * @return int
     */
    private function calculateBoundaryAfterAdvance(int $now, PartitionConfig $config): int
    {
        $boundary = $now;
        for ($i = 0; $i < $config->advanceCount; $i++) {
            $boundary = PartitionPeriod::nextBoundary($boundary, $config->period);
        }
        return $boundary;
    }

    /**
     * 从 maxBoundary 开始，创建分区直到 expectedBoundary。
     *
     * @param \PDO             $pdo
     * @param string           $fullTableName
     * @param PartitionConfig  $config
     * @param int              $currentMaxBoundary  当前最高分区边界
     * @param int              $expectedBoundary    预期需达到的边界
     * @param bool             $dryRun
     * @param MaintenanceResult $result
     * @return void
     */
    private function createFuturePartitions(
        \PDO $pdo,
        string $fullTableName,
        PartitionConfig $config,
        int $currentMaxBoundary,
        int $expectedBoundary,
        bool $dryRun,
        MaintenanceResult $result
    ) {
        $boundary = $currentMaxBoundary;
        $safetyLimit = max($config->advanceCount * 4, 16);
        $created = 0;

        while ($boundary < $expectedBoundary) {
            if (++$created > $safetyLimit) {
                $this->logger->error('PartitionMaintain safety limit exceeded', array(
                    'table'     => $fullTableName,
                    'limit'     => $safetyLimit,
                    'boundary'  => $boundary,
                    'expected'  => $expectedBoundary,
                ));
                $result->errors++;
                break;
            }
            $nextBoundary = PartitionPeriod::nextBoundary($boundary, $config->period);
            if ($nextBoundary <= $boundary) {
                // 防止死循环
                break;
            }

            // 构造分区名：
            //   MySQL: 仅使用周期后缀（如 'p2026Q4'），保持与旧版本一致
            //   PG:    纯表名 + 周期后缀（如 'forum_reply_p2026Q4'），
            //          与 PgSqlGrammar::prepareSchema() 建表阶段命名一致
            if ($this->dialect->supportsTransactionalDdl()) {
                $pureTable = $this->stripPrefix($fullTableName);
                $partitionName = $pureTable . '_' . PartitionPeriod::formatName(
                    $nextBoundary,
                    $config->period
                );
            } else {
                $partitionName = PartitionPeriod::formatName(
                    $nextBoundary,
                    $config->period
                );
            }

            $sqls = $this->dialect->compileCreatePartition(
                $fullTableName,
                $boundary,          // prevBoundary
                $partitionName,
                $nextBoundary,      // boundary
                $config
            );

            // PG 支持事务性 DDL，将同一分区的多条 SQL 包裹在事务中
            if ($this->dialect->supportsTransactionalDdl() && count($sqls) > 1) {
                $pdo->beginTransaction();
            }

            try {
                foreach ($sqls as $sql) {
                    if ($dryRun) {
                        $this->logger->info('PartitionMaintain [DRY-RUN] would create', array(
                            'table' => $fullTableName,
                            'sql'   => $sql,
                        ));
                    } else {
                        $pdo->exec($sql);
                    }
                }
                if ($this->dialect->supportsTransactionalDdl() && count($sqls) > 1) {
                    $pdo->commit();
                }
                $this->logger->info('PartitionMaintain created', array(
                    'table'     => $fullTableName,
                    'partition' => $partitionName,
                ));
            } catch (\Throwable $e) {
                if ($this->dialect->supportsTransactionalDdl()
                    && count($sqls) > 1
                    && $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }
                $result->errors++;
                $result->errorDetails[] = array(
                    'table' => $fullTableName,
                    'error' => $e->getMessage(),
                );
                $this->logger->error('PartitionMaintain create failed', array(
                    'table'     => $fullTableName,
                    'partition' => $partitionName,
                    'error'     => $e->getMessage(),
                ));
                break;
            }

            $result->partitionsCreated++;
            $boundary = $nextBoundary;
        }
    }

    /**
     * 删除超过保留期的分区。
     *
     * @param \PDO             $pdo
     * @param string           $fullTableName
     * @param PartitionConfig  $config
     * @param int              $now
     * @param bool             $dryRun
     * @param MaintenanceResult $result
     * @return void
     */
    private function dropExpiredPartitions(
        \PDO $pdo,
        string $fullTableName,
        PartitionConfig $config,
        int $now,
        bool $dryRun,
        MaintenanceResult $result
    ) {
        $retentionBoundary = PartitionPeriod::ago($now, $config->period, $config->retention);
        if ($retentionBoundary <= 0) {
            return;
        }

        $partitions = $this->fetchPartitions($pdo, $fullTableName);
        if (empty($partitions)) {
            return;
        }

        // 只收集小于 retentionBoundary 的分区（排除 pmax)
        $toDrop = array();
        foreach ($partitions as $p) {
            // 跳过 pmax 和无描述的分区
            if ($p['description'] === 'MAXVALUE' || $p['description'] === '') {
                continue;
            }
            // PARTITION_DESCRIPTION 是分区的 VALUES LESS THAN 值
            // 如果描述值 <= retentionBoundary，说明该分区数据已过期
            if ((int)$p['description'] <= $retentionBoundary) {
                $toDrop[] = $p['name'];
            }
        }

        if (empty($toDrop)) {
            return;
        }

        // 防御 MySQL error 1508：禁止删除所有数据分区，至少保留边界值最大的一个
        // $partitions 包含 pmax，数据分区数 = count($partitions) - 1
        if (count($toDrop) >= count($partitions) - 1 && count($partitions) > 1) {
            // $toDrop 按 PARTITION_ORDINAL_POSITION 升序排列，弹出最后一个（最新分区）
            array_pop($toDrop);
        }

        // 防御后可能清空（只剩 1 个分区被保留），不再执行 DROP
        if (empty($toDrop)) {
            return;
        }

        $sqls = $this->dialect->compileDropPartitions($fullTableName, $toDrop);

        // PG 支持事务性 DDL
        if ($this->dialect->supportsTransactionalDdl() && count($sqls) > 1) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($sqls as $sql) {
                if ($dryRun) {
                    $this->logger->info('PartitionMaintain [DRY-RUN] would drop', array(
                        'table' => $fullTableName,
                        'sql'   => $sql,
                    ));
                } else {
                    $pdo->exec($sql);
                }
            }
            if ($this->dialect->supportsTransactionalDdl() && count($sqls) > 1) {
                $pdo->commit();
            }
            $this->logger->info('PartitionMaintain dropped', array(
                'table'      => $fullTableName,
                'partitions' => $toDrop,
            ));
            $result->partitionsDropped += count($toDrop);
            // 注意：PG 下 $toDrop 是逻辑分区数，SQL 条数可能不同。
            // partitionsDropped 以逻辑分区数为准，不重复计数。
        } catch (\Throwable $e) {
            if ($this->dialect->supportsTransactionalDdl()
                && count($sqls) > 1
                && $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }
            $result->errors++;
            $result->errorDetails[] = array(
                'table' => $fullTableName,
                'error' => $e->getMessage(),
            );
            $this->logger->error('PartitionMaintain drop failed', array(
                'table' => $fullTableName,
                'error' => $e->getMessage(),
            ));
        }
    }

    /**
     * 估算最近的下一个分区边界（取所有注册表中最小的 nextBoundary）。
     *
     * @return int
     */
    private function estimateNextBoundary(): int
    {
        $now = time();
        $nearest = $now + 86400 * 365; // 1 年后兜底

        $configs = $this->registry->getAll();
        foreach ($configs as $config) {
            $boundary = PartitionPeriod::nextBoundary($now, $config->period);
            if ($boundary < $nearest) {
                $nearest = $boundary;
            }
        }

        return $nearest;
    }

    /**
     * 自动检测触发源。
     *
     * @return string
     */
    private function detectTrigger(): string
    {
        // 尝试从 PHP SAPI 判断
        $sapi = PHP_SAPI;
        if ($sapi === 'cli') {
            // 进一步区分为 CLI 脚本还是 Scheduler Job
            global $argv;
            if (isset($argv[0]) && strpos($argv[0], 'scheduler') !== false) {
                return 'scheduler';
            }
            return 'cli';
        }

        // 后台管理操作通过 $_SERVER 参数或请求特征判断较复杂，
        // 统一归为 admin，由 PartitionAdminController 设置特征标记
        return 'lazy';
    }
}