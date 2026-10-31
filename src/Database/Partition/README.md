# Framework\Database\Partition — 数据库分区管理器

**命名空间**: `Framework\Database\Partition`  
**物理路径**: `src/Database/Partition/`  
**PHP 兼容**: 7.2+（不使用 typed properties / union types / named arguments）  
**运行模式**: FPM + Swoole 双模式

---

## 概述

PartitionManager 是一个轻量级的 MySQL RANGE 分区生命周期管理工具。它解决的核心问题：

- 分区定义在 `install.php` 中写死（如 `p2026Q1`–`p2026Q4`），但分区维护是运行时需求
- 没有自动机制创建未来的分区（如 `p2027Q1`、`p2027Q2`）
- 没有自动机制清理过期分区（数据归档）
- 每个插件各自实现分区维护逻辑，产生重复代码

PartitionManager 接管了所有 RANGE 分区表的创建、维护和清理，插件只需一行 `register()` 声明。

---

## 架构

```
┌─────────────────────────────────────────────────────────┐
│  install.php / uninstall.php                            │
│    $pm->register() / $pm->unregister()                  │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  PartitionRegistry                                      │
│  注册表管理（缓存持久化 + information_schema 自动发现）    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  PartitionManager                                       │
│  maintain() — 全量维护                                  │
│  maintainOne() — 单表维护                               │
│  maintainIfNeeded() — 请求驱动惰性检查（双模式）         │
└──────┬─────────────────────────────────────────┬────────┘
       │                                         │
       ▼                                         ▼
  CREATE/ADD/DROP                            Scheduler Job
  (专用连接 + Redis 锁)                     (异步投递 + 自循环)
```

---

## 快速开始

### 1. 在 install.php 中注册分区表

```php
<?php

declare(strict_types=1);

use Framework\Database\Partition\PartitionManager;
use Framework\Database\Partition\PartitionConfig;

// 建表逻辑保持不变（含 PARTITION BY RANGE ...）
// ...

// 建表后追加注册（PHP 7.2 位置参数）
$pm = $this->container->get(PartitionManager::class);

$pm->register(new PartitionConfig(
    'forum_thread',    // 纯表名，不含前缀
    'created_at',      // 分区列
    'quarter',         // 周期：quarter 或 month
    4,                 // advanceCount：预创建 4 个季度
    8                  // retention：保留 8 个季度
));
```

### 2. 在 uninstall.php 中注销

```php
$pm = $this->container->get(PartitionManager::class);
$pm->unregister('forum_thread');
```

### 3. 为冷发现提供准确声明

安装阶段仍通过 `register()` 注册，卸载仍通过 `unregister()` 注销。注册缓存丢失后，物理目录能发现 RANGE 表，但不能还原保留期限等政策。应用原工厂在冷发现时读取各插件 `config.json` 的可选 `partitions`：

```json
{
  "partitions": {
    "example_log": ["occurred_at", "month", 4, 0, null, 0],
    "example_reply": ["created_at", "quarter", 4, 8, "thread_id", 8]
  }
}
```

键为无前缀表名，值严格按原生构造其余六个参数排列：分区列、month/quarter、预建数、保留期数、HASH 子分区列、子分区数。预建数为正整数，保留期数非负，0 为永久保留；无子分区使用 `null, 0`，有子分区使用非空列名和正整数。此声明与安装实际结构/注册政策必须一致，不代表业务删除授权。

Framework 的 `PartitionRegistry` 只接收可选第四构造参数 `?callable $declaredConfigProvider = null`，来源返回 `array<string, PartitionConfig>`，不认识插件或目录。原应用工厂提供清单来源；其他宿主可提供自己的来源。构造、注册和普通 `get/getAll` 不执行来源；一次实际冷发现至多调用一次，物理目录无表时不调用。原前三参数的调用保持有效，不强制迁移现存消费者。

优先使用真实表的完整插件声明，其次使用完整持久登记。两种来源都缺失时明确报错，禁止以 created_at、quarter、预建4或永久保留等默认值冒充恢复结果。恢复前核验真实整数分区列、HASH子分区列及每个父分区的子分区数量；表达式分区、日期列、结构冲突或元数据读取失败均阻止该批策略保存及DDL。完整声明可修复残缺旧登记，但必须通过同样的结构核验。清单不按启用/安装状态过滤，不加载插件代码，不自行创建普通表的分区。

扫描范围为默认主库，以及声明/登记通过 resolveShard() 定位到的整表独立主库；单表维护在所属主库执行同样的恢复核验。按键分片缺少定位上下文时明确失败，不猜测节点。固定 HASH 分区不套用时间维护；单表指定 HASH 表会明确报告其类型。后台状态页先恢复登记再读取状态，手动维护直接走统一维护入口。

