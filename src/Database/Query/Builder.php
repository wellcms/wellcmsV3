<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query;

use Framework\Database\Query\Grammar\GrammarInterface;
use Framework\Exception\Infra\QueryException;

/**
 * Class Builder
 *
 * 提供链式查询构造，并支持 JOIN、GROUP BY、HAVING、子查询、
 * 事务隔离级别及错误重试。
 */
class Builder
{
    /** @var GrammarInterface 方言编译器 */
    protected $grammar;

    /** @var array 绑定参数 */
    protected $bindings = [];

    /** @var array 查询组件 */
    protected $components = [
        'type'      => 'select', // select | insert | update | delete
        'columns'   => [],       // SELECT 列
        'table'     => null,     // 表
        'joins'     => [],       // JOIN
        'wheres'    => [],       // WHERE
        'groups'    => [],       // GROUP BY
        'havings'   => [],       // HAVING
        'orders'    => [],       // ORDER BY
        'limit'     => null,     // LIMIT
        'offset'    => null,     // OFFSET
        'insert'    => [],       // INSERT 数据
        'update'    => [],       // UPDATE 数据
    ];

    /** @var array 合法操作符白名单 */
    protected $allowedOperators = [
        '=',
        '>',
        '<',
        '>=',
        '<=',
        // '!=', // 不推荐使用 !=，因为它会导致索引失效，转为全表扫描。
        // '<>', // 不推荐使用 <>，因为它会导致索引失效，转为全表扫描。
        'LIKE', // 带有前导通配符的 LIKE (如 %abc%)，一旦 % 出现在开头，数据库索引就会失效，转为全表扫描。
        'NOT LIKE',
        // 'ILIKE', // 不区分大小写的 LIKE，性能开销大
        // 'REGEXP', // (正则表达式匹配) —— 性能杀手，慎用
        '<<',
        '<<=',
        '>>',
        '>>=',
        '&&',
        'NET_IN' // 善用 NET_IN，它能利用数据库索引，性能开销小。对非 PG 数据库会自动转为 BETWEEN ? AND ?。这是索引友好的查询方案，即使在低配机上也能保持常数级（O(log n)）的性能。
    ];

    /** @var string 事务隔离级别 */
    protected $isolationLevel;

    /** @var int 错误重试次数 */
    protected $maxRetries = 0;

    /** @var int 重试间隔（毫秒） */
    protected $retryDelay = 0;

    public function __construct(GrammarInterface $grammar)
    {
        $this->grammar = $grammar;
    }

    /**—————— 基本查询 ——————*/

    public function select(array $columns): self
    {
        $this->components['type']    = 'select';
        $this->components['columns'] = $columns;
        return $this;
    }

    public function from(string $table): self
    {
        $this->components['table'] = $table;
        return $this;
    }

    public function where(array $where): self
    {
        if (empty($where)) return $this;
        foreach ($where as $k => $v) {
            $wrappedK = $this->grammar->wrap($k);
            if (!is_array($v)) {
                $v = (is_int($v) || is_float($v)) ? $v : (string)$v;
                $this->components['wheres'][] = "{$wrappedK} = ?";
                $this->bindings[] = $this->paramsValueEscape($v);
            } elseif (isset($v[0])) {
                $sql = '';
                foreach ($v as $v1) {
                    $sql .= '?,';
                    $v1 = (is_int($v1) || is_float($v1)) ? $v1 : (string)$v1;
                    $this->bindings[] = $this->paramsValueEscape($v1);
                }
                $this->components['wheres'][] = "{$wrappedK} IN (" . trim($sql, ',') . ')';
            } else {
                foreach ($v as $k1 => $v1) {
                    $op = strtoupper(trim((string)$k1));
                    if (!in_array($op, $this->allowedOperators)) {
                        throw new QueryException("Invalid operator: {$k1}");
                    }

                    if ('LIKE' === $op) {
                        $v1 = "%$v1%";
                    }

                    // --- B方案：架构抽象 - 处理网络匹配虚拟操作符 ---
                    // 业务层用法：['create_ip' => ['NET_IN' => '192.168.1.0/24']]
                    if ('NET_IN' === $op) {
                        $range = \Framework\Utils\IpHelper::parseCidrRange((string)$v1);
                        // 如果是 PostgreSQL，使用原生高性能位运算
                        if (strpos(get_class($this->grammar), 'PgSqlGrammar') !== false) {
                            $this->components['wheres'][] = "{$wrappedK} << ?";
                            $this->bindings[] = $v1; // 直接传入 CIDR 字符串
                        } else {
                            // 其他数据库，转换为索引友好的 BETWEEN
                            $this->components['wheres'][] = "{$wrappedK} BETWEEN ? AND ?";
                            $this->bindings[] = $range['start'];
                            $this->bindings[] = $range['end'];
                        }
                        continue;
                    }

                    $v1 = (is_int($v1) || is_float($v1)) ? $v1 : (string)$v1;
                    // Plan A: 强制添加空格提升鲁棒性
                    $this->components['wheres'][] = "{$wrappedK} {$op} ?";
                    $this->bindings[] = $this->paramsValueEscape($v1);
                }
            }
        }
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->components['joins'][] = strtoupper($type) . " JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->components['groups'] = array_merge($this->components['groups'], $columns);
        return $this;
    }

