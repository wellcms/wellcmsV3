<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

use Framework\Database\Query\Grammar\GrammarInterface;

class OracleGrammar implements GrammarInterface
{
    // 移除所有缓存逻辑以防止内存溢出和泄露。

    protected function placeholder(int $index): string
    {
        return ':param' . ($index + 1);
    }

    public function wrap(string $id): string
    {
        if ($id === '*' || false !== strpos($id, ' ')) return $id;

        // 处理类似 table.* 或 table.column
        if (strpos($id, '.') !== false) {
            [$table, $column] = explode('.', $id, 2);
            $wrappedTable = '"' . strtoupper($table) . '"'; // 假设表名存储为大写
            $wrappedColumn = ($column === '*') ? $column : '"' . strtoupper($column) . '"';
            return "{$wrappedTable}.{$wrappedColumn}";
        }

        // 普通字段名
        return '"' . strtoupper($id) . '"';
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
        // 先生成核心子查询
        $inner = 'SELECT '
            . implode(', ', array_map([$this, 'wrap'], $columns))
            . ' FROM ' . $this->wrap($table);

        if ($joins) {
            $inner .= ' ' . implode(' ', $joins);
        }
        if ($wheres) {
            $combined = implode(' AND ', $wheres);
            $ctr = 0;
            $combined = preg_replace_callback('/\?/', function () use (&$ctr) {
                return $this->placeholder($ctr++);
            }, $combined);
            $inner .= ' WHERE ' . $combined;
        }
        if ($orders) {
            $inner .= ' ORDER BY ' . implode(', ', $orders);
        }

        // 如果未指定分页，直接返回
        if (null === $limit) {
            return $inner;
        }

        // Oracle 分页嵌套 ROWNUM
        $off = $offset ?? 0;
        $max = $off + $limit;
        $sql = "SELECT * FROM ( SELECT a.*, ROWNUM rnum FROM ( {$inner} ) a WHERE ROWNUM <= {$max} ) WHERE rnum > {$off}";
        return $sql;
    }

    public function compileInsert(string $table, array $data, bool $batch = false): string
    {
        // 与 SQL Server 逻辑相同，只是 wrap 与 placeholder 不同
        $sqlServer = new SqlServerGrammar();
        return strtr($sqlServer->compileInsert($table, $data, $batch), ['@param' => ':param']);
    }

    public function compileUpdate(string $table, array $data, array $wheres): string
    {
        $sqlServer = new SqlServerGrammar();
        return strtr($sqlServer->compileUpdate($table, $data, $wheres), ['@param' => ':param']);
    }

    public function compileBulkUpdate(string $table, string $key, array $rows, array $wheres = []): string
    {
        $paramIndex = 0;
        $cases = [];
        $columns = [];
        if (!empty($rows)) {
            foreach ($rows[0] as $col => $val) {
                if ($col !== $key) $columns[] = $col;
            }
        }

        foreach ($columns as $col) {
            foreach ($rows as $row) {
                $wrappedKey = $this->wrap($key);
                $whenKey = $this->placeholder($paramIndex++);
                $whenVal = $this->placeholder($paramIndex++);
                $cases[$col][] = "WHEN {$wrappedKey} = {$whenKey} THEN {$whenVal}";
            }
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->placeholder($paramIndex++);
        }

        $setClauses = [];
        foreach ($cases as $col => $whens) {
            $wrappedCol = $this->wrap($col);
            $setClauses[] = "{$wrappedCol} = CASE " . implode(' ', $whens) . " END";
        }

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
        $sqlServer = new SqlServerGrammar();
        return strtr($sqlServer->compileDelete($table, $wheres), ['@param' => ':param']);
    }

    public function compileTableListing(): string
    {
        return "SELECT table_name FROM user_tables";
    }

    public function compileColumnListing(string $table): string
    {
        $safe = strtoupper(str_replace("'", "''", $table));
        return "SELECT column_name AS \"Field\", data_type AS \"Type\", nullable AS \"Null\", data_default AS \"Default\" 
                FROM user_tab_columns WHERE table_name = '{$safe}'";
    }

    public function compileIndexListing(string $table): string
    {
        $safe = strtoupper(str_replace("'", "''", $table));
        return "SELECT index_name AS \"Key_name\" FROM user_indexes WHERE table_name = '{$safe}'";
    }

    public function compileTableExists(string $table): string
    {
        $safe = strtoupper(str_replace("'", "''", $table));
        return "SELECT table_name FROM user_tables WHERE table_name = '{$safe}'";
    }