DDL 方言、会话超时、未来边界、正保留期清理及物理缓存代际失效保持原流程；`retention=0` 不清理过期分区。单表维护获取全局锁失败时不建连接、不执行 DDL，返回原 `MaintenanceResult` 的 `errors=1`，使 CLI 非零退出、安装不误报初始化完成；成功路径不变。全量维护的原锁忙跳过语义不改。原300秒锁租约没有续期，本扩展不证明长批次并发或卸载排空已经安全；FPM/Swoole、MySQL/PG 的实际运行仍需对应环境验收。

### 4. 迁移存量系统

```bash
# 审计模式（审查 SQL，不改库）
php bin/migrate-partitions-prod --audit

# 执行模式（低峰期运行）
php bin/migrate-partitions-prod --execute

# 验证模式
php bin/migrate-partitions-prod --verify
```

---

## API 参考

### PartitionConfig

分区配置值对象。所有属性构造后只读。

```php
$config = new PartitionConfig(
    string $table,               // 纯表名，不含前缀（如 'forum_thread'）
    string $partitionColumn,     // 分区列（如 'created_at'）
    string $period = 'quarter',  // 周期：PartitionPeriod::Quarter / Month
    int $advanceCount = 4,       // 预创建分区数
    int $retention = 0,          // 保留周期数（0 永不清除）
    string $subPartitionColumn = null,  // 子分区列（如 'thread_id'）
    int $subPartitions = 0       // 子分区数
);
```

### PartitionPeriod

周期计算工具（final class + const，PHP 7.2 兼容枚举）。

| 方法 | 说明 |
|------|------|
| `PartitionPeriod::nextBoundary(int $ts, string $period): int` | 计算下一个边界时间戳 |
| `PartitionPeriod::ago(int $ts, string $period, int $count): int` | 计算 N 个周期前的时间戳 |
| `PartitionPeriod::formatName(int $ts, string $period): string` | 格式化分区名（如 `p2026Q1`） |

### PartitionRegistry

分区注册表管理。

| 方法 | 说明 |
|------|------|
| `register(PartitionConfig $config)` | 注册一张表的分区配置 |
| `unregister(string $table)` | 注销一张表 |
| `getAll(): array` | 获取所有注册配置 |
| `get(string $table): ?PartitionConfig` | 获取单表配置 |
| `discoverExisting(string $prefix): int` | 扫描 information_schema 接管存量表 |

### PartitionManager

核心分区管理服务。

| 方法 | 说明 | 安全机制 |
|------|------|---------|
| `maintain(bool $dryRun = false): MaintenanceResult` | 全量维护：创建未来分区 + 删除过期分区 | Redis 锁 + 专用连接 |
| `maintainOne(string $table, bool $dryRun = false): MaintenanceResult` | 单表维护 | 同上 |
| `maintainIfNeeded(): bool` | 请求驱动惰性检查（FPM 直接 DDL / Swoole 投递任务） | 三级短路 + Redis 锁 |
| `register(PartitionConfig $config)` | 委托给 Registry | — |
| `unregister(string $table)` | 委托给 Registry | — |
| `adoptExistingTables(): int` | 扫描 information_schema 接管存量表 | 幂等 |
| `getStatus(): array` | 获取所有表的状态摘要 | — |

### MaintenanceResult

维护操作结果值对象。

| 属性 | 类型 | 说明 |
|------|------|------|
| `$tablesScanned` | `int` | 检查的表数 |
| `$partitionsCreated` | `int` | 新增分区数 |
| `$partitionsDropped` | `int` | 删除分区数 |
| `$errors` | `int` | 错误数 |
| `$errorDetails` | `array` | 错误详情（含 SQL 和异常消息） |
| `$executionMs` | `float` | 执行耗时（毫秒） |
| `$trigger` | `string` | 触发方式（scheduler/admin/cli/lazy） |

---

## 三层调度机制

| 层级 | 触发方式 | 依赖 | 适用场景 |
|------|---------|------|---------|
| L1 | `maintainIfNeeded()` 请求驱动惰性检查 | 仅需 Cache | FPM 共享主机、小站点 |
| L2 | 后台管理 `POST /admin/PostPartitionMaintain` | 无 | 所有部署模式 |
| L3a | `PartitionMaintainJob` Scheduler 定时（推荐） | Scheduler daemon | Supervisor 托管的正式部署 |
| L3b | `php bin/partition` 系统 crontab | 系统 crontab | 无 Scheduler 的 VPS |

L1 + L2 + L3 可同时开启，`maintain()` 内部通过 Redis 分布式锁保证幂等。

---

## PHP 7.2 兼容说明

- 不使用 typed properties（`public int $x`）→ 使用 `/** @var int */` PHPDoc
- 不使用 union types（`?string` 可用，PHP 7.1+）→ `string $x = null`
- 不使用 named arguments → 全部使用位置参数
- 不使用 `?->` nullsafe 运算符 → 使用 `isset()` 或三元
- 不使用 match expression → 使用 `if/else` 或 `switch`
- PartitionPeriod 用 `final class + const` 模拟枚举

