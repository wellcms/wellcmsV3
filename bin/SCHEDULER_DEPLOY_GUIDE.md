# WellCMS 3.0 Scheduler 部署与运维指南

> **使命必达：毫秒级高性能异步任务调度系统。** 本文档旨在帮助用户快速启动并管理 WellCMS 3.0 的常驻任务调度器（Scheduler），确保系统自动任务（如备份、清理、队列处理）稳定运行。

---

## 🚀 第一部分：环境要求 (Requirements)

Scheduler 是系统的“心跳”，它负责在后台处理所有耗时或定时的任务。由于其常驻内存的特性，**生产环境必须使用 Supervisor 等进程守护工具运行**。

### 1. 环境准备

确保您的服务器已安装：
*   **Redis**: **强制要求**。Scheduler 依赖 Redis 进行任务队列的原子化存取。
*   **PHP**: 7.4 或更高版本（需安装 `redis` 扩展）。**PHP 8.4+ 需确保源码已同步最新兼容性修复**（如 `App\Bootstrap` 中 `DEBUG` 常量命名空间解析等）。
*   **目录权限**: `storage/tmp/` 与 `storage/logs/` 必须对运行用户可写。Scheduler 启动时会编译并写入 `storage/tmp/classes/` 缓存。

### 2. 运行模式说明

*   **生产模式 (推荐)**：必须使用 Supervisor 运行，支持故障自愈与优雅重启。
*   **调试模式 (仅开发)**：可在终端临时执行 `php bin/scheduler` 观察日志信息。
    > 💡 若启动后无任何输出且进程退出，可编辑 `bin/scheduler` 第 21 行将 `DEBUG` 设为 `1` 或 `2` 查看详细错误。

---

## 📋 第二部分：核心功能与设计 (Core Design)

WellCMS 3.0 Scheduler 采用了工业级的“生产者-消费者”模型。

### 1. 任务类型支持

*   **异步任务 (Async Jobs)**：如邮件发送、图片压缩。
*   **定时任务 (Crontab)**：基于 Redis 延迟队列实现的毫秒级定时任务。
*   **系统维护 (System Maintenance)**：自动清理过期 Session、重建 Classmap 缓存等。

### 2. 稳定性设计

*   **故障自愈**：脚本内部具备 `Throwable` 捕获机制，单个任务崩溃不会导致调度器停止。
*   **内存监控**：会自动监测 PHP 运行时的实测内存占用，达到阈值后配合 Supervisor 实现优雅自重启。
*   **隔离引导**：使用 `SCHEDULER_MODE` 常量，启动时仅加载最小化核心，跳过 Web 端的 Session 和 Cookie 损耗。

---

## 🛠 第三部分：工业级生产环境部署 (Supervisor)

**`bin/scheduler` 目前只支持在 Supervisor 环境下长期稳定运行**。Supervisor 能确保进程意外退出时秒级拉起。

### 1. 部署前准备

```bash
cd /path/to/wellcms

# 1. 确保运行用户对 storage/tmp/ 和 storage/logs/ 可写
#    Scheduler 启动时会编译 PHP 文件到 storage/tmp/classes/，权限不足会导致崩溃
sudo chown -R www:www storage/tmp/ storage/logs/
sudo chmod -R 755 storage/tmp/ storage/logs/

# 2. 清理旧编译缓存（如果从其他环境同步或更新代码后，建议执行）
#    否则旧缓存可能导致代码修复不生效
sudo rm -rf storage/tmp/classes/* storage/tmp/container.php

# 3. 验证 Redis 连接
php -r "require 'app/Core/Compile.php'; require 'app/Core/Autoload.php'; \$r = \App\Bootstrap::init()->get(\Framework\Cache\Interfaces\CacheInterface::class)->original('redis'); echo \$r->ping() ? 'Redis OK' : 'Redis FAIL';"
```

### 2. 命令行参数说明

`bin/scheduler` 支持以下参数：

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `--max-runs` | `5000` | 处理任务数上限。空转（队列无任务）不计入。达到上限后进程优雅退出，由 Supervisor 重启。 |
| `--memory` | `128` | 内存上限（单位：MB）。当 PHP 实际占用内存超过此值时，进程优雅退出，由 Supervisor 重启。 |

### 3. 手动运维命令（调试/临时运行）

