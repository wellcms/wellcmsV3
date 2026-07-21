<?php

namespace App\Models;

use Framework\Database\Interfaces\DatabaseInterface;

/**
 * 基础数据模型
 * 
 * 提取所有 Model 的公共 CRUD 逻辑，减少重复代码。
 * 子类只需设置 $table 属性即可拥有完整的数据库操作能力。
 */
class BaseModel
{
    /**
     * @var DatabaseInterface
     */
    protected $db;

    /**
     * 数据表名（不含前缀）
     * 子类必须设置此属性
     *
     * @var string
     */
    protected $table;

    /**
     * 构造函数
     *
     * @param DatabaseInterface $db
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 获取数据表名
     *
     * @return string
     * @throws \RuntimeException
     */
    protected function getTable(): string
    {
        if (empty($this->table)) {
            throw new \RuntimeException('Property $table must be set in ' . static::class);
        }
        return $this->table;
    }

    /**
     * 插入单条记录
     *
     * @param array $data
     * @return mixed
     */
    public function insert(array $data = [])
    {
        return $this->db->insert($this->getTable(), $data);
    }

    /**
     * 更新记录
     *
     * @param array $condition
     * @param array $update
     * @return int
     */
    public function update(array $condition = [], array $update = []): int
    {
        return $this->db->update($this->getTable(), $condition, $update);
    }

    /**
     * 查询单条记录
     *
     * @param array $condition
     * @param array $orderBy
     * @param array $fields
     * @return array
     */
    public function read(array $condition = [], array $orderBy = [], array $fields = ['*']): array
    {
        return $this->db->queryOne($this->getTable(), $condition, $orderBy, $fields);
    }

    /**
     * 分页查询多条记录
     *
     * @param array $condition
     * @param array $orderBy
     * @param int $page
     * @param int $pageSize
     * @param string $key 结果数组键名
     * @param array $fields
     * @return array
     */
    public function find(array $condition = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array
    {
        return $this->db->query($this->getTable(), $condition, $orderBy, $page, $pageSize, $key, $fields);
    }

    /**
     * 删除记录
     *
     * @param array $condition
     * @return int
     */
    public function delete(array $condition = []): int
    {
        return $this->db->delete($this->getTable(), $condition);
    }

    /**
     * 统计记录数
     *
     * @param array $condition
     * @return int
     */
    public function count(array $condition = []): int
    {
        return $this->db->count($this->getTable(), $condition);
    }

    /**
     * 获取某字段最大值
     *
     * @param string $field
     * @return int
     */
    public function maxid(string $field = 'id'): int
    {
        return $this->db->maxid($this->getTable(), $field);
    }

    /**
     * 批量插入
     *
     * @param array $data
     * @return mixed
     */
    public function bulkInsert(array $data = []): int
    {
        if (empty($data)) {
            return 0;
        }
        return $this->db->bulkInsert($this->getTable(), $data);
    }

    /**
     * 批量更新
     *
     * @param array $update
     * @param string $keyColumn
     * @param array $wheres
     * @return int
     */
    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        if (empty($update)) {
            return 0;
        }
        return $this->db->bulkUpdate($this->getTable(), $update, $keyColumn, $wheres);
    }
}