---

## Swoole 兼容说明

| 维度 | FPM | Swoole |
|------|-----|--------|
| 连接管理 | 短连接 PDO | 连接池，DDL 需 `createFreshConnection()` 钉住专用连接 |
| L1 惰性检查 | 请求中直接执行 `maintain()` | 仅投递 `PartitionMaintainJob` 到 Scheduler |
| 锁机制 | Redis 锁 | Redis 锁（与 FPM 共享） |
| StatefulTrait | 不需要 | 不需要（PartitionManager 是全局单例） |

---

## 用例

### 用例 1：插件注册分区表

```php
// RANGE 分区（季度，按 created_at）
$pm->register(new PartitionConfig('my_table', 'created_at', 'quarter', 4, 8));

// RANGE + HASH 子分区
$pm->register(new PartitionConfig(
    'my_table', 'created_at', 'quarter', 4, 8, 'user_id', 8
));

// 月度分区
$pm->register(new PartitionConfig('stats_log', 'created_at', 'month', 12, 24));
```

### 用例 2：存量系统迁移

```bash
# 1. 审查迁移计划
php bin/migrate-partitions-prod --audit

# 2. DBA 确认后执行
php bin/migrate-partitions-prod --execute

# 3. 验证结果
php bin/migrate-partitions-prod --verify
```

### 用例 3：手动维护

```bash
# 全量维护
php bin/partition

# 预览
php bin/partition --dry-run

# 单表
php bin/partition --table=forum_thread
```

### 用例 4：管理员后台

访问 `/admin/PartitionStatus` 查看分区状态，点击"执行维护"按钮。

---

## DDL 安全策略

1. **Redis 分布式锁**：所有 DDL 前通过 `CacheInterface::lock()` 获取锁，防并发
2. **专用连接**：通过 `createFreshConnection()` 获取钉住的 PDO 连接，避免连接池导致 `GET_LOCK` 失效
3. **DDL 超时**：`SET SESSION lock_wait_timeout = 10`
4. **熔断**：单张表 DDL 连续失败 3 次跳过，不阻塞全量维护
5. **MDL 风险**：REORGANIZE pmax 持有元数据锁，建议低峰期执行

---

## 参考实现

- `plugins/well_forum/install.php` — 17 张分区表的注册示例
- `bin/migrate-partitions-prod` — 存量系统迁移脚本
- `app/Jobs/PartitionMaintainJob.php` — 单任务自循环 Job 模式

### 分区策略修复后的部署

宿主将注册表保存在现有 KV 的 setting.partition_registry_configs 域。缓存仅作完整记录的恢复线索，维护核验通过后才迁移持久化；缺字段不填默认值。论坛新安装及升级使用 config.json 的17张表永久保留声明，下一次全量维护也会核验声明并纠正旧推断。修复登记不重写物理表结构，也不恢复过去已经删除的数据。

全量维护每次补查缺失的表；明确登记的正 retention 仍会执行过期分区删除。生产更新代码后按原发布流程重建编译与资产清单，并重载常驻 Worker。

当前插件安装 schema 中的 99 张 RANGE 分区表均有明确被动声明（包含消息、视频 SEO 与 Sinter 三服务），按真实分区列、周期、子分区和业务保留期恢复并持久化；普通未分区表不参与接管。外部遗留表仍须补齐对应插件声明，不能从物理结构臆造删除期限。

### 准确恢复回归测试

`tests/Database/Partition/PartitionRecoveryContractTest.php` 覆盖缺配置、结构冲突、缓存/KV失败及批次不发布部分策略；`PluginPartitionDeclarationContractTest.php` 核对当前99张RANGE表的声明与安装schema。

`PartitionRecoveryDatabaseIntegrationTest.php` 只允许连接数据目录位于指定 `/tmp/wellcms-partition-db-*` 根内的独立测试实例，默认验证MySQL和PostgreSQL；`WELLCMS_PARTITION_TEST_DRIVERS=mysql` 可单独运行旧MySQL兼容测试。测试创建随机库/schema并只清理自身夹具，不读取站点数据库配置。

MySQL分支最低兼容5.5.6，使用PARTITIONS/COLUMNS和常规JOIN、GROUP BY、CASE聚合，不使用CTE、窗口函数、JSON或8.0数据字典。PostgreSQL分支使用自身目录查询，二者不混用语法。

本轮真实数据库验收覆盖 MySQL 5.5.6-rc、MySQL 8.4.8、PostgreSQL 18：恢复登记、主库路由、分区列/类型/子分区核验、未知表拒绝猜测、KV失败无DDL，以及实际预建/删除边界。MySQL 5.5.6 测试使用官方原版二进制独立实例，未以较新版本模拟。
