# WellCMS 3.0 Swoole 工业级部署与运维指南

> **让高性能 PHP 触手可及。** 本文档提供从开发调试到工业级生产的完整 Swoole 部署路径，涵盖环境构建、性能调优、进程守护、反向代理、故障排查与监控报警。

---

## 📑 目录

1. [架构概述与组件关系](#1-架构概述与组件关系)
2. [环境准备](#2-环境准备)
3. [快速上手与调试模式](#3-快速上手与调试模式)
4. [核心配置与性能调优](#4-核心配置与性能调优)
5. [生产环境部署：Supervisor 守护](#5-生产环境部署supervisor-守护)
   - 5.6 [systemd 替代方案](#56-替代方案systemd-原生守护可选)
   - 5.7 [日志轮转](#57-日志轮转logrotate)
6. [Nginx 反向代理与静态资源](#6-nginx-反向代理与静态资源)
   - 6.3 [配置要点说明](#63-配置要点说明)
   - 6.4 [SSL 证书配置](#64-ssl-证书配置lets-encrypt)
   - 6.5 [验证 Nginx 配置](#65-验证-nginx-配置并重载)
7. [代码更新与热重载机制](#7-代码更新与热重载机制)
8. [故障排查与日志分析](#8-故障排查与日志分析)
9. [安全加固建议](#9-安全加固建议)
10. [附录：信号与进程参考](#10-附录信号与进程参考)

---

## 1. 架构概述与组件关系

WellCMS 3.0 在 Swoole 模式下采用 **"HTTP Server + 独立任务调度器"** 的双进程架构：

| 组件 | 职责 | 是否常驻 | 说明 |
|------|------|----------|------|
| `bin/well-server` | 处理 HTTP/HTTPS 请求 | 是 | Swoole HTTP Server，协程化、常驻内存 |
| `bin/scheduler` | 异步任务、定时任务、队列消费 | 是 | 独立进程，依赖 Redis，**必须与 Swoole Server 同时运行** |
| `bin/scheduler-health-check` | 监控与报警 | 是/否 | 可选，可守护或单次巡检 |

> ⚠️ **重要**：Swoole Server **不会**替代 Scheduler。邮件发送、图片压缩、Crontab 定时任务等仍由 `bin/scheduler` 处理。生产环境请务必按本文档和 `bin/SCHEDULER_DEPLOY_GUIDE.md` 同时配置两者。

---

## 2. 环境准备

### 2.1 基础依赖

| 依赖 | 最低版本 | 推荐版本 | 说明 |
|------|----------|----------|------|
| PHP | 7.2.0 | 8.1+ / 8.4 | `bin/well-server` 脚本内置 7.2.0 检查；8.4+ 需配合 Swoole 6.0+ 使用 |
| Swoole 扩展 | 4.8 | 5.1.x / 6.0+ | 必须开启 `openssl`；`http2` 视需求可选。6.0+ 与 5.x 存在 API 差异，详见第 8 章故障排查 |
| Redis 服务 | 5.0 | 7.x | 缓存驱动与队列存储；Scheduler 强制依赖 |
| pcntl 扩展 | - | 最新 | 用于信号发送与进程管理；未安装时脚本可兼容运行，但生产建议安装 |
| inotify 扩展 | - | 最新 | **可选**。如需要文件变更自动热重载，建议安装（见第 7 章） |

### 2.2 Swoole 安装与验证

**通过 PECL 安装（推荐）：**

```bash
# Debian / Ubuntu 先确保 php-dev 与 pecl 可用
sudo apt-get update
sudo apt-get install -y php-dev php-pear build-essential libssl-dev

# CentOS / RHEL
sudo yum install -y php-devel php-pear gcc gcc-c++ make openssl-devel

# 安装 Swoole（如需 openssl 支持，PECL 会自动探测；如探测失败请改用源码编译）
sudo pecl install swoole

# 如需指定版本
sudo pecl install swoole-5.1.3
```

**通过源码编译（自定义参数）：**

```bash
# 安装编译依赖（Debian/Ubuntu）
sudo apt-get install -y php-dev php-pear build-essential libssl-dev git

# 安装编译依赖（CentOS/RHEL）
sudo yum install -y php-devel gcc gcc-c++ make openssl-devel git

# 下载源码
cd /usr/local/src
sudo git clone https://github.com/swoole/swoole-src.git
cd swoole-src
sudo git checkout v5.1.3  # 替换为所需版本

# 编译安装
sudo phpize
# Swoole 5.x 使用以下参数：
sudo ./configure --enable-openssl --enable-http2
# Swoole 6.0+ 使用以下参数（--enable-openssl 已废弃）：
# sudo ./configure --with-openssl-dir=/usr --enable-http2

sudo make && sudo make install
```

**写入 PHP 配置并启用：**

```bash
# 查找 PHP CLI 配置目录
php_ini_dir=$(php -r "echo dirname(PHP_CONFIG_FILE_PATH);")

# 写入 CLI 环境（Swoole  Server / Scheduler 必须）
sudo bash -c "echo 'extension=swoole.so' > ${php_ini_dir}/cli/conf.d/99-swoole.ini"

# ⚠️ 重要：Swoole 的异步 IO（Timer、Event 等）只能在 CLI 模式下使用。
# 若在 FPM 中加载 swoole.so，可能导致 CoroutineConnectionPool 等组件报错：
# "async-io must be used in PHP CLI mode"
# 因此除非确有必要，否则不建议在 FPM 中加载 Swoole。
# sudo bash -c "echo 'extension=swoole.so' > ${php_ini_dir}/fpm/conf.d/99-swoole.ini"

# 如 php_ini_dir 变量为空，可手动确认路径
php -i | grep "Scan this dir for additional .ini files"
```

**验证安装：**

```bash
php --ri swoole | grep -E "Version|openssl|http2|coroutine"
# 预期输出包含 Version、openssl enabled 等信息

# 验证 pcntl（生产强烈建议安装）
php --ri pcntl | grep "pcntl support"

# 验证命令行可直接调用 well-server
php /www/wwwroot/wellcms/bin/well-server status
```

### 2.3 目录权限

Swoole 进程需要对以下目录有读写权限。以下命令以项目部署在 `/www/wwwroot/wellcms`、运行用户为 `www` 为例：

```bash
# 1. 创建专用运行用户（如无）
id -u www &>/dev/null || sudo useradd -s /sbin/nologin -M www

# 2. 设置项目根目录属主
sudo chown -R www:www /www/wwwroot/wellcms

# 3. 设置存储目录权限
sudo chmod -R 755 /www/wwwroot/wellcms/storage
sudo chmod -R 775 /www/wwwroot/wellcms/storage/logs
sudo chmod -R 775 /www/wwwroot/wellcms/storage/tmp
sudo chmod -R 775 /www/wwwroot/wellcms/storage/upload

# 4. 确保 public 目录可读
sudo chmod -R 755 /www/wwwroot/wellcms/public

# 5. 验证 PID 目录可写
sudo -u www touch /www/wwwroot/wellcms/storage/tmp/.write-test && rm -f /www/wwwroot/wellcms/storage/tmp/.write-test
echo "权限检查通过"
```

> `storage/tmp` 用于存放 PID 文件（`wellcms.pid`）与容器编译缓存（`container.php`），必须可写。

---

## 3. 快速上手与调试模式

### 3.1 DEBUG 常量说明

`bin/well-server` 脚本第 15 行定义了 `DEBUG` 常量，**该值直接影响错误报告级别与异常显示**：

| DEBUG 值 | 含义 | 适用场景 |
|----------|------|----------|
| `0` | 关闭错误显示；`error_reporting(0)` | **生产环境必须设置** |
| `1` | 显示除 Notice/Deprecated 外的错误 | 测试环境 |
| `2` | 显示所有错误（`E_ALL`） | 开发调试 |

修改方式：编辑 `bin/well-server` 第 15 行：

```php
define('DEBUG', 0); // 生产环境
```

> 💡 **排查提示**：如果前台启动 `php bin/well-server start` 后 Banner 已打印但进程立即退出，且 `storage/logs/swoole.log` 为空，请临时将 `DEBUG` 设为 `2` 重新启动，即可看到隐藏的 PHP Fatal Error（常见于 Swoole 6.0 API 兼容性问题）。

### 3.2 基础运维命令

```bash
# 赋予执行权限
chmod +x bin/well-server

# 前台启动（调试，Ctrl+C 停止）
php bin/well-server start

# 后台守护启动（生产调试首次验证）
php bin/well-server start -d

# 指定主机与端口启动
php bin/well-server start -d -h 127.0.0.1 -p 9502

# 查看运行状态
php bin/well-server status

# 平滑热重载（仅重启 Worker，不断开连接）
php bin/well-server reload

# 完全重启（停止 -> 检测 -> 启动）
php bin/well-server restart -d

# 停止服务
php bin/well-server stop
```

**命令参数对照表：**

| 命令 | 作用 | 参数 |
|------|------|------|
| `start` | 启动服务 | `-d` 后台守护, `-h` 监听主机, `-p` 监听端口 |
| `stop` | 停止服务 | 自动等待进程释放端口，清理 Stale PID |
| `restart` | 完全重启 | 先 stop 再 start，适用于修改 Master 进程逻辑后 |
| `reload` | 平滑热重载 | 向 Worker 发送 SIGUSR1，仅重载业务代码 |
| `status` | 状态查询 | 显示 PID、运行路径、进程状态 |

---

## 4. 核心配置与性能调优

### 4.1 默认参数解析

`src/Http/SwooleServer.php` 内置了以下工业级默认值：

```php
[
    'worker_num'       => swoole_cpu_num(),   // 默认等于 CPU 核心数
    'open_tcp_nodelay' => true,               // 关闭 Nagle 算法，降低延迟
    'max_request'      => 10000,              // 每个 Worker 处理 1 万请求后自动重启
    'daemonize'        => false,              // 默认前台运行
    'reload_async'     => true,               // 异步重载，优雅退出
    'max_wait_time'    => 30,                 // Worker 退出最大等待 30 秒
    'pid_file'         => storage/tmp/wellcms.pid,
    'log_file'         => storage/logs/swoole.log,
    'enable_coroutine' => true,               // 启用协程
]
```

### 4.2 自定义核心参数

当前 `bin/well-server` 脚本仅通过命令行暴露 `-h`、`-p`、`-d` 三个参数。如需调整 `worker_num`、`max_request` 等，**建议复制一份自定义启动脚本**：

```bash
cp bin/well-server bin/well-server-prod
```

修改第 88 行，传入自定义配置数组：

```php
$server = new \Framework\Http\SwooleServer($host, $port, [
    'daemonize'    => $daemon,
    'worker_num'   => 8,          // 根据 CPU 核心数与业务 IO 密集度调整
    'max_request'  => 50000,      // 业务稳定后可适当增大，减少 Worker 重启频率
    'task_worker_num' => 4,       // 如需使用 Swoole Task，启用 Task Worker
    'reactor_num'  => 4,          // Reactor 线程数，默认等于 CPU 核数
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time'      => 120,
]);
```

### 4.3 调优建议

| 参数 | 建议值 | 说明 |
|------|--------|------|
| `worker_num` | `CPU核数 * 2 ~ 4` | IO 密集型（多数据库/Redis 调用）可适当增大；CPU 密集型保持等于核数 |
| `max_request` | `10000 ~ 100000` | 业务代码存在轻微内存泄漏时，减小该值可充当兜底；代码严谨后可增大 |
| `task_worker_num` | `CPU核数` | 仅在使用 Swoole Task 投递异步任务时启用 |
| `reactor_num` | `CPU核数` | 处理连接 accept，通常无需调整；高并发短连接可设为 `CPU * 2` |
| `heartbeat_idle_time` | `120` | 自动清理死连接，防止句柄泄漏 |

### 4.4 内存与连接池

- **PHP 内存限制**：建议为 Swoole Worker 设置充足的 `memory_limit`。在 Supervisor 的 `environment` 中配置：
  ```ini
  environment=PHP_MEMORY_LIMIT="512M"
  ```
- **数据库连接池**：WellCMS 3.0 已内置协程连接池。确保 `config/Database.php` 中启用了连接池模式，并合理设置 `max_connections`（通常 `worker_num * 10` 为安全上限）。
- **Redis 连接**：同样使用连接池，避免每个协程独立创建连接导致句柄打满。

---

## 5. 生产环境部署：Supervisor 守护

生产环境严禁直接前台运行 `php bin/well-server start`。请使用 **Supervisor** 进行进程守护，实现崩溃自启、优雅关机与日志分割。

### 5.1 安装 Supervisor

**Debian / Ubuntu：**

```bash
sudo apt-get update
sudo apt-get install -y supervisor
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

**CentOS / RHEL 7+：**

```bash
sudo yum install -y supervisor
sudo systemctl enable supervisord
sudo systemctl start supervisord
```

**验证安装：**

```bash
supervisorctl version
# 或
systemctl status supervisor  # Debian/Ubuntu
systemctl status supervisord # CentOS
```

### 5.2 Swoole Server 配置

创建配置文件：

```bash
sudo mkdir -p /etc/supervisor/conf.d
sudo tee /etc/supervisor/conf.d/wellcms-swoole.conf > /dev/null << 'EOF'
[program:wellcms-swoole]
command=php /www/wwwroot/wellcms/bin/well-server start
directory=/www/wwwroot/wellcms
user=www
numprocs=1
autostart=true
autorestart=true
startsecs=5
startretries=3
stopsig=TERM
stopwaitsecs=30
killasgroup=true
stopasgroup=true
environment=DEBUG="0",PHP_MEMORY_LIMIT="512M"
stderr_logfile=/var/log/wellcms-swoole.err.log
stdout_logfile=/var/log/wellcms-swoole.out.log
stderr_logfile_maxbytes=50MB
stderr_logfile_backups=5
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
EOF
```

> **注意**：配置中的 `command` 不要加 `-d` 参数。Supervisor 本身负责守护，Swoole 的 `daemonize` 应保持 `false`，否则 Supervisor 会丢失进程追踪。

### 5.3 加载配置并启动

```bash
# 通知 Supervisor 重新读取配置
sudo supervisorctl reread

# 应用配置更新
sudo supervisorctl update

# 启动 Swoole 进程
sudo supervisorctl start wellcms-swoole

# 查看状态
sudo supervisorctl status wellcms-swoole

# 查看实时日志
sudo tail -f /var/log/wellcms-swoole.out.log
```

常用运维命令：

```bash
# 停止
sudo supervisorctl stop wellcms-swoole

# 重启
sudo supervisorctl restart wellcms-swoole

# 查看所有受管进程
sudo supervisorctl status
```

### 5.4 同时守护 Scheduler（必读）

Swoole Server 与 Scheduler 是独立进程，必须同时守护。参考 `bin/SCHEDULER_DEPLOY_GUIDE.md` 创建：

```bash
sudo tee /etc/supervisor/conf.d/wellcms-scheduler.conf > /dev/null << 'EOF'
[program:wellcms-scheduler]
command=php /www/wwwroot/wellcms/bin/scheduler --max-runs=5000 --memory=128
directory=/www/wwwroot/wellcms
user=www
autostart=true
autorestart=true
startsecs=5
stopsig=TERM
stopwaitsecs=30
stderr_logfile=/var/log/wellcms-scheduler.err.log
stdout_logfile=/var/log/wellcms-scheduler.out.log
EOF

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wellcms-scheduler
```

加载后确认两者均在线：

```bash
sudo supervisorctl status
# wellcms-swoole     RUNNING
# wellcms-scheduler  RUNNING
```

### 5.5 开机自启

```bash
# Debian/Ubuntu
sudo systemctl enable supervisor
sudo systemctl start supervisor

# CentOS/RHEL
sudo systemctl enable supervisord
sudo systemctl start supervisord
```

### 5.6 替代方案：systemd 原生守护（可选）

如果你更倾向于不使用 Supervisor，可直接使用 systemd 管理 Swoole 进程：

```bash
sudo tee /etc/systemd/system/wellcms-swoole.service > /dev/null << 'EOF'
[Unit]
Description=WellCMS 3.0 Swoole Server
After=network.target redis.service

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=/www/wwwroot/wellcms
ExecStart=/usr/bin/php /www/wwwroot/wellcms/bin/well-server start
ExecStop=/usr/bin/php /www/wwwroot/wellcms/bin/well-server stop
ExecReload=/usr/bin/php /www/wwwroot/wellcms/bin/well-server reload
Restart=on-failure
RestartSec=5
Environment=DEBUG=0
Environment=PHP_MEMORY_LIMIT=512M

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable wellcms-swoole
sudo systemctl start wellcms-swoole
sudo systemctl status wellcms-swoole
```

systemd 常用运维命令：

```bash
# 查看状态
sudo systemctl status wellcms-swoole

# 停止
sudo systemctl stop wellcms-swoole

# 重启
sudo systemctl restart wellcms-swoole

# 热重载
sudo systemctl reload wellcms-swoole

# 查看实时日志
sudo journalctl -u wellcms-swoole -f
```

### 5.7 日志轮转（logrotate）

防止 Swoole 与 Supervisor 日志长期运行撑爆磁盘，建议配置 `logrotate`：

```bash
sudo tee /etc/logrotate.d/wellcms > /dev/null << 'EOF'
/var/log/wellcms-swoole*.log
/var/log/wellcms-scheduler*.log
/var/log/wellcms-health*.log
/www/wwwroot/wellcms/storage/logs/*.log
{
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www www
    sharedscripts
    postrotate
        # 如使用 systemd
        /bin/kill -USR1 $(systemctl show --property=MainPID --value rsyslog.service 2>/dev/null) 2>/dev/null || true
    endscript
}
EOF

# 测试 logrotate 配置
sudo logrotate -d /etc/logrotate.d/wellcms

# 强制执行一次轮转
sudo logrotate -f /etc/logrotate.d/wellcms
```

---

## 6. Nginx 反向代理与静态资源

Swoole 专注处理动态请求，静态资源（JS/CSS/图片/上传文件）应交由 Nginx 直接响应，并在前端处理 SSL 终止、Gzip 压缩与访问控制。

### 6.1 安装 Nginx

**Debian / Ubuntu：**

```bash
sudo apt-get update
sudo apt-get install -y nginx
sudo systemctl enable nginx
sudo systemctl start nginx
```

**CentOS / RHEL 7+：**

```bash
sudo yum install -y epel-release
sudo yum install -y nginx
sudo systemctl enable nginx
sudo systemctl start nginx
```

**验证安装：**

```bash
nginx -v
systemctl status nginx
```

### 6.2 创建站点配置文件

```bash
# 创建 Nginx 站点配置
sudo tee /etc/nginx/sites-available/wellcms > /dev/null << 'EOF'
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/nginx/ssl/your-domain.com.crt;
    ssl_certificate_key /etc/nginx/ssl/your-domain.com.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    root /www/wwwroot/wellcms/public;
    index index.php index.html;

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript application/rss+xml application/atom+xml image/svg+xml;

    location ^~ /plugins/ {
        alias /www/wwwroot/wellcms/plugins/;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ^~ /themes/ {
        alias /www/wwwroot/wellcms/themes/;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ^~ /views/ {
        alias /www/wwwroot/wellcms/app/views/;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ^~ /upload/ {
        alias /www/wwwroot/wellcms/storage/upload/;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, immutable";

        location ~* \.(php|php5|php7|phtml|pl|py|jsp|asp|sh|cgi)$ {
            deny all;
        }
    }

    location / {
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Host $http_host;

        proxy_connect_timeout 30s;
        proxy_send_timeout 30s;
        proxy_read_timeout 60s;
        proxy_buffering on;
        proxy_buffer_size 4k;
        proxy_buffers 8 4k;

        # WebSocket 支持（如需启用请取消注释）
        # proxy_set_header Upgrade $http_upgrade;
        # proxy_set_header Connection "upgrade";

        proxy_pass http://127.0.0.1:9501;
    }

    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~* \.(git|svn|htaccess|env|log|sql)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
}
EOF

# 启用站点（Debian/Ubuntu）
sudo mkdir -p /etc/nginx/sites-enabled
sudo ln -sf /etc/nginx/sites-available/wellcms /etc/nginx/sites-enabled/wellcms
sudo rm -f /etc/nginx/sites-enabled/default

# CentOS/RHEL 请将配置文件放入 /etc/nginx/conf.d/wellcms.conf
# sudo mv /etc/nginx/sites-available/wellcms /etc/nginx/conf.d/wellcms.conf

# 创建 SSL 目录
sudo mkdir -p /etc/nginx/ssl
```

### 6.3 配置要点说明

| 配置项 | 作用 |
|--------|------|
| `expires 30d` + `Cache-Control` | 静态资源长期缓存，减少回源 |
| `upload` 目录禁止脚本执行 | 防止用户上传恶意脚本被解析执行 |
| `proxy_buffering on` | Nginx 缓冲后端响应，提高吞吐 |
| `proxy_read_timeout 60s` | 长耗时接口（如导出、批量处理）需适当放大 |
| WebSocket headers 注释块 | 预留配置，启用 Swoole WebSocket 时取消注释 |

### 6.4 SSL 证书配置（Let's Encrypt）

**使用 Certbot 自动申请并配置（推荐）：**

```bash
# 安装 Certbot
sudo apt-get install -y certbot python3-certbot-nginx   # Debian/Ubuntu
sudo yum install -y certbot python3-certbot-nginx       # CentOS/RHEL

# 申请证书（会自动修改 Nginx 配置并重启）
sudo certbot --nginx -d your-domain.com -d www.your-domain.com

# 测试自动续期
sudo certbot renew --dry-run

# 查看证书有效期
sudo certbot certificates
```

**手动配置自有证书：**

将证书文件上传至 `/etc/nginx/ssl/` 后，确保 Nginx 配置中的 `ssl_certificate` 与 `ssl_certificate_key` 路径正确即可。

### 6.5 验证 Nginx 配置并重载

```bash
# 测试配置语法
sudo nginx -t

# 重载配置（零停机）
sudo systemctl reload nginx

# 查看 Nginx 实时错误日志
sudo tail -f /var/log/nginx/error.log

# 查看访问日志中的 502/504
sudo awk '$9 ~ /502|504/' /var/log/nginx/access.log | tail -20
```

---

## 7. 代码更新与热重载机制

Swoole 为常驻内存架构，代码更新不像 PHP-FPM 那样"即改即生效"。必须理解以下机制：

### 7.1 Reload（热重载）vs Restart（冷重启）

| 操作 | 命令 | 影响范围 | 适用场景 |
|------|------|----------|----------|
| **Reload** | `php bin/well-server reload` | 仅重启 Worker 进程；Master 与 Manager 不受影响；连接不中断 | 修改 Controller、Model、Service 等业务代码后 |
| **Restart** | `php bin/well-server restart` | 完全停止并重新启动 Master 进程；所有连接断开 | 修改 `SwooleServer.php`、`bin/well-server`、核心配置后 |

### 7.2 系统自动化感知（插件/主题/缓存）

WellCMS 3.0 已在后台操作中内置了自动 Reload 触发：

- 安装/卸载插件
- 启用/禁用插件
- 切换主题
- 清空系统缓存

上述操作完成后，系统会自动调用 `\Framework\Utils\Runtime::reload()` 向 Swoole Master 进程发送 **SIGUSR1** 信号，所有 Worker 会平滑重启并重新加载最新逻辑。

### 7.3 普通业务代码更新流程

如果你通过 `git pull`、SFTP 或 CI/CD 更新了普通业务代码（Controller、Model、View、配置等），**必须手动触发 Reload**：

```bash
# 方式一：通过命令行
php bin/well-server reload

# 方式二：通过 PHP-FPM 或 CLI 调用 Runtime API
php -r "require 'app/Core/Compile.php'; require 'app/Core/Autoload.php'; \Framework\Utils\Runtime::reload();"
```

### 7.4 无法通过 Reload 生效的变更

以下变更 **必须执行 Restart**：

- 修改 `src/Http/SwooleServer.php` 或 `bin/well-server`
- 修改 Swoole 配置项（`worker_num`、`max_request` 等）
- 修改 `composer.json` 并重新加载自动加载器
- 修改 `.env` 或核心启动常量（如 `DEBUG`）

### 7.5 文件变更自动热重载（开发环境）

开发环境可借助 `inotify` 或 `fswatch` 实现文件变更自动 Reload：

```bash
# 安装 inotify-tools
apt-get install inotify-tools

# 监控 app/、config/、src/ 目录并自动 reload
while inotifywait -r -e modify,create,delete app/ config/ src/ themes/ plugins/; do
    php /www/wwwroot/wellcms/bin/well-server reload
    echo "[$(date)] Reload triggered"
done
```

> ⚠️ 生产环境不建议使用自动文件监控，容易在高频部署时触发 Reload 风暴。

---

## 8. 故障排查与日志分析

### 8.1 服务无法启动

**现象：** `php bin/well-server start` 后立即退出或无响应。

排查步骤：

```bash
# 1. 检查 Swoole 扩展是否存在
php --ri swoole

# 2. 检查端口占用
ss -tlnp | grep 9501
lsof -i:9501

# 3. 检查 PID 文件是否残留（Stale PID）
cat storage/tmp/wellcms.pid
# 如果对应进程已不存在，手动删除：
rm -f storage/tmp/wellcms.pid

# 4. 前台启动查看详细错误
php bin/well-server start
# 或临时将 DEBUG 设为 2 查看堆栈
```

**Swoole 6.0 / PHP 8.4 专项排查：**

若遇到 `eventLoop has already been created, unable to start Swoole\Http\Server`，说明在 `Swoole\Http\Server->start()` 之前已有代码创建了事件循环（Event Loop）。常见原因：

1. **构造函数中提前调用了 `Swoole\Timer::tick()`** — `CoroutineConnectionPool` 旧版本会在构造函数中启动健康检查定时器。请确保使用最新代码（已修复为惰性启动）。
2. **构造函数中提前调用了 `Swoole\Event::defer()`** — 部分服务（如 `MarketClient`）旧版本会在构造函数中注册 `Event::defer`。请确保使用最新代码（已改为仅在协程内使用 `Coroutine::defer()`）。
3. **FPM 进程中加载了 swoole.so** — 如果 PHP-FPM 也加载了 Swoole 扩展，某些全局初始化代码可能提前创建了事件循环。建议仅在 CLI 模式启用 Swoole。

若遇到 `Undefined constant Swoole\Constant::VERSION`，说明代码中使用了 Swoole 5.x 的常量，而当前安装的是 Swoole 6.0+。Swoole 6.0 已移除 `Swoole\Constant::VERSION`，请改用 `SWOOLE_VERSION` 或 `swoole_version()`。

### 8.2 502 Bad Gateway / 504 Gateway Timeout

| 现象 | 可能原因 | 解决方案 |
|------|----------|----------|
| 502 | Swoole 进程已崩溃或停止 | `supervisorctl status wellcms-swoole`；检查 `storage/logs/swoole.log` |
| 502 | Nginx 无法连接到 127.0.0.1:9501 | 检查 Swoole 监听地址，`bin/well-server` 默认 `0.0.0.0:9501`，Nginx 应能访问 |
| 504 | 接口执行超时 | 增大 Nginx `proxy_read_timeout` 与 Swoole `max_execution_time`；检查慢 SQL |
| 504 | Worker 被占满 | 检查 `worker_num` 是否过低；检查是否有阻塞 IO 未协程化 |

### 8.3 内存泄漏排查

Swoole 常驻内存下，内存泄漏会随时间累积。排查方法：

```bash
# 查看 Worker 进程内存占用
ps aux | grep "well-server: worker" | awk '{print $2, $6/1024 "MB"}'

# 持续监控
watch -n 5 'ps aux | grep "well-server" | grep -v grep'
```

应对措施：

1. **降低 `max_request`**：让 Worker 在处理一定请求后自动重启，作为兜底手段。
2. **检查全局/静态变量**：避免在 Worker 进程中累积无限增长的全局数组或静态缓存。
3. **使用 Swoole Tracker 或 Valgrind**：深度分析泄漏点。

### 8.4 日志位置说明

| 日志文件 | 路径 | 说明 |
|----------|------|------|
| Swoole 运行日志 | `storage/logs/swoole.log` | Swoole 底层错误、Worker 异常退出、信号记录 |
| 应用错误日志 | `storage/logs/` 下按日期切分 | WellCMS 业务异常、SQL 错误 |
| Supervisor 标准输出 | `/var/log/wellcms-swoole.out.log` | 前台输出（如 Banner、启动信息） |
| Supervisor 标准错误 | `/var/log/wellcms-swoole.err.log` | PHP Fatal Error、未捕获异常 |
| Nginx 错误日志 | `/var/log/nginx/error.log` | 502/504、反向代理失败 |

实时查看示例：

```bash
# Swoole 实时日志
tail -f storage/logs/swoole.log

# Supervisor 错误日志
tail -f /var/log/wellcms-swoole.err.log

# 按分钟查看应用错误
ls -lt storage/logs/ | head -5
```

### 8.5 Worker 异常退出分析

若 `swoole.log` 中出现 `worker abnormal exit`：

```bash
# 查看系统日志中该时间点的 OOM Killer 记录
dmesg | grep -i kill

# 查看 PHP 内存限制是否过低
grep memory_limit /etc/php/8.1/cli/php.ini

# 检查是否触发了 max_request 正常重启（Swoole 日志会标记为 "worker exit"）
grep -E "WorkerStop|WorkerError" storage/logs/swoole.log
```

### 8.6 一键健康检查脚本

以下脚本可用于快速巡检 Swoole 进程、端口、内存、连接数等核心指标：

```bash
#!/bin/bash
# wellcms-health-check.sh — 快速巡检 WellCMS Swoole 状态

APP_PATH="/www/wwwroot/wellcms"
PID_FILE="${APP_PATH}/storage/tmp/wellcms.pid"
LOG_FILE="${APP_PATH}/storage/logs/swoole.log"
PORT=9501

echo "========== WellCMS Swoole 健康检查 =========="
echo "检查时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# 1. PID 文件检查
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "[OK] PID 文件存在且进程存活 (PID: $PID)"
    else
        echo "[WARN] PID 文件存在但进程已消失 (Stale PID: $PID)，建议删除: rm -f $PID_FILE"
    fi
else
    echo "[FAIL] PID 文件不存在，服务可能未启动"
fi

# 2. 端口监听检查
if ss -tlnp | grep -q ":$PORT"; then
    echo "[OK] 端口 $PORT 正在监听"
else
    echo "[FAIL] 端口 $PORT 未监听"
fi

# 3. Worker 进程检查
WORKER_COUNT=$(ps aux | grep "well-server: worker" | grep -v grep | wc -l)
if [ "$WORKER_COUNT" -gt 0 ]; then
    echo "[OK] Worker 进程数量: $WORKER_COUNT"
else
    echo "[FAIL] 未发现 Worker 进程"
fi

# 4. 内存占用检查
echo "[INFO] 各进程内存占用:"
ps aux | grep "well-server" | grep -v grep | awk '{printf "  PID: %s  RSS: %.1f MB  CMD: %s\n", $2, $6/1024, $11}'

# 5. 最近错误检查
if [ -f "$LOG_FILE" ]; then
    ERROR_COUNT=$(tail -n 100 "$LOG_FILE" | grep -ci "error\|fatal\|warning")
    echo "[INFO] 最近 100 行日志中异常关键字数量: $ERROR_COUNT"
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo "[INFO] 最新异常日志:"
        tail -n 100 "$LOG_FILE" | grep -i "error\|fatal\|warning" | tail -n 3 | sed 's/^/  /'
    fi
else
    echo "[WARN] Swoole 日志文件不存在: $LOG_FILE"
fi

echo ""
echo "========== 检查完成 =========="
```

保存后赋予执行权限并运行：

```bash
chmod +x wellcms-health-check.sh
./wellcms-health-check.sh
```

---

## 9. 安全加固建议

### 9.1 运行用户隔离

严禁使用 `root` 运行 Swoole 或 Scheduler：

```bash
# 创建专用用户
useradd -s /sbin/nologin -M www

# 确保 Supervisor 配置中 user=www
# 确保目录属主为 www
chown -R www:www /www/wwwroot/wellcms
```

### 9.2 端口与防火墙

**使用 UFW（Debian/Ubuntu）：**

```bash
# 默认拒绝入站，允许出站
sudo ufw default deny incoming
sudo ufw default allow outgoing

# 开放 Nginx 端口到公网
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# 开放 SSH（防止误操作锁死）
sudo ufw allow 22/tcp

# Swoole 端口仅监听 127.0.0.1，不暴露公网
# 如需外部访问，通过 Nginx 反向代理，勿直接开放 9501
sudo ufw deny 9501/tcp

# 启用防火墙
sudo ufw enable
sudo ufw status verbose
```

**使用 firewalld（CentOS/RHEL）：**

```bash
# 开放 HTTP/HTTPS
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https

# 开放 SSH（如未开放）
sudo firewall-cmd --permanent --add-service=ssh

# 明确拒绝 9501（可选，默认不开放的区域已拒绝）
sudo firewall-cmd --permanent --add-rich-rule='rule family="ipv4" port protocol="tcp" port="9501" reject'

# 重载生效
sudo firewall-cmd --reload
sudo firewall-cmd --list-all
```

若需通过内网其他机器直连 Swoole，可绑定内网 IP：

```bash
php bin/well-server start -d -h 10.0.0.5 -p 9501
```

### 9.3 上传目录安全

已在第 6 章 Nginx 配置中体现：对 `upload/` 目录禁止解析任何脚本文件。此外建议：

```bash
# 去除上传目录脚本执行权限（即使文件被上传也无法执行）
chmod -R 755 storage/upload
find storage/upload -type f -exec chmod 644 {} \;
```

---

## 10. 附录：信号与进程参考

### 10.1 进程结构

```
well-server: master (PID 记录于 storage/tmp/wellcms.pid)
├── well-server: manager
│   ├── well-server: worker #0
│   ├── well-server: worker #1
│   ├── well-server: worker #2
│   └── ...
```

- **Master**：管理连接 accept，处理信号。
- **Manager**：管理 Worker 生命周期（fork/reload）。
- **Worker**：处理 HTTP 请求与业务逻辑。

### 10.2 信号对照表

| 信号 | 值 | 发送对象 | 作用 |
|------|----|----------|------|
| `SIGTERM` | 15 | Master | 优雅关闭整个服务 |
| `SIGUSR1` | 10 | Master | 重载所有 Worker（Reload） |
| `SIGUSR2` | 12 | Master | 重载所有 Task Worker 与 Worker |

手动发送信号示例：

```bash
# 优雅停止
kill -15 $(cat storage/tmp/wellcms.pid)

# 热重载
kill -USR1 $(cat storage/tmp/wellcms.pid)
```

### 10.3 快速检查清单（部署前逐项核对）

- [ ] PHP 版本 >= 7.2，建议 8.1+（8.4+ 需配合 Swoole 6.0+）
- [ ] Swoole 扩展已加载且 openssl 开启（6.0+ 需注意 API 差异）
- [ ] Swoole 扩展**仅在 CLI 模式加载**，FPM 中未加载（防止 async-io 报错与事件循环冲突）
- [ ] `storage/tmp` 与 `storage/logs` 对运行用户可写
- [ ] `bin/well-server` 中 `DEBUG` 已设为 `0`
- [ ] Supervisor 配置已加载且 `autostart=true`
- [ ] Scheduler 也在 Supervisor 中正常运行
- [ ] Nginx 已配置静态资源 location 与上传目录脚本禁止
- [ ] 防火墙已关闭 9501 公网访问（如通过 Nginx 反向代理）
- [ ] SSL 证书已配置且 TLSv1.2+ 强制开启
- [ ] 已执行过一次 `php bin/well-server reload` 或访问首页验证连通性

---

**WellCMS 研发团队 敬上**
*最后更新时间：2026-04-19*