    public function compileSetCharset(string $charset, string $collation): string
    {
        return "ALTER SESSION SET NLS_LANGUAGE = 'AMERICAN'";
    }

    public function compileIsolationLevel(string $level): string
    {
        // Oracle 支持 READ COMMITTED 和 SERIALIZABLE
        return "SET TRANSACTION ISOLATION LEVEL {$level}";
    }

    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrap($table);
    }

    public function compileSchema(string $schema): string
    {
        return "ALTER SESSION SET CURRENT_SCHEMA = " . strtoupper($schema);
    }

    public function getSequenceName(string $table): ?string
    {
        return null;
    }

    public function prepareSchema(string $sql): array
    {
        // 1. 彻底预处理：移除注释
        $sql = preg_replace([
            '/\/\*.*?\*\//s',
            '/--.*$/m',
            '/#.*$/m'
        ], '', $sql);

        // 2. 数据类型与属性映射转换
        $mappings = [
            // 自增主键处理 (必须最先处理)
            '/\bBIGINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' NUMBER(20) GENERATED AS IDENTITY PRIMARY KEY ',
            '/\bINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'    => ' NUMBER(19) GENERATED AS IDENTITY PRIMARY KEY ',
            '/\bINTEGER\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' NUMBER(19) GENERATED AS IDENTITY PRIMARY KEY ',

            // MySQL 特有属性清理
            '/\bUNSIGNED\b/i'            => ' ',
            '/\bZEROFILL\b/i'            => ' ',
            '/\bBINARY\b(?!\s*\(16\)|\s*\(32\))/i' => ' ',
            '/\bCHARACTER\s+SET\s+\w+/i'  => ' ',
            '/\bCOLLATE\s*=\s*[\w_]+/i'   => ' ',
            '/\bCOLLATE\s+[\w_]+/i'       => ' ',
            '/\bENGINE\s*=\s*\w+/i'       => ' ',
            '/\bAUTO_INCREMENT\s*=\s*\d+/i' => ' ',
            '/\bDEFAULT\s+CHARSET\s*=\s*\w+/i' => ' ',
            '/\bCOMMENT\s*=\s*\'.*?\'/i'  => ' ',
            '/\bCOMMENT\s+\'.*?\'/i'      => ' ',
            '/ON\s+UPDATE\s+CURRENT_TIMESTAMP/i' => ' ',

            // 整数映射
            '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' NUMBER(20) ',
            '/\bINT\s*(\(\d+\))?\s+UNSIGNED\b/i'      => ' NUMBER(19) ',
            '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' NUMBER(19) ',
            '/\bMEDIUMINT\s*(\(\d+\))?\s+UNSIGNED\b/i' => ' NUMBER(10) ',
            '/\bSMALLINT\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' NUMBER(6) ',
            '/\bTINYINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' NUMBER(3) ',

            '/\bBIGINT\b(\s*\(\d+\))?/i'      => ' NUMBER(19) ',
            '/\bINT\b(\s*\(\d+\))?/i'         => ' NUMBER(19) ',
            '/\bINTEGER\b(\s*\(\d+\))?/i'     => ' NUMBER(19) ',
            '/\bMEDIUMINT\b(\s*\(\d+\))?/i'   => ' NUMBER(10) ',
            '/\bSMALLINT\b(\s*\(\d+\))?/i'    => ' NUMBER(6) ',
            '/\bTINYINT\b(\s*\(\d+\))?/i'     => ' NUMBER(3) ',
            '/\bBOOL(EAN)?\b/i'               => ' NUMBER(1) ',
            '/\bBIT\s*(\(\d+\))?/i'           => ' NUMBER(38) ',

            // 浮点数与精确小数
            '/\bDOUBLE\b(\s*PRECISION)?/i'    => ' FLOAT(126) ',
            '/\bFLOAT\b(\s*\(\d+(,\d+)?\))?/i' => ' FLOAT ',
            '/\bDECIMAL\s*\((\d+),(\d+)\)/i'  => ' NUMBER($1,$2) ',
            '/\bDECIMAL\s*\((\d+)\)/i'        => ' NUMBER($1,0) ',

            // 文本/JSON/二进制
            '/LONGTEXT/i'                => ' CLOB ',
            '/MEDIUMTEXT/i'              => ' CLOB ',
            '/TINYTEXT/i'                => ' VARCHAR2(4000) ',
            '/\bJSON\b/i'                => ' CLOB ',
            '/\bBLOB\b/i'                => ' BLOB ',
            '/\bMEDIUMBLOB\b/i'          => ' BLOB ',
            '/\bLONGBLOB\b/i'            => ' BLOB ',
            '/\bBINARY\s*\(16\)/i'       => ' RAW(16) ',
            '/\bBINARY\s*\(32\)/i'       => ' RAW(32) ',
            '/\bVARBINARY\s*\(16\)/i'    => ' RAW(16) ',
            '/\bVARBINARY\s*\(32\)/i'    => ' RAW(32) ',

            // 日期时间 (遵循规范映射为 BIGINT/NUMBER)
            '/DATETIME/i'                => ' NUMBER(19) ',
            '/TIMESTAMP\b/i'             => ' NUMBER(19) ',
            '/\bYEAR\b/i'                => ' NUMBER(4) ',

            '/\bVARCHAR\s*\((\d+)\)/i'   => ' VARCHAR2($1) ',
            '/\bCHAR\s*\((\d+)\)/i'      => ' CHAR($1) ',
            '/`/'                        => '"',
        ];

        $sql = preg_replace(array_keys($mappings), array_values($mappings), $sql);

        // 4. 解析与重构
        $rawQueries = preg_split('/;(?:\s*[\r\n]|$)+/s', $sql);
        $finalQueries = [];

        foreach ($rawQueries as $q) {
            $q = trim($q);
            if (empty($q)) continue;

            $upperQ = strtoupper($q);

            if (strpos($upperQ, 'CREATE TABLE') !== false) {
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+EXISTS\s+)?"?(\w+)"?/i', $q, $tnMatch)) {
                    $tableName = strtoupper($tnMatch[1]);

                    // --- 1. 提取索引 ---
                    preg_match_all('/(?:UNIQUE\s+)?KEY\s+"(\w+)"\s+\((.*?)\)/i', $q, $idxMatches, PREG_SET_ORDER);

                    // 移除原有的 KEY 定义和重复主键声明
                    $q = preg_replace('/,?\s+PRIMARY\s+KEY\s+\(".*?"\)/i', '', $q);
                    $q = preg_replace('/,?(?:\s+)?(?:UNIQUE\s+)?KEY\s+".*?"\s+\(.*?\)/i', '', $q);

                    // --- 2. 移除分区定义 (Oracle 本身支持分区但语法复杂，此处默认扁平化处理) ---
                    if (stripos($q, 'PARTITION BY') !== false) {
                        $q = preg_replace('/PARTITION\s+BY\s+.*$/is', '', $q);
                    }

                    $q = preg_replace('/,\s*\)/', ')', $q);
                    $q = preg_replace('/\s+/', ' ', $q);

                    $finalQueries[] = trim($q, " ;") . ';';

                    // 转换为独立的 CREATE INDEX 语句
                    foreach ($idxMatches as $match) {
                        $isUnique = stripos($match[0], 'UNIQUE') !== false;
                        $idxName = strtoupper($match[1]);
                        $idxCols = preg_replace('/\(\d+\)/', '', $match[2]);
                        $finalQueries[] = ($isUnique ? "CREATE UNIQUE INDEX" : "CREATE INDEX") .
                            " \"IDX_{$tableName}_{$idxName}\" ON \"{$tableName}\" ({$idxCols});";
                    }
                } else {
                    $finalQueries[] = trim($q, " ;") . ';';
                }
            } elseif (strpos($upperQ, 'ALTER TABLE') !== false) {
                // 处理 ALTER TABLE (Oracle 使用 MODIFY)
                if (preg_match('/ALTER\s+TABLE\s+"?(\w+)"?\s+MODIFY\s+"?(\w+)"?\s+(.*)/i', $q, $alterMatch)) {
                    $tableName = strtoupper($alterMatch[1]);
                    $columnName = strtoupper($alterMatch[2]);
                    $rest = trim($alterMatch[3]);
                    $type = explode(' ', $rest)[0];
                    $finalQueries[] = "ALTER TABLE \"{$tableName}\" MODIFY \"{$columnName}\" {$type};";
                } else {
                    $finalQueries[] = trim($q, " ;") . ';';
                }
            } else {
                $finalQueries[] = preg_replace('/\s+/', ' ', trim($q, " ;")) . ';';
            }
        }
        return $finalQueries;
    }

    public static function clearCache(): void {}
}