    public function having(string $column, string $operator, $value): self
    {
        $this->components['havings'][] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orderBy(array $orderby): self
    {
        foreach ($orderby as $field => $value) {
            $direction = (1 === (int)$value ? 'ASC' : 'DESC');
            $this->components['orders'][] = $this->grammar->wrap($field) . " {$direction}";
        }
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->components['limit'] = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->components['offset'] = $offset;
        return $this;
    }

    /**—————— 插入操作 ——————*/

    /**
     * INSERT 单行
     */
    public function insert(array $data): self
    {
        $this->components['type']   = 'insert';
        $this->components['insert'] = $data;
        foreach ($data as $value) {
            $this->bindings[] = $this->paramsValueEscape($value);
        }
        return $this;
    }

    /**
     * BULK INSERT 多行
     */
    public function bulkInsert(array $rows): self
    {
        $this->components['type']   = 'bulkInsert';
        $this->components['insert'] = $rows;
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $this->bindings[] = $this->paramsValueEscape($value);
            }
        }
        return $this;
    }

    /**—————— 更新操作 ——————*/

    /**
     * UPDATE 单表更新
     */
    public function update(array $data): self
    {
        $this->components['type'] = 'update';
        $this->components['update'] = $data;
        foreach ($data as $col => $value) {
            $this->bindings[] = $this->paramsValueEscape($value);
        }

        return $this;
    }

    /**
     * BULK UPDATE：基于 CASE ... WHEN 实现批量更新
     * $rows 格式：[
     *   ['id'=>1, 'views+'=>5, 'name'=>'A'],
     *   ['id'=>2, 'views+'=>3, 'name'=>'B'],
     * ]
     */
    public function bulkUpdate(array $rows, string $keyColumn = 'id'): self
    {
        $this->components['type'] = 'bulkUpdate';
        $this->components['updateRows'] = [
            'key'  => $keyColumn,
            'rows' => $rows,
        ];
        // $columns = 所有需要更新的列（去掉$keyColumn）
        $columns = [];
        foreach ($rows[0] as $col => $val) {
            if ($col !== $keyColumn) $columns[] = $col;
        }

        foreach ($columns as $col) {
            foreach ($rows as $row) {
                $this->bindings[] = $row[$keyColumn]; // CASE里的ID
                $this->bindings[] = $row[$col];       // CASE里的值
            }
        }

        // 最后把所有 keyColumn 的值放到 bindings 末尾，用于 WHERE IN (...)
        foreach ($rows as $row) {
            $this->bindings[] = $row[$keyColumn];
        }

        return $this;
    }

    /**—————— 删除操作 ——————*/

    /**
     * DELETE
     */
    public function delete(): self
    {
        $this->components['type'] = 'delete';
        return $this;
    }

    /**—————— 事务 & 重试 ——————*/

    public function setTransactionIsolation(string $level): self
    {
        $this->isolationLevel = strtoupper($level);
        return $this;
    }

    public function retry(int $retries, int $delay = 0): self
    {
        $this->maxRetries = $retries;
        $this->retryDelay = $delay;
        return $this;
    }

    /**
     * 返回完整 SQL 和绑定参数
     *
     * @return array [sql, bindings]
     * @throws QueryException
     */
    public function toSqlWithBindings(): array
    {
        switch ($this->components['type']) {
            case 'select':
                $sql = $this->grammar->compileSelect(
                    $this->components['columns'] ?: ['*'],
                    $this->components['table'],
                    $this->components['joins'],
                    $this->components['wheres'],
                    $this->components['orders'],
                    $this->components['limit'],
                    $this->components['offset']
                );
                break;
            case 'insert':
                $sql = $this->grammar->compileInsert(
                    $this->components['table'],
                    $this->components['insert']
                );
                break;
            case 'bulkInsert':
                $sql = $this->grammar->compileInsert(
                    $this->components['table'],
                    $this->components['insert'],
                    (bool)count($this->components['insert'])
                );
                break;
            case 'update':
                $sql = $this->grammar->compileUpdate(
                    $this->components['table'],
                    $this->components['update'],
                    $this->components['wheres']
                );
                break;
            case 'bulkUpdate':
                $info = $this->components['updateRows'];
                $rows = $info['rows'];
                $key  = $info['key'];
                $columns = [];
                if (!empty($rows)) {
                    foreach ($rows[0] as $col => $val) {
                        if ($col !== $key) $columns[] = $col;
                    }
                }
                // buldUpdate() 的 binding 顺序：CASE(columns×rows×2) + IN(rows)
                $bulkCount = count($columns) * count($rows) * 2 + count($rows);
                if (count($this->bindings) > $bulkCount) {
                    $whereCount = count($this->bindings) - $bulkCount;
                    $whereBinds = array_slice($this->bindings, 0, $whereCount);
                    $bulkBinds = array_slice($this->bindings, $whereCount);
                    $this->bindings = array_merge($bulkBinds, $whereBinds);
                }
                $sql  = $this->grammar->compileBulkUpdate(
                    $this->components['table'],
                    $key,
                    $rows,
                    $this->components['wheres']
                );
                break;
            case 'delete':
                $sql = $this->grammar->compileDelete(
                    $this->components['table'],
                    $this->components['wheres']
                );
                break;
            default:
                throw new QueryException("Unsupported query type: {$this->components['type']}");
        }

        $result = [$sql, $this->bindings];
        $this->reset(); // 重置所有属性，彻底清空
        return $result;
    }

    /** 获取当前绑定数组 */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    private function paramsValueEscape($value)
    {
        return is_int($value) ? (int)$value : (string)$value;
    }

    protected function reset(): void{
        $this->components = [
            'type'      => 'select',
            'columns'   => [],
            'table'     => null,
            'joins'     => [],
            'wheres'    => [],
            'groups'    => [],
            'havings'   => [],
            'orders'    => [],
            'limit'     => null,
            'offset'    => null,
            'insert'    => [],
            'update'    => [],
        ];
        $this->bindings = [];
        $this->isolationLevel = null;
        $this->maxRetries = 0;
        $this->retryDelay = 0;
    }
}

