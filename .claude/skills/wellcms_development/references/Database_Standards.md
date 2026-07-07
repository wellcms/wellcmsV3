# WellCMS 3.0 数据库与事务规范 (Database & Transaction Standards)

## 0. Model 表名规范 (Table Naming)

*   **`$table` 严禁携带表前缀**：Model 的 `protected $table` 属性必须填写**不含 `well_` 前缀**的纯表名。前缀由底层 `DatabaseInterface` 在运行时自动拼接。
*   **示例**：
    ```php
    // 正确：纯表名
    protected $table = 'multi_site_sync_outbox';  // DB 层拼接为 well_multi_site_sync_outbox

    // 错误：携带 well_ 前缀
    protected $table = 'well_multi_site_sync_outbox';  // DB 层拼接为 well_well_multi_site_sync_outbox
    ```
*   **install.php 建表**：使用 `{$db->prefix}表名` 格式，与 Model 的 `$table` 形成一致（`$db->prefix` 输出 `well_`）。

## 1. 原子服务层架构 (Atomic Service)
所有物理表必须 1:1 映射一个原子 Service。职责划分为：
*   **原子操作封装**：仅调用 `$this->dbModel`，无复杂逻辑。
*   **业务逻辑封装**：包含 `readByCache`、`format` 及数据合并。

### 标准接口签名
*   `insert(array $data): int`
*   `bulkInsert(array $data): int`
*   `update(array $condition, array $update): bool` (触发 ID 缓存清理)
*   `bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): bool` (触发 ID 缓存清理)
*   `delete(array $condition): bool` (触发 ID 缓存清理)
*   `read(array $condition = []): array`
*   `find(array $condition = [], array $orderby = [], int $page, int $pageSize): array`
*   `count(array $condition = []): int`

## 2. 缓存治理与锁策略
*   **闭环读取**：必须通过 `readByCache` 配合 `cacheWithLock`。
*   **主动清理**：`update`、`bulkUpdate` 与 `delete` 必须在 $condition 包含主键 ID 时主动 `delete` 对应缓存 Key。

## 3. 排序与性能确定性 (ORDER BY)
*   **单字段限制**：严禁多字段排序。`$orderBy` 必须仅含一个键值对。
*   **数值规范**：`1` 代表 ASC，`-1` 代表 DESC。
*   **索引依赖**：排序字段必须是索引的一部分。

## 4. 大数据分区准则 (Industrial Partitioning)
根据业务负载，系统强制执行以下三级分区策略：

*   **一级：核心内容 (Core Content)**：
    *   **策略**：`BigInt` (自增) + Quarterly `RANGE (created_at)`。
    *   **适用**：`forum_thread`, `forum_reply`。解决数据周期性归档与单表 B+ 树层级过深。
*   **二级：高频轨迹/社交 (High-Freq User Data)**：
    *   **策略**：`UUIDv7` + **复合分区**（Quarterly `RANGE (created_at)` + `SUBPARTITION HASH (user_id)`）。
    *   **适用**：`forum_message`, `forum_stats_log`。`RANGE` 解决生命周期，`HASH` 解决特定用户的大规模并发写入与查询裁剪。
*   **三级：系统审计/流水 (Auditing & Logs)**：
    *   **策略**：`UUIDv7` + Monthly `RANGE (created_at)`。
    *   **适用**：`system_log`, `login_history`。极致提升冷热数据物理分离性能。

### 强制技术细节
1.  **分区命名**：统一采用 `pYYYYQX` 或 `pYYYYMM`。
2.  **主键规范**：分区键 **必须** 包含在 `PRIMARY KEY` 声明中，严禁在分区表上创建未包含分区键的 `UNIQUE KEY`。

## 5. 跨数据库兼容性 (MySQL & PostgreSQL)
*   **字段定义禁令**：状态/类型字段，MySQL 必用 `tinyint`，PostgreSQL 必用 `smallint`。严禁使用字符类存储状态。
*   **索引匹配**：数组 `$condition` 键名顺序必须与索引定义 100% 匹配，严禁分库乱序。

## 6. 严禁 SQL 高级语法（铁律）
*   **绝对禁止 JOIN**：严禁使用 `JOIN`、`LEFT JOIN`、`RIGHT JOIN`、`INNER JOIN`、`CROSS JOIN` 等任何连表查询语法。
*   **绝对禁止子查询**：严禁在 `WHERE`、`SELECT`、`FROM` 中使用嵌套子查询（Subquery）。
*   **绝对禁止视图（VIEW）**：严禁创建或使用数据库视图。
*   **绝对禁止存储过程/函数/触发器**：所有业务逻辑必须在应用层（PHP Service）实现，严禁下沉到数据库层。
*   **单表查询 + 应用层合并**：多表数据关联必须通过原子 Service 逐表查询后，在 PHP 层通过 `array_merge`、`array_key_exists` 等方式合并。例如：先查索引表获取 ID 列表，再查主表获取详情，最后循环合并。

