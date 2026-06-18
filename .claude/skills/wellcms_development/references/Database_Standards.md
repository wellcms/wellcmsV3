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

well_forum 在上述三级策略下，共使用 **6 种 RANGE 分区模式** + **3 种 HASH 分区**（无需管理）：

### 4.1 分区模式一览

| 模式 | 分区类型 | 分区键 | 子分区键 | 子分区数 | 适用表 | 业务场景 |
|------|---------|--------|---------|---------|-------|---------|
| **A** | RANGE | `created_at` | 无 | — | `forum_thread`, `forum_thread_index`, `forum_thread_status_index`, `forum_thread_summary`, `forum_thread_content`, `forum_report`, `forum_reward`, `forum_punishment` | 核心内容：帖子、索引、统计、举报、奖惩。按创建时间归档，无子分区 |
| **B** | RANGE + HASH | `created_at` | `thread_id` | 8 | `forum_reply_index`, `forum_reply_status_index`, `forum_reply_summary` | 回复索引：按回复时间归档，按 `thread_id` 散列写入，防止大热帖写入冲突 |
| **C** | RANGE + HASH | `created_at` / `thread_created_at` | `thread_id` | 16 | `forum_reply_content`, `forum_thread_topic_index` | 回复内容/话题索引：内容体量大，使用 16 子分区进一步分散 IO |
| **D** | RANGE + HASH | `thread_created_at` | `user_id` | 8 | `forum_like`, `forum_dislike` | 互动数据：按**主题创建时间**归档（与主表对齐），按 `user_id` 散列 |
| **E** | RANGE + HASH | `reply_created_at` | `user_id` | 8 | `forum_reply_like`, `forum_reply_dislike` | 回复互动：按回复创建时间归档，按 `user_id` 散列 |
| **F** | RANGE + HASH | `created_at` | `user_id` | 8 | `forum_message` | 消息通知：百亿级消息，按发送时间归档 + 按接收人散列 |
| **G** | HASH | `topic_id` / `user_id` | 无 | 64/128 | `forum_topic_thread_index`, `forum_favorite`, `forum_collection_follow`, `forum_user_follow`, `forum_user_interact` | 纯 HASH（无时间概念，固定分区数，不由 PartitionManager 管理） |

### 4.2 分区键选择说明

| 分区键 | 含义 | 适用场景 |
|--------|------|---------|
| `created_at` | 记录的创建时间 | 绝大多数内容表（帖子、回复、统计等） |
| `thread_created_at` | 关联主题的创建时间 | 需与主表 `forum_thread` 对齐生命周期的表（如赞/踩） |
| `reply_created_at` | 关联回复的创建时间 | 需与回复表对齐生命周期的表（如回复赞/踩） |

### 4.3 子分区键选择说明

| 子分区键 | 含义 | 适用场景 |
|---------|------|---------|
| `thread_id` | 主题 ID | 先按时间范围裁剪，再按主题散列——大热帖的回复写入不会集中到同一子分区 |
| `user_id` | 用户 ID | 用户维度的查询（如"我的消息""我的点赞"）可在单子分区内完成，避免全表广播 |

### 4.4 PartitionManager 注册方式

| 模式 | Register 调用（PHP 7.2 位置参数） |
|------|----------------------------------|
| A | `new PartitionConfig($table, 'created_at', 'quarter')` |
| B | `new PartitionConfig($table, 'created_at', 'quarter', 4, 8, 'thread_id', 8)` |
| C | `new PartitionConfig($table, 'created_at', 'quarter', 4, 8, 'thread_id', 16)` |
| D | `new PartitionConfig($table, 'thread_created_at', 'quarter', 4, 8, 'user_id', 8)` |
| E | `new PartitionConfig($table, 'reply_created_at', 'quarter', 4, 8, 'user_id', 8)` |
| F | `new PartitionConfig($table, 'created_at', 'quarter', 4, 8, 'user_id', 8)` |
| G | **不注册**（HASH 分区无需维护） |

### 强制技术细节
1.  **分区命名**：统一采用 `pYYYYQX` 或 `pYYYYMM`。
2.  **主键规范**：分区键 **必须** 包含在 `PRIMARY KEY` 声明中，严禁在分区表上创建未包含分区键的 `UNIQUE KEY`。
3.  **建表规范**：所有 RANGE 分区表在 `install.php` 中只写 `PARTITION pmax VALUES LESS THAN MAXVALUE`，**严禁硬编码 p2026Q1-p2026Q4**。未来分区由 `PartitionManager::maintain()` 自动创建。

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

## 9. 分区管理器 PartitionManager

**位置**: `src/Database/Partition/`  
**文档**: [README.md](/src/Database/Partition/README.md)

分区管理器（PartitionManager）是 Framework 层提供的 RANGE 分区生命周期管理服务。用于解决分区定义在 install 时硬编码、运行时分区无人维护的问题。

### 9.1 插件接入规范

> ⚠️ **建表时只写 `pmax`，严禁硬编码未来分区。** 所有未来分区由 `PartitionManager::maintain()` 自动创建。

**install.php 完整模式**（建表 + 注册）：

