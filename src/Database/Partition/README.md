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

### 3. 迁移存量系统

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
    int $retention = 8,          // 保留周期数（0 永不清除）
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

- `plugins/well_forum/install.php` — 18 张分区表的注册示例
- `bin/migrate-partitions-prod` — 存量系统迁移脚本
- `app/Jobs/PartitionMaintainJob.php` — 单任务自循环 Job 模式
