# WellCMS 3.0

English | [简体中文](./README.zh-CN.md)

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-8892BF.svg)

WellCMS 3.0 是一款采用**自研轻量级框架**构建的内容管理系统（CMS），**零 Composer 依赖**，支持 **FPM** 与 **Swoole** 双模式运行。它通过编译缓存、IoC 容器预编译、插件化架构和协程安全设计，实现了高性能与高扩展性的平衡。

---

## ✨ 核心特性

- **零外部依赖** — 不依赖 Composer，开箱即用
- **FPM / Swoole 双模式** — 既能在虚拟主机运行，也能承载高并发场景
- **PSR-7 兼容** — 标准化的请求/响应对象与中间件管道
- **自研 IoC 容器** — O(1) 循环依赖检测、反射缓存、延迟加载代理
- **编译缓存体系** — 模板、语言包、容器、资产全自动编译缓存
- **数据库抽象** — 查询构造器支持 MySQL / PostgreSQL / SQLite
- **连接池与分片** — 协程连接池、读写分离、一致性哈希分片路由
- **多级缓存** — Redis / Memcached / APCu / YAC / Static 多层调度
- **插件化扩展** — 钩子注入、文件覆盖、语言包扩展、静态资产聚合
- **任务调度器** — 基于 Redis 队列的常驻进程任务调度
- **安全防护** — XSS 过滤、CSRF 防护、Token 验证、限流、登录校验

---

## 📁 目录结构

```
wellcms_3.0/
├── app/              # 应用层（控制器、服务、中间件、模型、视图）
├── bin/              # CLI 入口（Swoole 服务器 / 任务调度器）
├── config/           # 运行时配置文件
├── install/          # 图形化安装向导
├── public/           # Web 入口与静态资源
├── src/              # 框架层（IoC、HTTP、DB、Cache、Session、Scheduler）
├── storage/          # 日志、编译缓存、上传文件
├── themes/           # 主题风格目录
└── plugins/          # 插件目录
```

> `plugins/` 与 `themes/` 中的文件在运行时通过 `Compile.php` 合并到主程序中。

---

## 🚀 环境要求

- **PHP** ≥ 7.2（测试兼容至 8.3）
- **扩展**：PDO、mbstring、gd / imagick、fileinfo、openssl
- **Swoole 模式**（可选）：swoole 扩展 ≥ 4.5
- **数据库**：MySQL / PostgreSQL / SQLite

---

## ⚡ 安装

### 方式一：FPM 部署

1. 将 Web 服务器文档根目录指向 `public/`
2. 确保 `storage/`、`config/`、`install/` 目录可写
3. 配置 Nginx 伪静态规则（详见 `nginx.conf.example`）：
   - 插件/主题/视图/上传目录需配置 `alias` 静态资源映射
   - 伪静态支持 3 种 URL 风格：`user-login.html` | `/user/login.html` | `/user/login`
4. 访问 `http://your-domain/install/` 完成 5 步安装向导
5. 安装完成后请确保 `install/` 目录存在（`app/Bootstrap.php` 依赖 `install/install.lock` 判断安装状态），建议通过 Web 服务器配置禁止直接访问 `install/` 目录

### 方式二：Swoole 部署

```bash
# 启动 Swoole 服务器（默认端口 9501）
php bin/well-server start

# 后台守护进程
php bin/well-server start -d

# 停止 / 重启 / 热重载 / 状态
php bin/well-server stop
php bin/well-server restart
php bin/well-server reload
php bin/well-server status
```

> 📖 更详细的 Swoole 生产部署说明请参考：[`bin/SWOOLE_DEPLOY_GUIDE.md`](./bin/SWOOLE_DEPLOY_GUIDE.md)

---

## 🛠️ CLI 工具

| 命令 | 说明 |
|------|------|
| `php bin/well-server {start\|stop\|restart\|reload\|status}` | Swoole 服务器管理 |
| `php bin/scheduler` | 启动常驻任务调度器 |
| `php bin/scheduler-health-check` | 调度器健康检查 |

> 📖 调度器生产部署与运维指南请参考：[`bin/SCHEDULER_DEPLOY_GUIDE.md`](./bin/SCHEDULER_DEPLOY_GUIDE.md)

---

## 📖 文档

- [WellCMS 3.0 详细介绍](./docs/WELLCMS_3.0_INTRODUCTION.md)

---

## 📄 许可证

本项目基于 [MIT License](./LICENSE) 开源。

Copyright (c) 2026 WellCMS
