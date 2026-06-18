<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

use Framework\Database\Query\Grammar\GrammarInterface;

class MySqlGrammar implements GrammarInterface
{
    // 移除所有缓存逻辑以防止内存溢出和泄露。
    // SQL 构建开销极低，通过移除此处的不稳定缓存可显著提升在高并发环境下的鲁棒性。

    public function wrap(string $id): string
    {
        if ($id === '*' || false !== strpos($id, ' ')) return $id;

        if (strpos($id, '.') !== false) {
            [$table, $column] = explode('.', $id, 2);
            // 包裹表名部分
            $wrappedTable = "`{$table}`";
            // 列名如果是 *，不包裹；否则正常包裹
            $wrappedColumn = ($column === '*') ? $column : "`{$column}`";
            return "{$wrappedTable}.{$wrappedColumn}";
        }

        return "`{$id}`";
    }

    public function compileSelect(array $cols, string $tbl, array $joins, array $wheres, array $orders, ?int $lim, ?int $off): string
    {
        $sql  = 'SELECT ' . implode(', ', array_map([$this, 'wrap'], $cols)) . ' FROM ' . $this->wrap($tbl);
        if ($joins)  $sql .= ' ' . implode(' ', $joins);
        if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
        if ($orders) $sql .= ' ORDER BY ' . implode(', ', $orders);
        if (null !== $lim) {
            $sql .= " LIMIT {$lim}";
            if (null !== $off) {
                $sql .= " OFFSET {$off}";
            }
        }

        return $sql;
    }

    public function compileInsert(string $table, array $data, bool $batch = false): string
    {
        $cols = array_keys($batch ? $data[0] : $data);
        $wrapped = array_map([$this, 'wrap'], $cols);
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

        if ($batch) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->wrap($table),
                implode(',', $wrapped),
                implode(',', array_fill(0, count($data), $place))
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->wrap($table),
                implode(',', $wrapped),
                $place
            );
        }
        return $sql;
    }

    public function compileUpdate(string $table, array $data, array $wheres): string
    {
        $sets = [];
        foreach ($data as $col => $_) {
            $operator = substr($col, -1);  // '+' 或 '-'
            if ('+' === $operator || '-' === $operator) {
                $col = substr($col, 0, -1);    // 去掉后缀的真实字段名
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
        return 'SHOW TABLES';
    }

    public function compileColumnListing(string $table): string
    {
        return 'DESCRIBE ' . $this->wrap($table);
    }

    public function compileIndexListing(string $table): string
    {
        return 'SHOW INDEX FROM ' . $this->wrap($table);
    }

    public function compileTableExists(string $table): string
    {
        $safe = str_replace("'", "''", $table);
        return "SHOW TABLES LIKE '{$safe}'";
    }

    public function compileSetCharset(string $charset, string $collation): string
    {
        return "SET NAMES '{$charset}' COLLATE '{$collation}'";
    }

    public function compileIsolationLevel(string $level): string
    {
        return "SET SESSION TRANSACTION ISOLATION LEVEL {$level}";
    }

    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrap($table);
    }

    public function compileSchema(string $schema): string
    {
        return "/* MySQL does not support search_path, using database: {$schema} */";
    }

    public function getSequenceName(string $table): ?string
    {
        return null;
    }

    public function prepareSchema(string $sql): array
    {
        return [$sql];
    }

    public static function clearCache(): void {}
}