```php
use Framework\Database\Partition\{PartitionManager, PartitionConfig};

// 1. 建表 — PARTITION BY RANGE 只写 pmax，不写具体分区
if (!$db->findTable('my_plugin_table')) {
    $sql = "CREATE TABLE `{$db->prefix}my_plugin_table` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0',
        `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      PARTITION BY RANGE (`created_at`) (
          PARTITION pmax VALUES LESS THAN MAXVALUE
      );";
    $db->exec($sql);
}

// 2. 注册 — PartitionManager 接管后续维护（PHP 7.2 位置参数）
$pm = $this->container->get(PartitionManager::class);
$pm->register(new PartitionConfig('my_plugin_table', 'created_at', 'quarter', 4, 8));
```

**复合子分区表**（RANGE + HASH）：

```php
$sql = "CREATE TABLE `{$db->prefix}my_plugin_reply` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`, `created_at`, `thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  PARTITION BY RANGE (`created_at`)
  SUBPARTITION BY HASH (`thread_id`) SUBPARTITIONS 8 (
      PARTITION pmax VALUES LESS THAN MAXVALUE
  );";
$db->exec($sql);

$pm->register(new PartitionConfig(
    'my_plugin_reply', 'created_at', 'quarter', 4, 8, 'thread_id', 8
));
```

**uninstall.php 中注销**（DROP TABLE 前调用）：

```php
$pm = $this->container->get(PartitionManager::class);
$pm->unregister('my_plugin_table');
$pm->unregister('my_plugin_reply');
$db->exec("DROP TABLE IF EXISTS `{$db->prefix}my_plugin_table`");
$db->exec("DROP TABLE IF EXISTS `{$db->prefix}my_plugin_reply`");
```

### 9.2 PartitionConfig 参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `$table` | string | 是 | — | 纯表名，不含前缀（如 `forum_thread`） |
| `$partitionColumn` | string | 是 | — | 分区列（如 `created_at`） |
| `$period` | string | 否 | `'quarter'` | 周期：`PartitionPeriod::Quarter` 或 `Month` |
| `$advanceCount` | int | 否 | 4 | 预创建分区数（当前边界 + 4 个季度） |
| `$retention` | int | 否 | 8 | 保留周期数（8 季度 = 2 年；0 为永不清除） |
| `$subPartitionColumn` | string|null | 否 | null | 子分区列（如 `thread_id` / `user_id`） |
| `$subPartitions` | int | 否 | 0 | 子分区数（0 表示无子分区） |

### 9.3 分区周期

| 周期 | 常量 | 命名格式 | 示例 |
|------|------|---------|------|
| 季度 | `PartitionPeriod::Quarter` | `pYYYYQ{N}` | `p2026Q1`, `p2027Q2` |
| 月度 | `PartitionPeriod::Month` | `pYYYYMM` | `p202601`, `p202612` |

### 9.4 分区类型 vs 是否需要注册

| 分区类型 | 需要注册 | 原因 |
|---------|---------|------|
| `RANGE` (created_at) | ✅ | 需要创建未来分区 + 清理过期数据 |
| `RANGE + HASH` 子分区 | ✅ | 同上，RANGE 部分需维护 |
| `HASH` (user_id/topic_id) | ❌ | 固定分区数，无时间概念，无需维护 |

well_forum 具体 7 种模式与注册关系（详细定义见 §4.1）：

| 模式 | 分区类型 | 是否注册 | 原因 |
|------|---------|---------|------|
| **A** | RANGE | ✅ | 按 `created_at` 季度归档，需要创建未来分区 + 清理过期数据 |
| **B** | RANGE + HASH (`thread_id`, 8) | ✅ | RANGE 部分需维护，HASH 自动散列 |
| **C** | RANGE + HASH (`thread_id`, 16) | ✅ | 同上（子分区数更多） |
| **D** | RANGE + HASH (`user_id`, 8) | ✅ | 同上（子分区列为 `user_id`） |
| **E** | RANGE + HASH (`user_id`, 8) | ✅ | 同上（分区键为 `reply_created_at`） |
| **F** | RANGE + HASH (`user_id`, 8) | ✅ | 同上（消息通知表） |
| **G** | HASH | ❌ | 固定分区数（64/128），无时间概念，无需维护 |

**关键判断标准**：只要包含 `RANGE` 就需要注册。纯 `HASH` 不需要。

### 9.5 三层维护机制

| 层级 | 机制 | 触发方式 |
|------|------|---------|
| L1 惰性检查 | `maintainIfNeeded()` | 请求驱动，FPM 直接执行 / Swoole 投递任务 |
| L2 后台 | `POST /admin/PostPartitionMaintain` | 管理员手动点"执行维护" |
| L3 定时 | `PartitionMaintainJob` | Scheduler 每日 03:00，自循环 |

三级可叠加，`maintain()` 内部 Redis 分布式锁保证幂等。

### 9.6 参考实现

* **注册示例**：[well_forum/install.php](/plugins/well_forum/install.php)（18 张分区表注册）
* **核心服务**：[PartitionManager.php](/src/Database/Partition/PartitionManager.php)
* **定时任务**：[PartitionMaintainJob.php](/app/Jobs/PartitionMaintainJob.php)
* **迁移脚本**：[bin/migrate-partitions-prod](/bin/migrate-partitions-prod)
