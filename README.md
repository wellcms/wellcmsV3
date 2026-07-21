# WellCMS 3.0

[English](./README.md) | [简体中文](./README.zh-CN.md)

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-8892BF.svg)

WellCMS 3.0 is a content management system (CMS) built on a **self-developed lightweight framework** with **zero Composer dependencies**, supporting dual runtime modes: **FPM** and **Swoole**. It balances high performance and extensibility through compiled caching, IoC container pre-compilation, plugin-based architecture, and coroutine-safe design.

---

## ✨ Core Features

- **Zero External Dependencies** — No Composer required, works out of the box
- **FPM / Swoole Dual Mode** — Runs on shared hosting or high-concurrency scenarios
- **PSR-7 Compatible** — Standardized request/response objects and middleware pipeline
- **Self-developed IoC Container** — O(1) circular dependency detection, reflection caching, lazy-loading proxies
- **Compiled Caching System** — Automatic compilation caching for templates, language packs, container, and assets
- **Database Abstraction** — Query builder supporting MySQL / PostgreSQL / SQLite
- **Connection Pooling & Sharding** — Coroutine connection pools, read/write splitting, consistent-hash sharding router
- **Multi-level Caching** — Redis / Memcached / APCu / YAC / Static multi-layer scheduling
- **Plugin-based Extensions** — Hook injection, file overrides, language pack extensions, static asset aggregation
- **Task Scheduler** — Resident process task scheduling based on Redis queues
- **Security Protection** — XSS filtering, CSRF protection, token verification, rate limiting, login authentication

---

## 📁 Directory Structure

```
wellcms_3.0/
├── app/              # Application layer (controllers, services, middleware, models, views)
├── bin/              # CLI entry points (Swoole server / task scheduler)
├── config/           # Runtime configuration files
├── install/          # Graphical installation wizard
├── public/           # Web entry point and static assets
├── src/              # Framework layer (IoC, HTTP, DB, Cache, Session, Scheduler)
├── storage/          # Logs, compiled cache, uploaded files
├── themes/           # Theme directory
└── plugins/          # Plugin directory
```

> Files in `plugins/` and `themes/` are merged into the main program at runtime via `Compile.php`.

---

## 🚀 Requirements

- **PHP** ≥ 7.2 (tested up to 8.3)
- **Extensions**: PDO, mbstring, gd / imagick, fileinfo, openssl
- **Swoole Mode** (optional): swoole extension ≥ 4.5
- **Database**: MySQL / PostgreSQL / SQLite

---

## ⚡ Installation

### Method 1: FPM Deployment

1. Point your web server document root to `public/`
2. Ensure `storage/`, `config/`, and `install/` directories are writable
3. Configure Nginx rewrite rules (see `nginx.conf.example`):
   - Plugin/theme/view/upload directories require `alias` static resource mapping
   - Rewrite supports 3 URL styles: `user-login.html` | `/user/login.html` | `/user/login`
4. Visit `http://your-domain/install/` to complete the 5-step installation wizard
5. After installation, ensure the `install/` directory remains (`app/Bootstrap.php` uses `install/install.lock` to detect installation status). It is recommended to block direct access to `install/` via web server configuration

### Method 2: Swoole Deployment

```bash
# Start Swoole server (default port 9501)
php bin/well-server start

# Run as daemon
php bin/well-server start -d

# Stop / restart / reload / status
php bin/well-server stop
php bin/well-server restart
php bin/well-server reload
php bin/well-server status
```

> 📖 For detailed Swoole production deployment instructions, see [`bin/SWOOLE_DEPLOY_GUIDE.md`](./bin/SWOOLE_DEPLOY_GUIDE.md)

---

## 🛠️ CLI Tools

| Command | Description |
|---------|-------------|
| `php bin/well-server {start\|stop\|restart\|reload\|status}` | Swoole server management |
| `php bin/scheduler` | Start resident task scheduler |
| `php bin/scheduler-health-check` | Scheduler health check |

> 📖 For scheduler production deployment and operations guide, see [`bin/SCHEDULER_DEPLOY_GUIDE.md`](./bin/SCHEDULER_DEPLOY_GUIDE.md)

---

## 📖 Documentation

- [WellCMS 3.0 Detailed Introduction](./docs/WELLCMS_3.0_INTRODUCTION.md)

---

## 📄 License

This project is open-sourced under the [MIT License](./LICENSE).

Copyright (c) 2026 WellCMS
