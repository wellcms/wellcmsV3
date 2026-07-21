<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

use Framework\Database\Query\Grammar\GrammarInterface;

class PgSqlGrammar implements GrammarInterface
{
    // 移除所有缓存逻辑以防止内存溢出和泄露，尤其是大规模 IN 查询时的序列化开销。
    // SQL 构建开销极低，移除缓存可显著提升在 Swoole/FPM 长周期环境下的稳定性。

    public /** @var null */
    static $hasBtreeGist = null;

    /** @var array|null prepareSchema 正则缓存 patterns */
    protected static $schemaPatterns = null;

    /** @var array|null prepareSchema 正则缓存 replacements */
    protected static $schemaReplacements = null;

    /** @var array */
    protected $reservedMap = [
        'ctid' => '_ctid',
        'oid'  => '_oid',
        'xmin' => '_xmin',
        'xmax' => '_xmax',
    ];

    public function wrap(string $id): string
    {
        if ($id === '*' || false !== strpos($id, ' ')) return $id;

        // 处理类似 table.* 或 table.column
        if (strpos($id, '.') !== false) {
            [$table, $column] = explode('.', $id, 2);
            $wrappedTable = "\"{$table}\"";

            $lowerCol = strtolower($column);
            if (isset($this->reservedMap[$lowerCol])) {
                $column = $this->reservedMap[$lowerCol];
            }

            $wrappedColumn = ($column === '*') ? $column : "\"{$column}\"";
            return "{$wrappedTable}.{$wrappedColumn}";
        }

        $lowerId = strtolower($id);
        if (isset($this->reservedMap[$lowerId])) {
            $id = $this->reservedMap[$lowerId];
        }

        // 普通字段名
        return "\"{$id}\"";
    }

    protected function placeholder(int $index): string
    {
        return '?';
    }

    public function compileSelect(
        array $columns,
        string $table,
        array $joins = [],
        array $wheres = [],
        array $orders = [],
        ?int $limit = null,
        ?int $offset = null
    ): string {
        // 自动为重命名的保留列添加别名 (例如 SELECT "_ctid" AS "ctid")
        $selectCols = [];
        foreach ($columns as $col) {
            $wrapped = $this->wrap($col);
            $lowerCol = strtolower($col);
            if (isset($this->reservedMap[$lowerCol])) {
                $selectCols[] = "{$wrapped} AS \"{$col}\"";
            } else {
                $selectCols[] = $wrapped;
            }
        }

        // 基础 SELECT、FROM
        $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM ' . $this->wrap($table);

        // JOIN
        if ($joins) {
            $sql .= ' ' . implode(' ', $joins);
        }

        // WHERE：先拼接带 ? 的模板，然后替换占位
        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        // ORDER BY
        if ($orders) {
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        // LIMIT / OFFSET
        if (null !== $limit) {
            $sql .= ' LIMIT ' . $limit;
            if (null !== $offset) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        return $sql;
    }

    public function compileInsert(string $table, array $data, bool $batch = false): string
    {
        $cols = array_keys($batch ? $data[0] : $data);
        $wrapped = array_map([$this, 'wrap'], $cols);

        if ($batch) {
            $cols = array_keys($data[0]);
            $wrapped = array_map([$this, 'wrap'], $cols);
            $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->wrap($table),
                implode(',', $wrapped),
                implode(',', array_fill(0, count($data), $place))
            );
        } else {
            $ph = array_fill(0, count($cols), '?');
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->wrap($table),
                implode(',', $wrapped),
                implode(',', $ph)
            );
        }

        return $sql;
    }

    public function compileUpdate(string $table, array $data, array $wheres): string
    {
        $sets = [];
        foreach ($data as $col => $_row) {
            $operator = substr($col, -1);
            if ('+' === $operator || '-' === $operator) {
                $col = substr($col, 0, -1);
                $sets[] = "{$this->wrap($col)} = {$this->wrap($col)} " . ('+' === $operator ? '+' : '-') . " ?";
            } else {
                $sets[] = "{$this->wrap($col)} = ?";
            }
        }
        $sql = 'UPDATE ' . $this->wrap($table) . ' SET ' . implode(', ', $sets);
        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        return $sql;
    }

