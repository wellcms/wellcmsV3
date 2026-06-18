<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

use Framework\Database\Query\Grammar\GrammarInterface;

class SQLiteGrammar implements GrammarInterface
{
    // 移除所有缓存逻辑以防止内存溢出和泄露。

    public function wrap(string $id): string
    {
        if ($id === '*' || false !== strpos($id, ' ')) return $id;
        if (strpos($id, '.') !== false) {
            [$table, $column] = explode('.', $id, 2);
            $wrappedTable = "\"{$table}\"";
            $wrappedColumn = ($column === '*') ? $column : "\"{$column}\"";
            return "{$wrappedTable}.{$wrappedColumn}";
        }
        return "\"{$id}\"";
    }

    public function compileSelect(array $cols, string $tbl, array $joins, array $wheres, array $orders, ?int $lim, ?int $off): string
    {
        $sql  = 'SELECT ' . implode(', ', array_map([$this, 'wrap'], $cols)) . ' FROM ' . $this->wrap($tbl);
        if ($joins)  $sql .= ' ' . implode(' ', $joins);
        if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
        if ($orders) $sql .= ' ORDER BY ' . implode(', ', $orders);
        if (null !== $lim) {
            $sql .= " LIMIT {$lim}";
            if (null !== $off) $sql .= " OFFSET {$off}";
        }

        return $sql;
    }