/*
components['type']
区分当前构造的是哪种操作：select／insert／bulkInsert／update／delete。

insert()、bulkInsert()、update()、delete() 方法

insert()：单行插入，字段名由 compileInsert() 处理，值用 ? 占位并追加到 $bindings。

bulkInsert()：多行插入，交由 compileBulkInsert() 生成多组 (?,?,…)，并一次性将所有值加到 $bindings。

update()：更新同理，通过 compileUpdate() 生成 SET col1 = ?, col2 = ? … WHERE …。

delete()：调用 compileDelete() 生成 DELETE FROM table WHERE …。

方言编译器 (GrammarInterface)
需要在各自方言下实现对应的 compileInsert()、compileBulkInsert()、compileUpdate()、compileDelete()（以及原来的 compileSelect()）。

重试、事务隔离
都保留，可在 PdoDriver 执行时读取 $builder->getBindings() 及隔离级别／重试次数。

示例用法
// SELECT
list($sql,$bind) = $builder->select(['id','name'])
                           ->from('users')
                           ->where('status','=',1)
                           ->orderBy(['id' => 1])
                           ->limit(10)
                           ->offset(20)
                           ->toSqlWithBindings();

// INSERT
list($sql,$bind) = $builder->insert(['title'=>'Hi','body'=>'...'])
                           ->from('posts')  // table() 也可单独提供方法
                           ->toSqlWithBindings();

// UPDATE + WHERE
list($sql,$bind) = $builder->from('posts')
                           ->update(['views+'=>1])
                           ->where('id','=',5)
                           ->toSqlWithBindings();

// DELETE
list($sql,$bind) = $builder->from('sessions')
                           ->where('expired_at','<', time())
                           ->delete()
                           ->toSqlWithBindings();

*/

/**
 * 高性能 IP 网段搜索示例 (跨数据库兼容方案)
 *
 * 价值：
 * 1. 业务层无需区分数据库类型 (MySQL vs PostgreSQL)。
 * 2. 自动利用数据库索引，避免 SQL 中使用函数导致的全表扫描。
 * 3. 完美支持 IPv4 和 IPv6 混合环境。
 */

// 1. 定义要搜索的网段 (CIDR 格式)
//$cidr = '192.168.1.0/24';

// 2. 无论底层是 MySQL 还是 PostgreSQL，代码完全一致
//$where = [
//    'create_ip' => ['NET_IN' => $cidr]
//];

// 3. 执行查询
//$threads = $db->query('forum_thread', $where, ['id' => -1]);

/*
 * --- 运行时的底层翻译逻辑说明 ---
 *
 * 如果环境是 PostgreSQL:
 * 生成 SQL: SELECT * FROM well_forum_thread WHERE "create_ip" << ?
 * 绑定值: ['192.168.1.0/24']
 *
 * 如果环境是 MySQL/SQLite/Oracle:
 * 生成 SQL: SELECT * FROM well_forum_thread WHERE `create_ip` BETWEEN ? AND ?
 * 绑定值: [Binary(192.168.1.0), Binary(192.168.1.255)]
 */