    public function compileBulkUpdate(string $table, string $key, array $rows, array $wheres = []): string
    {
        $columns = [];
        if (!empty($rows)) {
            foreach ($rows[0] as $col => $val) {
                if ($col !== $key) $columns[] = $col;
            }
        }

        $setClauses = [];
        foreach ($columns as $col) {
            $operator = substr($col, -1);
            $realCol = $col;
            $isRelative = ('+' === $operator || '-' === $operator);
            if ($isRelative) {
                $realCol = substr($col, 0, -1);
            }

            $whens = [];
            foreach ($rows as $_) {
                $whens[] = "WHEN " . $this->wrap($key) . " = ? THEN " .
                    ($isRelative ? $this->wrap($realCol) . " " . $operator . " ?" : "?");
            }
            $setClauses[] = $this->wrap($realCol) . " = CASE " . implode(' ', $whens) . " END";
        }

        $ids = array_fill(0, count($rows), '?');

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s IN (%s)',
            $this->wrap($table),
            implode(', ', $setClauses),
            $this->wrap($key),
            implode(', ', $ids)
        );

        if ($wheres) {
            $sql .= ' AND ' . implode(' AND ', $wheres);
        }

        return $sql;
    }

    public function compileDelete(string $table, array $wheres): string
    {
        $sql = 'DELETE FROM ' . $this->wrap($table);
        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        return $sql;
    }

    public function compileTableListing(): string
    {
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname != 'pg_catalog' AND schemaname != 'information_schema'";
    }

    public function compileColumnListing(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT column_name as \"Field\", data_type as \"Type\", is_nullable as \"Null\", column_default as \"Default\"
                FROM information_schema.columns
                WHERE table_name = '{$safe}'
                AND table_schema = CURRENT_SCHEMA()";
    }

    public function compileIndexListing(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT indexname as \"Key_name\", indexdef FROM pg_indexes WHERE tablename = '{$safe}'";
    }

    public function compileTableExists(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = '{$safe}'";
    }

    public function compileSetCharset(string $charset, string $collation): string
    {
        // PostgreSQL 字符集设置通常在连接 DSN 或通过 SET client_encoding
        return "SET client_encoding TO '{$charset}'";
    }

    public function compileIsolationLevel(string $level): string
    {
        // 对应 PostgreSQL 标准语法
        return "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$level}";
    }

    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrap($table) . ' RESTART IDENTITY CASCADE';
    }

    public function compileSchema(string $schema): string
    {
        $schemas = array_map(function ($s) {
            return '"' . trim($s) . '"';
        }, explode(',', $schema));
        return 'SET search_path TO ' . implode(', ', $schemas);
    }

    public function getSequenceName(string $table): ?string
    {
        // 1. 排除无序列的系统表 (kv, cache, session 等)
        // WellCMS 3.0 session 表使用字符串 ID，不使用自增序列
        $nonAutoIncrementKeywords = ['kv', 'cache', 'session', 'index', 'access'];
        foreach ($nonAutoIncrementKeywords as $kw) {
            if (stripos($table, $kw) !== false) return null;
        }

        // 2. 统一主键规范：WellCMS 3.0 已全面重构，所有表主键统一为 "id"
        // 对应的 PostgreSQL SERIAL/BIGSERIAL 默认序列名为 {table}_id_seq
        return "{$table}_id_seq";
    }

    public function prepareSchema(string $sql): array
    {
        // 1. 彻底预处理：利用数组一次性替换，减少内存拷贝开销
        // 移除块注释、标准行注释 和 MySQL 风格行注释
        $sql = preg_replace([
            '/\/\*.*?\*\//s',
            '/--.*$/m',
            '/#.*$/m'
        ], '', $sql);

        // 2. 基础替换：引号和 MySQL 特有前缀/后缀清理
        $sql = str_replace('`', '"', $sql);

        if (self::$schemaPatterns === null) {
            $mappings = [
                // 自增主键处理 (必须最先处理)
                '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' BIGSERIAL ',
                '/\bBIGINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'                     => ' BIGSERIAL ',
                '/\bINT\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'    => ' SERIAL ',
                '/\bINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'                        => ' SERIAL ',
                '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' SERIAL ',
                '/\bSMALLINT\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'  => ' SMALLSERIAL ',
                '/\bSMALLINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'                       => ' SMALLSERIAL ',

                // MySQL 特有属性清理
                '/\bUNSIGNED\b/i'            => ' ',
                '/\bZEROFILL\b/i'            => ' ',
                '/\bBINARY\b(?!\s*\(16\)|\s*\(32\))/i' => ' ', // 移除作为属性的 BINARY，保留作为类型的
                '/\bCHARACTER\s+SET\s+\w+/i'  => ' ',
                '/\bCOLLATE\s*=\s*[\w_]+/i'   => ' ',
                '/\bCOLLATE\s+[\w_]+/i'       => ' ',
                '/\bENGINE\s*=\s*\w+/i'       => ' ',
                '/\bAUTO_INCREMENT\s*=\s*\d+/i' => ' ',
                '/\bDEFAULT\s+CHARSET\s*=\s*\w+/i' => ' ',
                '/\bCOMMENT\s*=\s*\'.*?\'/i'  => ' ',
                '/\bCOMMENT\s+\'.*?\'/i'      => ' ',
                '/ON\s+UPDATE\s+CURRENT_TIMESTAMP/i' => ' ', // PgSQL 不支持列级自动更新，需剔除

                // 无符号整数溢出保护 (关键修复：MySQL INT UNSIGNED -> Postgres INTEGER)
                // 确保替换结果前后有空格，防止与 NOT NULL 粘连
                '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' NUMERIC(20) ',
                '/\bINT\s*(\(\d+\))?\s+UNSIGNED\b/i'      => ' INTEGER ',
                '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' INTEGER ',
                '/\bMEDIUMINT\s*(\(\d+\))?\s+UNSIGNED\b/i' => ' INTEGER ',
                '/\bSMALLINT\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' INTEGER ',
                '/\bTINYINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' SMALLINT ',

                // 精确基础类型转换
                '/\bBIGINT\b(\s*\(\d+\))?/i'      => ' BIGINT ',
                '/\bINT\b(\s*\(\d+\))?/i'         => ' INTEGER ',
                '/\bINTEGER\b(\s*\(\d+\))?/i'     => ' INTEGER ',
                '/\bMEDIUMINT\b(\s*\(\d+\))?/i'   => ' INTEGER ',
                '/\bSMALLINT\b(\s*\(\d+\))?/i'    => ' SMALLINT ',
                '/\bTINYINT\b(\s*\(\d+\))?/i'     => ' SMALLINT ',
                '/\bBOOL(EAN)?\b/i'               => ' SMALLINT ',
                '/\bBIT\s*(\(\d+\))?/i'           => ' INTEGER ',

                // 浮点数处理
                '/\bDOUBLE\b(\s*PRECISION)?/i'    => ' DOUBLE PRECISION ',
                '/\bFLOAT\b(\s*\(\d+(,\d+)?\))?/i' => ' REAL ',
                '/\bDECIMAL\s*\((\d+),(\d+)\)/i' => ' NUMERIC($1,$2) ',
                '/\bDECIMAL\s*\((\d+)\)/i'   => ' NUMERIC($1,0) ',

                // 文本与二进制
                '/LONGTEXT/i'                => ' TEXT ',
                '/MEDIUMTEXT/i'              => ' TEXT ',
                '/TINYTEXT/i'                => ' TEXT ',
                '/\bTEXT\b/i'                => ' TEXT ',
                '/\bJSON\b/i'                => ' TEXT ', // 遵循规范使用 TEXT 存储 JSON
                '/\bBLOB\b/i'                => ' BYTEA ',
                '/\bMEDIUMBLOB\b/i'          => ' BYTEA ',
                '/\bLONGBLOB\b/i'            => ' BYTEA ',
                '/\bBINARY\s*\(16\)/i'       => ' BYTEA ',
                '/\bBINARY\s*\(32\)/i'       => ' BYTEA ',
                '/\bVARBINARY\s*\(16\)/i'    => ' BYTEA ',
                '/\bVARBINARY\s*\(32\)/i'    => ' BYTEA ',

                // INET 网络地址类型原生支持包含操作符 (<<) 范围搜索
                '/DATETIME/i'                => ' TIMESTAMP ',
                '/TIMESTAMP\b/i'             => ' TIMESTAMP ',
                '/\bYEAR\b/i'                => ' SMALLINT ',

                '/\bCHAR\s*\((\d+)\)/i'      => ' VARCHAR($1) ',

            ];

            // PostgreSQL 系统保留列冲突强制转换 (ctid, oid, xmin, xmax)
            foreach ($this->reservedMap as $old => $new) {
                $mappings["/\"{$old}\"/i"] = "\"{$new}\"";
                $mappings["/\b{$old}\b/i"] = $new;
            }

            self::$schemaPatterns = array_keys($mappings);
            self::$schemaReplacements = array_values($mappings);
        }

        $sql = preg_replace(self::$schemaPatterns, self::$schemaReplacements, $sql);

        // 4. 解析与重构：根据分号分割语句（排除掉可能在引号内的分号通常很难，但建表 SQL 中分号主要用于结尾）
        $rawQueries = preg_split('/;(?:\s*[\r\n]|$)+/s', $sql);
        $finalQueries = [];

        foreach ($rawQueries as $q) {
            $q = trim($q);
            if (empty($q)) continue;

            $upperQ = strtoupper($q);

            if (strpos($upperQ, 'CREATE TABLE') !== false) {
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?["`]?(\w+)["`]?/i', $q, $tnMatch)) {
                    $tableName = $tnMatch[1];

                    // --- 1. 提取并处理分区 (PARTITION BY RANGE... [SUBPARTITION BY HASH]) ---
                    $partitionClause = '';
                    $partitionTables = [];
                    // 增强语法匹配：不仅支持标准 RANGE 分区，还支持带 HASH 子分区的复杂语法
                    // 格式: PARTITION BY RANGE (col) [SUBPARTITION BY HASH (col) SUBPARTITIONS x] ( PARTITION p1 ... )
                    if (preg_match('/PARTITION\s+BY\s+RANGE\s*\(([^)]+)\)(?:\s+SUBPARTITION\s+BY\s+HASH\s*\(([^)]+)\)\s+SUBPARTITIONS\s+(\d+))?\s*\((.*)\)/is', $q, $partMatch, PREG_OFFSET_CAPTURE)) {
                        $partField = trim($partMatch[1][0], ' "`');
                        // 提取子分区字段和数量（如果存在）
                        $subPartField = (isset($partMatch[2]) && !empty($partMatch[2][0])) ? trim($partMatch[2][0], ' "`') : '';
                        $subPartCount = (isset($partMatch[3]) && !empty($partMatch[3][0])) ? (int)$partMatch[3][0] : 0;

                        $partitionClause = " PARTITION BY RANGE (\"{$partField}\")";
                        $pListText = $partMatch[4][0];

                        // 提取单个分区定义
                        if (preg_match_all('/PARTITION\s+(\w+)\s+VALUES\s+LESS\s+THAN\s*\(?([^\s),]+)\)?/i', $pListText, $pMatches, PREG_SET_ORDER)) {
                            $prevLimit = 'MINVALUE';
                            foreach ($pMatches as $pm) {
                                $pName = $pm[1];
                                $pLimit = trim($pm[2]);
                                // 处理 MAXVALUE 边界
                                $finalLimit = (strtoupper($pLimit) === 'MAXVALUE') ? 'MAXVALUE' : $pLimit;

                                $basePartitionName = "{$tableName}_{$pName}";

                                if ($subPartCount > 0) {
                                    // 核心改进：在 PostgreSQL 中，如果父表的分区还需要进一步分区，
                                    // 必须在创建分区表时显式指定其分区方式 (PARTITION BY HASH)
                                    $partitionTables[] = "CREATE TABLE IF NOT EXISTS \"{$basePartitionName}\" PARTITION OF \"{$tableName}\" FOR VALUES FROM ({$prevLimit}) TO ({$finalLimit}) PARTITION BY HASH (\"{$subPartField}\");";
                                    // 创建实际存储数据的三级分区（子分区）
                                    for ($s = 0; $s < $subPartCount; $s++) {
                                        $partitionTables[] = "CREATE TABLE IF NOT EXISTS \"{$basePartitionName}_s{$s}\" PARTITION OF \"{$basePartitionName}\" FOR VALUES WITH (modulus {$subPartCount}, remainder {$s});";
                                    }
                                } else {
                                    // 标准两级范围分区
                                    $partitionTables[] = "CREATE TABLE IF NOT EXISTS \"{$basePartitionName}\" PARTITION OF \"{$tableName}\" FOR VALUES FROM ({$prevLimit}) TO ({$finalLimit});";
                                }
                                $prevLimit = $finalLimit;
                            }
                        }
                        // 移除建表语句末尾的所有分区定义，准备拼接标准建表 SQL
                        $q = substr($q, 0, (int)$partMatch[0][1]);
                    }

                    // --- 1.2 提取并处理哈希分区 (PARTITION BY HASH) ---
                    if (empty($partitionClause) && preg_match('/PARTITION\s+BY\s+HASH\s*\(([^)]+)\)\s+PARTITIONS\s+(\d+)/is', $q, $hashMatch, PREG_OFFSET_CAPTURE)) {
                        $partField = trim($hashMatch[1][0], ' "`');
                        $partitionCount = (int)$hashMatch[2][0];
                        $partitionClause = " PARTITION BY HASH (\"{$partField}\")";

                        for ($i = 0; $i < $partitionCount; $i++) {
                            $partitionTables[] = "CREATE TABLE IF NOT EXISTS \"{$tableName}_p{$i}\" PARTITION OF \"{$tableName}\" FOR VALUES WITH (modulus {$partitionCount}, remainder {$i});";
                        }
                        // 移除建表语句末尾的分区定义
                        $q = substr($q, 0, (int)$hashMatch[0][1]);
                    }

                    // 提取 UNIQUE KEY 和 KEY（排除 PRIMARY KEY）
                    preg_match_all('/(?<!PRIMARY\s)(?:UNIQUE\s+)?KEY\s+["`]?(\w+)["`]?\s+\((.*?)\)/i', $q, $idxMatches, PREG_SET_ORDER);

                    // 移除建表语句内的索引定义（极其精准地避开 PRIMARY KEY）
                    $q = preg_replace('/,?\s*(?<!PRIMARY\s)(?:UNIQUE\s+)?KEY\s+["`]?\w+["`]?\s+\(.*?\)|\s*,?\s*(?<!PRIMARY\s)(?:UNIQUE\s+)?KEY\s+\(.*?\)/i', '', $q);

                    // --- 3. 提取 PRIMARY KEY 以防它是分区表 ---
                    // 如果是分区表，PgSQL 的 PK 必须包含分区字段，我们将其下移以防冲突
                    if ($partitionClause && preg_match('/PRIMARY\s+KEY\s+\((.*?)\)/i', $q, $pkMatch)) {
                        // 此处不做自动合并分区键（太危险），保留原样，由应用保证
                    }

                    // 修正末尾逗号并清理空白
                    $q = preg_replace('/,\s*\)/', ')', $q);

                    // 额外加固：如果 $q 内部依然残留有 PARTITION BY (说明之前的正则可能跳过了某些字符)，强制截断到最后一个 )
                    if ($partitionClause && stripos($q, 'PARTITION BY') !== false) {
                        $q = substr($q, 0, stripos($q, 'PARTITION BY'));
                    }

                    $q = preg_replace('/\s+/', ' ', $q);

                    // 拼接基础建表语句
                    $finalQueries[] = trim($q, " ;") . $partitionClause . ';';

                    // 拼接分区表定义
                    foreach ($partitionTables as $pTable) {
                        $finalQueries[] = $pTable;
                    }

                    // 转换为独立的 CREATE INDEX 语句
                    $useGist = (self::$hasBtreeGist === true);
                    $hasGistUsed = false;

                    foreach ($idxMatches as $match) {
                        $isUnique = stripos($match[0], 'UNIQUE') !== false;
                        $idxName = $match[1];
                        $idxCols = preg_replace('/\(\d+\)/', '', $match[2]);

                        // --- 自动提升 IP 索引为 GiST (自适应逻辑) ---
                        // 匹配包含 ip 字样的字段名，且环境支持 btree_gist
                        if ($useGist && stripos($idxCols, 'ip') !== false) {
                            $finalQueries[] = ($isUnique ? "CREATE UNIQUE INDEX" : "CREATE INDEX") .
                                " \"idx_{$tableName}_{$idxName}\" ON \"{$tableName}\" USING gist ({$idxCols});";
                            $hasGistUsed = true;
                        } else {
                            $finalQueries[] = ($isUnique ? "CREATE UNIQUE INDEX " : "CREATE INDEX ") .
                                " \"idx_{$tableName}_{$idxName}\" ON \"{$tableName}\" ({$idxCols});";
                        }
                    }

                    // 如果检测到环境支持扩展，则在建表前注入扩展安装指令
                    if ($hasGistUsed) {
                        $extensionStmt = 'CREATE EXTENSION IF NOT EXISTS btree_gist;';
                        if (!in_array($extensionStmt, $finalQueries)) {
                            array_unshift($finalQueries, $extensionStmt);
                        }
                    }
                } else {
                    $finalQueries[] = $q . ';';
                }
            } elseif (strpos($upperQ, 'ALTER TABLE') !== false) {
                // 处理 ALTER TABLE 各种动作
                if (preg_match('/ALTER\s+TABLE\s+["`]?(\w+)["`]?\s+(MODIFY|CHANGE|ADD|RENAME|DROP)\s+(.*)/i', $q, $alterMatch)) {
                    $tableName = $alterMatch[1];
                    $action = strtoupper($alterMatch[2]);
                    $body = trim($alterMatch[3]);

                    if ($action === 'MODIFY') {
                        // MODIFY col type ...
                        // 匹配列名和类型，忽略其他属性
                        if (preg_match('/["`]?(\w+)["`]?\s+([\w\s\(\),]+)/i', $body, $m)) {
                            $columnName = $m[1];
                            $columnType = trim(explode(' ', $m[2])[0]); // Just take the base type
                            $finalQueries[] = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$columnName}\" TYPE {$columnType};";
                        } else {
                            $finalQueries[] = trim($q, " ;") . ';'; // Fallback if regex fails
                        }
                    } elseif ($action === 'CHANGE') {
                        // CHANGE old_col new_col type ...
                        if (preg_match('/["`]?(\w+)["`]?\s+["`]?(\w+)["`]?\s+([\w\s\(\),]+)/i', $body, $m)) {
                            $oldColumnName = $m[1];
                            $newColumnName = $m[2];
                            $columnType = trim(explode(' ', $m[3])[0]); // Just take the base type
                            $finalQueries[] = "ALTER TABLE \"{$tableName}\" RENAME COLUMN \"{$oldColumnName}\" TO \"{$newColumnName}\";";
                            $finalQueries[] = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$newColumnName}\" TYPE {$columnType};";
                        } else {
                            $finalQueries[] = trim($q, " ;") . ';'; // Fallback
                        }
                    } elseif ($action === 'ADD') {
                        // ADD COLUMN col type ... (保持原样，只换引号)
                        // MySQL: ADD COLUMN `col` INT DEFAULT 0
                        // PgSQL: ADD COLUMN "col" INT DEFAULT 0
                        $finalQueries[] = str_replace('`', '"', trim($q, " ;")) . ';';
                    } elseif ($action === 'DROP') {
                        // DROP COLUMN col
                        // MySQL: DROP COLUMN `col`
                        // PgSQL: DROP COLUMN "col"
                        $finalQueries[] = str_replace('`', '"', trim($q, " ;")) . ';';
                    } elseif ($action === 'RENAME') {
                        // RENAME TO new_table (MySQL syntax)
                        // PgSQL: ALTER TABLE old_table RENAME TO new_table
                        if (preg_match('/RENAME\s+TO\s+["`]?(\w+)["`]?/i', $body, $m)) {
                            $newTableName = $m[1];
                            $finalQueries[] = "ALTER TABLE \"{$tableName}\" RENAME TO \"{$newTableName}\";";
                        } else {
                            $finalQueries[] = $q . ';'; // Fallback
                        }
                    } else {
                        $finalQueries[] = $q . ';';
                    }
                } elseif (stripos($q, 'ALTER TABLE') !== false && stripos($q, 'AUTO_INCREMENT') !== false) {
                    continue; // 忽略 MySQL 的 AUTO_INCREMENT 修改
                } else {
                    $finalQueries[] = $q . ';';
                }
            } elseif (strpos($upperQ, 'INSERT INTO') !== false && strpos($upperQ, 'ON DUPLICATE KEY UPDATE') !== false) {
                // 简单映射 ON DUPLICATE KEY UPDATE -> ON CONFLICT DO UPDATE
                // 注意：由于无法在此可靠获取主键名，此块仅做基础替换，建议避免复杂用法
                $q = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT DO UPDATE SET', $q);
                $finalQueries[] = $q . ';';
            } else {
                $finalQueries[] = preg_replace('/\s+/', ' ', trim($q, " ;")) . ';';
            }
        }

        return $finalQueries;
    }

    public static function clearCache(): void
    {
        self::$hasBtreeGist = null;
        self::$schemaPatterns = null;
        self::$schemaReplacements = null;
    }
}