    public function compileInsert(string $table, array $data, bool $batch = false): string
    {
        $cols = array_keys($batch ? $data[0] : $data);
        $wrapped = array_map([$this, 'wrap'], $cols);
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        if ($batch) {
            $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $this->wrap($table), implode(',', $wrapped), implode(',', array_fill(0, count($data), $place)));
        } else {
            $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $this->wrap($table), implode(',', $wrapped), $place);
        }
        return $sql;
    }

    public function compileUpdate(string $table, array $data, array $wheres): string
    {
        $sets = [];
        foreach ($data as $col => $_) {
            $operator = substr($col, -1);
            if ('+' === $operator || '-' === $operator) {
                $col = substr($col, 0, -1);
                $sets[] = "{$this->wrap($col)} = {$this->wrap($col)} " . ('+' === $operator ? '+' : '-') . " ?";
            } else {
                $sets[] = "{$this->wrap($col)} = ?";
            }
        }
        $sql = 'UPDATE ' . $this->wrap($table) . ' SET ' . implode(', ', $sets);
        if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
        return $sql;
    }

    public function compileBulkUpdate(string $table, string $key, array $rows, array $wheres = []): string
    {
        // SQLite 批量更新通常通过 CASE 实现，与 MySQL 类似
        $cases = [];
        foreach ($rows as $row) {
            foreach ($row as $col => $val) {
                if ($col === $key) continue;
                $cases[$col][] = "WHEN {$this->wrap($key)} = ? THEN ?";
            }
        }
        $sets = [];
        foreach ($cases as $col => $whens) {
            $sets[] = "{$this->wrap($col)} = CASE " . implode(' ', $whens) . " END";
        }
        $ids = array_fill(0, count($rows), '?');
        $sql = "UPDATE {$this->wrap($table)} SET " . implode(', ', $sets) . " WHERE {$this->wrap($key)} IN (" . implode(',', $ids) . ')';
        if ($wheres) {
            $sql .= ' AND ' . implode(' AND ', $wheres);
        }
        return $sql;
    }

    public function compileDelete(string $table, array $wheres): string
    {
        $sql = 'DELETE FROM ' . $this->wrap($table);
        if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
        return $sql;
    }

    public function compileTableListing(): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
    }
    public function compileColumnListing(string $table): string
    {
        return "PRAGMA table_info(" . $this->wrap($table) . ")";
    }
    public function compileIndexListing(string $table): string
    {
        return "PRAGMA index_list(" . $this->wrap($table) . ")";
    }
    public function compileTableExists(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$safe}'";
    }
    public function compileSetCharset(string $charset, string $collation): string
    {
        return "PRAGMA encoding = \"UTF-8\"";
    }
    public function compileIsolationLevel(string $level): string
    {
        return "PRAGMA journal_mode = WAL";
    }
    public function compileTruncate(string $table): string
    {
        return 'DELETE FROM ' . $this->wrap($table);
    }
    public function compileSchema(string $schema): string
    {
        return "/* SQLite uses single file database, schema concept is mapping to database file: {$schema} */";
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
            // 整数映射 (SQLite 核心类型，必须先处理自增)
            '/\bBIGINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' INTEGER PRIMARY KEY AUTOINCREMENT ',
            '/\bINT\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'    => ' INTEGER PRIMARY KEY AUTOINCREMENT ',
            '/\bINTEGER\s*(\(\d+\))?\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' INTEGER PRIMARY KEY AUTOINCREMENT ',

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

            '/\bBIGINT\b(\s*\(\d+\))?/i'      => ' INTEGER ',
            '/\bINT\b(\s*\(\d+\))?/i'         => ' INTEGER ',
            '/\bINTEGER\b(\s*\(\d+\))?/i'     => ' INTEGER ',
            '/\bMEDIUMINT\b(\s*\(\d+\))?/i'   => ' INTEGER ',
            '/\bSMALLINT\b(\s*\(\d+\))?/i'    => ' INTEGER ',
            '/\bTINYINT\b(\s*\(\d+\))?/i'     => ' INTEGER ',
            '/\bBOOL(EAN)?\b/i'               => ' INTEGER ',
            '/\bBIT\s*(\(\d+\))?/i'           => ' INTEGER ',

            // 浮点数与精确小数
            '/\bDOUBLE\b(\s*PRECISION)?/i'    => ' REAL ',
            '/\bFLOAT\b(\s*\(\d+(,\d+)?\))?/i' => ' REAL ',
            '/\bDECIMAL\s*\((\d+),(\d+)\)/i'  => ' NUMERIC ',
            '/\bDECIMAL\s*\((\d+)\)/i'        => ' NUMERIC ',

            // 文本/JSON/二进制
            '/LONGTEXT/i'                => ' TEXT ',
            '/MEDIUMTEXT/i'              => ' TEXT ',
            '/TINYTEXT/i'                => ' TEXT ',
            '/\bJSON\b/i'                => ' TEXT ',
            '/\bBLOB\b/i'                => ' BLOB ',
            '/\bMEDIUMBLOB\b/i'          => ' BLOB ',
            '/\bLONGBLOB\b/i'            => ' BLOB ',
            '/\bBINARY\s*\(16\)/i'       => ' BLOB ',
            '/\bBINARY\s*\(32\)/i'       => ' BLOB ',
            '/\bVARBINARY\s*\(16\)/i'    => ' BLOB ',
            '/\bVARBINARY\s*\(32\)/i'    => ' BLOB ',

            // 日期时间
            '/DATETIME/i'                => ' TEXT ',
            '/TIMESTAMP\b/i'             => ' TEXT ',
            '/\bYEAR\b/i'                => ' INTEGER ',

            '/\bCHAR\s*\((\d+)\)/i'      => ' VARCHAR($1) ',
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
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+EXISTS\s+)?["`]?(\w+)["`]?/i', $q, $tnMatch)) {
                    $tableName = $tnMatch[1];

                    // --- 1. 提取索引 (SQLite 核心兼容) ---
                    preg_match_all('/(?:UNIQUE\s+)?KEY\s+["`]?(\w+)["`]?\s+\((.*?)\)/i', $q, $idxMatches, PREG_SET_ORDER);

                    // 移除原有的 KEY 定义
                    $q = preg_replace('/,?(?:\s+)?(?:UNIQUE\s+)?KEY\s+["`]?(\w+)["`]?\s+\(.*?\)/i', '', $q);

                    // --- 2. 处理自增主键冲突 ---
                    // 如果已经注入了 INTEGER PRIMARY KEY AUTOINCREMENT，则移除表尾的重复 PRIMARY KEY 定义
                    if (stripos($q, 'AUTOINCREMENT') !== false) {
                        $q = preg_replace('/,?\s+PRIMARY\s+KEY\s+\("?\w+"?\)/i', '', $q);
                    }

                    // --- 3. 移除分区定义 (SQLite 不支持，直接扁平化) ---
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
                            " \"idx_{$tableName}_{$idxName}\" ON \"{$tableName}\" ({$idxCols});";
                    }
                } else {
                    $finalQueries[] = trim($q, " ;") . ';';
                }
            } elseif (strpos($upperQ, 'ALTER TABLE') !== false) {
                // SQLite ALTER TABLE 支持极有限 (仅 ADD COLUMN, RENAME TO)
                // 暂时忽略 MODIFY, CHANGE 等
                if (preg_match('/(MODIFY|CHANGE)/i', $q)) {
                    continue;
                }
                $finalQueries[] = str_replace('`', '"', trim($q, " ;")) . ';';
            } else {
                $finalQueries[] = preg_replace('/\s+/', ' ', trim($q, " ;")) . ';';
            }
        }
        return $finalQueries;
    }

    public static function clearCache(): void {}
}