```bash
cd /path/to/wellcms

# 前台启动（Ctrl+C 停止，适合调试）
php bin/scheduler

# 指定任务数和内存上限
php bin/scheduler --max-runs=5000 --memory=128

# 后台运行（无 Supervisor 时的临时方案）
nohup php bin/scheduler --max-runs=5000 --memory=128 > storage/logs/scheduler.out 2>&1 &

# 停止
pkill -f "php bin/scheduler"

# 查看实时日志
tail -f storage/logs/scheduler.out
```

### 4. Supervisor 配置示例

在 `/etc/supervisor/conf.d/` 目录下创建 `wellcms-scheduler.conf`：

```ini
[program:wellcms-scheduler]
; 修改为您的项目实际路径
command=php /path/to/wellcms/bin/scheduler --max-runs=5000 --memory=128
directory=/path/to/wellcms
user=www
autostart=true
autorestart=true
startsecs=5
stderr_logfile=/var/log/wellcms_scheduler.err.log
stdout_logfile=/var/log/wellcms_scheduler.out.log
; 优雅关机
stopsig=TERM
stopwaitsecs=30
```

### 5. 常用管理命令

```bash
# 更新配置并启动
supervisorctl reread
supervisorctl update
supervisorctl start wellcms-scheduler

# 查看状态
supervisorctl status wellcms-scheduler

# 查看运行日志
tail -f /var/log/wellcms_scheduler.out.log
```

---

## 🚨 第五部分：故障排查

### 1. 启动时 `Permission denied` / `Failed to open stream`

**现象**：
```
fopen(.../storage/tmp/classes/...): Failed to open stream: Permission denied
```

**原因**：`storage/tmp/classes/` 目录属主或权限不对，`Compile::init()` 无法写入编译缓存。

**解决**：
```bash
sudo chown -R www:www storage/tmp/ storage/logs/
sudo chmod -R 755 storage/tmp/ storage/logs/
```

### 2. `Undefined constant "App\DEBUG"`

**现象**：PHP 8.4 下启动报错 `Undefined constant "App\DEBUG"`。

**原因**：`app/Bootstrap.php` 等文件中在 `App` 命名空间内直接使用了 `DEBUG` 常量。PHP 8.2+ 不再自动回退到全局命名空间。

**解决**：确保源码已同步最新修复（将 `DEBUG` 改为 `\DEBUG`），并清理 `storage/tmp/classes/*` 缓存。

### 3. Redis 连接失败

**现象**：启动时报错 `Connection refused` 或 `Cannot connect to Redis`。

**原因**：Redis 服务未启动，或 `config/Cache.php` 中 Redis 配置（host/port/password）不正确。

**解决**：
```bash
# 检查 Redis 是否运行
redis-cli ping
# 预期返回 PONG

# 检查配置
grep -A 10 "'redis'" config/Cache.php
```

### 4. 日志位置

| 日志 | 路径 | 说明 |
|------|------|------|
| 前台/后台标准输出 | `storage/logs/scheduler.out` | 手动运行或 nohup 时的输出 |
| Supervisor 标准输出 | `/var/log/wellcms_scheduler.out.log` | Supervisor 托管时的输出 |
| Supervisor 错误日志 | `/var/log/wellcms_scheduler.err.log` | PHP Fatal Error、未捕获异常 |

---

## 🛡 第四部分：自动化监控与报警 (Health Check)

为了保持管理一致性，健康检查脚本建议也通过 Supervisor 以守护进程模式运行。

### 1. 运行方式说明

*   **持续监控 (生产模式)**：使用 `--daemon --interval=60` 参数。
*   **单次巡检 (调试模式)**：直接运行 `php bin/scheduler-health-check`。

### 2. 健康检查配置示例

```ini
[program:wellcms-health-check]
; 每一分钟扫描一次队列积压、Redis 连接及 5 分钟内的日志异常
command=php /path/to/wellcms/bin/scheduler-health-check --daemon --interval=60 --threshold=1000 --webhook="https://oapi.dingtalk.com/robot/send?access_token=xxx"
user=www
autostart=true
autorestart=true
stderr_logfile=/var/log/wellcms_health.err.log
stdout_logfile=/var/log/wellcms_health.out.log
```

---

**WellCMS 研发团队 敬上**  
*最后更新时间：2026-04-19*
