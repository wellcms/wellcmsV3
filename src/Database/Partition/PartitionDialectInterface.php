<?php
declare(strict_types=1);

namespace Framework\Database\Partition;

/**
 * 分区方言接口。
 *
 * 职责：封装不同数据库（MySQL / PostgreSQL）中与分区管理相关的
 * 元数据查询和 DDL 语法差异。
 *
 * 不包含的业务逻辑：
 * - 分区边界计算（PartitionPeriod 负责）
 * - 主维护流程编排（PartitionManager 负责）
 * - 注册信息管理（PartitionRegistry 负责）
 *
 * PHP 7.2 兼容：不使用 typed properties / union types。
 * 实现类必须无状态，可安全单例共享（Swoole 协程安全）。
 */
interface PartitionDialectInterface
{
    // ---------------------------------------------------------------
    // 元数据查询
    // ---------------------------------------------------------------

    /**
     * 查询指定表的分区列表的 SQL。
     *
     * 返回列要求（PDO::CASE_LOWER 强制列名小写）：
     * - partition_name:       分区名
     * - partition_description: 分区边界值（数值或 'MAXVALUE' 字面量）
     * - partition_ordinal_position: 分区序号（用于排序）
     *
     * 要求：每个父分区返回且仅返回一行（子分区表需去重）。
     *
     * @param string $fullTableName 带前缀的完整表名（如 'well_forum_thread'）
     * @return string 完整 SQL 语句
     */
    public function compilePartitionsQuery(string $fullTableName): string;

    // ---------------------------------------------------------------
    // 分区 DDL
    // ---------------------------------------------------------------

    /**
     * 构建"创建新分区"的 SQL。
     *
     * MySQL:  REORGANIZE PARTITION pmax INTO (新分区, pmax)
     * PgSQL:  CREATE TABLE IF NOT EXISTS ... PARTITION OF ...
     *         FOR VALUES FROM (prevBoundary) TO (boundary) [+ PARTITION BY HASH]
     *
     * 对于子分区表，返回多条 SQL（PG 的 3 层架构）。
     * 所有 CREATE TABLE 必须使用 IF NOT EXISTS，保证重入幂等。
     *
     * @param string          $fullTableName  带前缀的完整表名（父表引用）
     * @param int             $prevBoundary   当前最高分区边界（下界，Unix 时间戳）
     * @param string          $partitionName  新分区完整表名（含纯表名前缀，如 'forum_reply_p2026Q4'）
     * @param int             $boundary       新分区上界（Unix 时间戳）
     * @param PartitionConfig $config         分区配置（含子分区信息）
     * @return array<string>  1 条或多条 SQL 语句
     */
    public function compileCreatePartition(
        string $fullTableName,
        int $prevBoundary,
        string $partitionName,
        int $boundary,
        PartitionConfig $config
    ): array;

    /**
     * 构建"删除分区"的 SQL。
     *
     * MySQL:  ALTER TABLE t DROP PARTITION p1, p2
     * PgSQL:  DROP TABLE IF EXISTS p1 CASCADE (单步，自动解除分区关系)
     *
     * @param string   $fullTableName  带前缀的完整表名
     * @param string[] $partitionNames 要删除的分区表名列表
     * @return array<string> 1 条或多条 SQL 语句
     */
    public function compileDropPartitions(
        string $fullTableName,
        array $partitionNames
    ): array;

    // ---------------------------------------------------------------
    // 会话配置
    // ---------------------------------------------------------------

    /**
     * 编译 DDL 执行前的会话级超时设置 SQL。
     *
     * 在 createFreshConnection() 的专用 PDO 连接上执行，
     * 不影响业务连接。
     *
     * MySQL: lock_wait_timeout + innodb_lock_wait_timeout（MDL + 行锁）
     * PgSQL: lock_timeout + statement_timeout（表锁 + 语句超时）
     *
     * @return array<string> 0 条或多条 SET SESSION 语句
     */
    public function compileSessionSetup(): array;

    // ---------------------------------------------------------------
    // 能力查询
    // ---------------------------------------------------------------

    /**
     * 数据库是否支持事务性 DDL（DDL 可回滚）。
     *
     * PG 12+ : true  → PartitionManager 将多条 DDL 包裹在事务中
     * MySQL   : false → DDL 隐式提交，逐条执行
     *
     * @return bool
     */
    public function supportsTransactionalDdl(): bool;

    // ---------------------------------------------------------------
    // 标识符引用
    // ---------------------------------------------------------------

    /**
     * 引用标识符（表名、列名）。
     *
     * MySQL: `name`
     * PgSQL: "name"
     *
     * @param string $identifier
     * @return string 引用后的标识符
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * 安全引用字符串字面量（防 SQL 注入）。
     *
     * MySQL/PG: 'value'（内部转义单引号）
     *
     * @param string $value
     * @return string 带引号的字面量
     */
    public function quoteLiteral(string $value): string;
}
