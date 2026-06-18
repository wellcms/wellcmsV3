<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

use Framework\Database\Query\Grammar\GrammarInterface;

class SqlServerGrammar implements GrammarInterface
{
    // 移除所有缓存逻辑以防止内存溢出和泄露。

    public function wrap(string $id): string
    {
        if ($id === '*' || false !== strpos($id, ' ')) return $id;

        // 处理类似 table.* 或 table.column
        if (strpos($id, '.') !== false) {
            [$table, $column] = explode('.', $id, 2);
            $wrappedTable = "[{$table}]";
            $wrappedColumn = ($column === '*') ? $column : "[{$column}]";
            return "{$wrappedTable}.{$wrappedColumn}";
        }

        // 普通字段名
        return "[{$id}]";
    }

    protected function placeholder(int $index): string
    {
        // SQL Server 使用 @param1、@param2 … 命名参数
        return '@param' . ($index + 1);
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
        // 基础
        $sql = 'SELECT ' . implode(', ', array_map([$this, 'wrap'], $columns))
            . ' FROM ' . $this->wrap($table);

        // JOIN
        if ($joins) {
            $sql .= ' ' . implode(' ', $joins);
        }

        // WHERE
        if ($wheres) {
            $combined = implode(' AND ', $wheres);
            $counter = 0;
            $combined = preg_replace_callback('/\?/', function () use (&$counter) {
                return $this->placeholder($counter++);
            }, $combined);
            $sql .= ' WHERE ' . $combined;
        }

        // ORDER BY
        if ($orders) {
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        // SQL Server 分页必须有 ORDER BY
        if (null !== $offset || null !== $limit) {
            if (!$orders) {
                $sql .= ' ORDER BY (SELECT NULL)';
            }
            $off = $offset ?? 0;
            $lim = $limit  ?? PHP_INT_MAX;
            $sql .= " OFFSET {$off} ROWS FETCH NEXT {$lim} ROWS ONLY";
        }

        return $sql;
    }

    public function compileInsert(string $table, array $data, bool $batch = false): string
    {
        $cols = array_keys($batch ? $data[0] : $data);
        $wrapped = array_map([$this, 'wrap'], $cols);

        if ($batch) {
            $rows = [];
            $ctr = 0;
            foreach ($data as $row) {
                $ph = [];
                foreach ($row as $_) {
                    $ph[] = $this->placeholder($ctr++);
                }
                $rows[] = '(' . implode(',', $ph) . ')';
            }
            $sql = "INSERT INTO {$this->wrap($table)} (" . implode(',', $wrapped) . ") VALUES " . implode(',', $rows);
        } else {
            $ph = [];
            for ($i = 0; $i < count($cols); $i++) {
                $ph[] = $this->placeholder($i);
            }
            $sql = "INSERT INTO {$this->wrap($table)} (" . implode(',', $wrapped) . ") VALUES (" . implode(',', $ph) . ")";
        }

        return $sql;
    }

    public function compileUpdate(string $table, array $data, array $wheres): string
    {
        $sets = [];
        $ctr = 0;
        foreach ($data as $col => $_) {
            $operator = substr($col, -1);
            if ('+' === $operator || '-' === $operator) {
                $col = substr($col, 0, -1);
                $sets[] = "{$this->wrap($col)} = {$this->wrap($col)} " . ('+' === $operator ? '+' : '-') . " " . $this->placeholder($ctr++);
            } else {
                $sets[] = "{$this->wrap($col)} = " . $this->placeholder($ctr++);
            }
        }
        $sql = "UPDATE {$this->wrap($table)} SET " . implode(', ', $sets);
        if ($wheres) {
            $whereClauses = [];
            foreach ($wheres as $cond) {
                $whereClauses[] = preg_replace_callback('/\?/', function () use (&$ctr) {
                    return $this->placeholder($ctr++);
                }, $cond);
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }
        return $sql;
    }

    public function compileBulkUpdate(string $table, string $key, array $rows, array $wheres = []): string
    {
        $paramIndex = 0;
        $cases      = [];
        $columns    = [];
        if (!empty($rows)) {
            foreach ($rows[0] as $col => $val) {
                if ($col !== $key) $columns[] = $col;
            }
        }

        foreach ($columns as $col) {
            foreach ($rows as $row) {
                $whenKey = $this->placeholder($paramIndex++);
                $whenVal = $this->placeholder($paramIndex++);
                $cases[$col][] = "WHEN {$this->wrap($key)} = {$whenKey} THEN {$whenVal}";
            }
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->placeholder($paramIndex++);
        }

        $setClauses = [];
        foreach ($cases as $col => $whens) {
            $setClauses[] = "{$this->wrap($col)} = CASE " . implode(' ', $whens) . " END";
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
        $sql = "DELETE FROM {$this->wrap($table)}";
        if ($wheres) {
            $ctr = 0;
            $whereClauses = [];
            foreach ($wheres as $cond) {
                $whereClauses[] = preg_replace_callback('/\?/', function () use (&$ctr) {
                    return $this->placeholder($ctr++);
                }, $cond);
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }
        return $sql;
    }

    public function compileTableListing(): string
    {
        return "SELECT name FROM sys.tables";
    }

    public function compileColumnListing(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT name AS 'Field', TYPE_NAME(system_type_id) AS 'Type', CASE is_nullable WHEN 1 THEN 'YES' ELSE 'NO' END AS 'Null'
                FROM sys.columns WHERE object_id = OBJECT_ID('{$safe}')";
    }

    public function compileIndexListing(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT name AS 'Key_name' FROM sys.indexes WHERE object_id = OBJECT_ID('{$safe}')";
    }

    public function compileTableExists(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT name FROM sys.tables WHERE name = '{$safe}'";
    }

    public function compileSetCharset(string $charset, string $collation): string
    {
        return "/* SQL Server charset is governed by collation at column/database level */";
    }

    public function compileIsolationLevel(string $level): string
    {
        return "SET TRANSACTION ISOLATION LEVEL {$level}";
    }

    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrap($table);
    }

    public function compileSchema(string $schema): string
    {
        return "USE [" . $schema . "]";
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
            '/\bBIGINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' BIGINT IDENTITY(1,1) PRIMARY KEY ',
            '/\bINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'    => ' BIGINT IDENTITY(1,1) PRIMARY KEY ',
            '/\bINTEGER\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' BIGINT IDENTITY(1,1) PRIMARY KEY ',

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
            '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' DECIMAL(20,0) ',
            '/\bINT\s*(\(\d+\))?\s+UNSIGNED\b/i'      => ' BIGINT ',
            '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' BIGINT ',
            '/\bMEDIUMINT\s*(\(\d+\))?\s+UNSIGNED\b/i' => ' INT ',
            '/\bSMALLINT\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' INT ',
            '/\bTINYINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' SMALLINT ',

            '/\bBIGINT\b(\s*\(\d+\))?/i'      => ' BIGINT ',
            '/\bINT\b(\s*\(\d+\))?/i'         => ' BIGINT ',
            '/\bINTEGER\b(\s*\(\d+\))?/i'     => ' BIGINT ',
            '/\bMEDIUMINT\b(\s*\(\d+\))?/i'   => ' INT ',
            '/\bSMALLINT\b(\s*\(\d+\))?/i'    => ' SMALLINT ',
            '/\bTINYINT\b(\s*\(\d+\))?/i'     => ' SMALLINT ',
            '/\bBOOL(EAN)?\b/i'               => ' SMALLINT ',
            '/\bBIT\s*(\(\d+\))?/i'           => ' BIT ',

            // 浮点数与精确小数
            '/\bDOUBLE\b(\s*PRECISION)?/i'    => ' FLOAT ',
            '/\bFLOAT\b(\s*\(\d+(,\d+)?\))?/i' => ' REAL ',
            '/\bDECIMAL\s*\((\d+),(\d+)\)/i'  => ' DECIMAL($1,$2) ',
            '/\bDECIMAL\s*\((\d+)\)/i'        => ' DECIMAL($1,0) ',

            // 文本/JSON/二进制
            '/LONGTEXT/i'                => ' NVARCHAR(MAX) ',
            '/MEDIUMTEXT/i'              => ' NVARCHAR(MAX) ',
            '/TINYTEXT/i'                => ' NVARCHAR(MAX) ',
            '/\bJSON\b/i'                => ' NVARCHAR(MAX) ',
            '/\bBLOB\b/i'                => ' VARBINARY(MAX) ',
            '/\bMEDIUMBLOB\b/i'          => ' VARBINARY(MAX) ',
            '/\bLONGBLOB\b/i'            => ' VARBINARY(MAX) ',
            '/\bBINARY\s*\(16\)/i'       => ' BINARY(16) ',
            '/\bBINARY\s*\(32\)/i'       => ' VARBINARY(32) ',

            // 日期时间
            '/DATETIME/i'                => ' DATETIME2 ',
            '/TIMESTAMP\b/i'             => ' DATETIME2 ',
            '/\bYEAR\b/i'                => ' SMALLINT ',

            '/\bCHAR\s*\((\d+)\)/i'      => ' NVARCHAR($1) ',
            '/`/'                        => '"',
        ];

        $sql = preg_replace(array_keys($mappings), array_values($mappings), $sql);
        // 将引号标识符转换为 SQL Server 的方括号标识符
        $sql = preg_replace('/"(\w+)"/', '[$1]', $sql);

        // 4. 解析与重构
        $rawQueries = preg_split('/;(?:\s*[\r\n]|$)+/s', $sql);
        $finalQueries = [];

        foreach ($rawQueries as $q) {
            $q = trim($q);
            if (empty($q)) continue;

            $upperQ = strtoupper($q);

            if (strpos($upperQ, 'CREATE TABLE') !== false) {
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+EXISTS\s+)?\[(\w+)\]/i', $q, $tnMatch)) {
                    $tableName = $tnMatch[1];

                    // --- 1. 提取索引 ---
                    preg_match_all('/(?:UNIQUE\s+)?KEY\s+\[(\w+)\]\s+\((.*?)\)/i', $q, $idxMatches, PREG_SET_ORDER);

                    // 移除原有的 KEY 定义和重复主键声明
                    $q = preg_replace('/,?\s+PRIMARY\s+KEY\s+\(\[.*?\]\)/i', '', $q);
                    $q = preg_replace('/,?(?:\s+)?(?:UNIQUE\s+)?KEY\s+\[\w+\]\s+\(.*?\)/i', '', $q);

                    // --- 2. 移除分区定义 (SQL Server 分区语法完全不同，此处做扁平化处理) ---
                    if (stripos($q, 'PARTITION BY') !== false) {
                        $q = preg_replace('/PARTITION\s+BY\s+.*$/is', '', $q);
                    }

                    $q = preg_replace('/,\s*\)/', ')', $q);
                    $q = preg_replace('/\s+/', ' ', $q);

                    $finalQueries[] = trim($q, " ;") . ';';

                    // 转换为独立的 CREATE INDEX 语句
                    foreach ($idxMatches as $match) {
                        $isUnique = stripos($match[0], 'UNIQUE') !== false;
                        $idxName = $match[1];
                        $idxCols = preg_replace('/\(\d+\)/', '', $match[2]);
                        $finalQueries[] = ($isUnique ? "CREATE UNIQUE INDEX" : "CREATE INDEX") .
                            " [idx_{$tableName}_{$idxName}] ON [{$tableName}] ({$idxCols});";
                    }
                } else {
                    $finalQueries[] = trim($q, " ;") . ';';
                }
            } elseif (strpos($upperQ, 'ALTER TABLE') !== false) {
                // 处理 ALTER TABLE (SQL Server 使用 ALTER COLUMN)
                if (preg_match('/ALTER\s+TABLE\s+\[(\w+)\]\s+MODIFY\s+\[(\w+)\]\s+(.*)/i', $q, $alterMatch)) {
                    $tableName = $alterMatch[1];
                    $columnName = $alterMatch[2];
                    $rest = trim($alterMatch[3]);
                    $type = explode(' ', $rest)[0];
                    $finalQueries[] = "ALTER TABLE [{$tableName}] ALTER COLUMN [{$columnName}] {$type};";
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