## 7. Model 层与 BaseModel 规范

### 7.1 BaseModel 提取策略
所有标准 Model **必须**继承 `App\Models\BaseModel`，通过 `protected $table` 声明表名，禁止在每个方法中硬编码表名字符串。

```php
namespace Plugins\well_forum\Models;

use App\Models\BaseModel;

class ForumCategoryModel extends BaseModel
{
    protected $table = 'forum_category';
}
```

BaseModel 已提供以下标准方法，子类无特殊需求时不得重复覆盖：
*   `insert(array $data = [])`
*   `update(array $condition = [], array $update = [])`
*   `read(array $condition = [], array $orderBy = [], array $fields = ['*'])`
*   `find(array $condition = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*'])`
*   `delete(array $condition = [])`
*   `count(array $condition = [])`
*   `maxid(string $field = 'id')`
*   `bulkInsert(array $data = [])`
*   `bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = [])`

### 7.4 `update()` 增量/减量语法铁律
WellCMS Query Builder 的 `compileUpdate` 通过**键名后缀**识别增量/减量操作，**不支持**嵌套数组语法。

**正确语法** (必须)：
```php
// ✅ 增量：键名以 '+' 结尾
$this->dbModel->update(['id' => $id], ['downloads+' => 1]);
// 生成 SQL: UPDATE ... SET `downloads` = `downloads` + ? WHERE `id` = ?

// ✅ 减量：键名以 '-' 结尾
$this->dbModel->update(['id' => $id], ['stock-' => 1]);
// 生成 SQL: UPDATE ... SET `stock` = `stock` - ? WHERE `id` = ?

// ✅ 普通赋值
$this->dbModel->update(['id' => $id], ['name' => 'New Name']);
```

**错误语法** (严禁)：
```php
// ❌ 错误：嵌套数组语法不被支持
$this->dbModel->update(['id' => $id], ['downloads' => ['+=' => 1]]);
// 实际生成 SQL: UPDATE ... SET `downloads` = 'Array' WHERE `id` = ?
// 后果：在 MySQL strict mode 下 PDO 抛异常，导致整个请求失败
```

**关键认知**：
*   `paramsValueEscape()` 对数组执行强制类型转换 `(string)$value`，数组会变成 `"Array"` 字符串。
*   所有方言（MySQL / PgSQL / SQLite / SqlServer）均遵循相同的键名后缀规则（`+` / `-`）。
*   `bulkUpdate` 同理：行数据中使用 `'views+' => 5` 而非 `['views' => ['+=' => 5]]`。

### 7.2 `find()` 的 `$key` 参数语义
*   `$key = ''`（默认值）：返回**索引数组** `[0 => row, 1 => row]`。
*   `$key = 'id'`：返回以指定字段为键的**关联数组** `['id1' => row, 'id2' => row]`（底层使用 `array_column`）。
*   **不可随意变更默认值**：若原子 Model 历史默认值为 `'id'`，继承 BaseModel 后必须通过覆盖 `find()` 保持该默认值，否则会导致调用方按 ID 索引的代码失效。

### 7.3 无法继承 BaseModel 的例外情况
PHP 7.2 严格限制方法覆盖签名（见 `Compatibility_and_Tools.md`）。以下情况 Model **不得**继承 BaseModel，保持独立实现：
*   **`insert` 参数签名不兼容**：如 `insert(array $data, bool $replace = false)` 与 BaseModel 的 `insert(array $data = [])` 参数数量/类型不同。
*   **返回类型冲突**：如 `insert(array $data): int` 与 BaseModel 无返回类型声明冲突。
*   **完全自定义 CRUD**：如游标分页 `cursorFind()`、批量清理 `cleanupExpired()` 等无标准对应方法。

对于可继承但含有**自定义业务方法**的 Model（如 `readByHash()`、`deleteById()`），继承 BaseModel 后仅保留自定义方法及需要修正默认值的 `find()` / `maxid()` 覆盖。

## 8. 参考实现 (Reference Implementation)
*   **基础原子服务标准**：[UserService.php](/app/Services/Auth/UserService.php)
*   **复合业务与 Service 协作标准**：[ForumThreadService.php](/plugins/well_forum/Services/ForumThreadService.php)
*   **BaseModel 提取标准**：[BaseModel.php](/app/Models/BaseModel.php)
*   **插件 Model 继承范例**：[ForumCategoryModel.php](/plugins/well_forum/Models/ForumCategoryModel.php)
