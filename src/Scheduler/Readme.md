# WellCMS 任务调度系统 (Scheduler) 开发与部署指南

本调度系统是专为守护进程（Daemon）模式设计的异步任务处理框架，完美支持 Supervisor 托管，适用于高并发、对实时性有要求的后台任务。

---

## 一、 Job 任务类开发规范

所有的任务逻辑必须封装在类中。

### 1. 基础结构
*   **命名空间**：建议放在 `app/Jobs/` 或插件对应的 `Jobs/` 目录。
*   **接口实现**：必须实现 `Framework\Scheduler\Interfaces\JobInterface`（标记接口）。
*   **构造函数**：支持 **依赖注入 (DI)**。容器会自动解析构造函数中的类型提示并注入对应的服务。

### 2. 代码示例
创建一个简单的邮件发送任务 `app/Jobs/SendMailJob.php`:

* **最佳实践：构造函数注入 (Constructor Injection)**
既然容器负责实例化 Job 类，你可以在 Job 类的 `__construct` 中声明你需要的依赖，容器会自动注入它们。

```php
<?php
declare(strict_types=1);

namespace App\Jobs;

use Framework\Scheduler\Interfaces\JobInterface;
use App\Services\EmailService; // 假设的服务

class SendMailJob implements JobInterface
{
    private $emailService;

    // 容器会自动注入 EmailService 实例
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * 任务执行入口
     * @param string $to 收件人
     * @param string $subject 主题
     * @param string $_task_id 系统自动注入的任务ID (可选)
     */
    public function handle(string $to, string $subject, ?string $_task_id = null): bool
    {
        // 调用业务逻辑
        $result = $this->emailService->send($to, $subject);
        
        // 返回 true 表示执行成功，返回 false 会触发系统的重试机制
        return $result;
    }
}
```

### 2. 定时任务 handle 方法传参和接收参数的正确方式是什么？

**正确方式：** `handle($fileId, $filePath)` (接收独立参数)

**错误方式：** `handle($args)` (接收一个数组)

* **原理分析**：
在 `src/Scheduler/TaskExecutor.php` 的 `invokeCallback` 方法中，第 159 行代码如下：

```php
return $obj->{$methodName}(...$task->args);

```

这里使用了 PHP 的 **参数解包 (Splat Operator `...`)**。这意味着 `$task->args` 数组中的每个元素会被展开成独立的参数传递给方法。

* **注意事项 (PHP 版本差异)**：
* **PHP 8.0+**：支持**命名参数**。如果你传入的 `args` 是 `['fileId' => 1]`，PHP 会寻找名为 `$fileId` 的参数，参数顺序不重要，名字必须对应。
* **PHP < 8.0**：参数是**按位置**传递的。`args` 数组的第一个元素传给 `handle` 的第一个参数，以此类推。建议保持数组顺序与方法参数顺序一致。

* **关于自动注入的参数**：
`TaskManage` 会自动往 `args` 里塞入 `_task_id` 和 `_session_id`。如果你的 `handle` 方法没有定义对应的参数，在 PHP 8+ 中可能会报错（Unknown named parameter）。


**建议的 handle 写法**（为了兼容性，可以显式接收系统参数）：

```php
public function handle(string $to, string $subject, ?string $_task_id = null, ?string $_session_id = null): bool
```


---

## 二、 任务投递 (Task Pushing)

使用 `Framework\Scheduler\TaskManage` 类向队列推送任务。

### 1. 获取实例
```php
// 从容器获取（推荐）
$taskManage = $container->get(\Framework\Scheduler\TaskManage::class);
```

### 2. 推送参数说明
调用 `createTask(array $payload)`，参数如下：

| 参数名 | 类型 | 必填 | 说明 | 默认值 |
| :--- | :--- | :--- | :--- | :--- |
| **className** | string | 是 | 执行类的全名 (如 `App\Jobs\SendMailJob::class`) | - |
| **methodName** | string | 否 | 执行的方法名 | `handle` |
| **args** | array | 否 | 传递给方法的参数，关联数组（Key 对应参数名） | `[]` |
| **priority** | int | 否 | 优先级 (0-10)，**越小越优先** | `5` |
| **scheduledAt** | int\|string | 否 | 执行时间。支持时间戳或 `2025-01-01 12:00:00` 字符串 | `time()` |
| **dedupeKey** | string | 否 | 幂等去重 Key。设置后，缓存期内相同 Key 的任务不会重复投递 | - |
| **timeout** | int | 否 | 任务执行超时时间（秒）。0 表示不限制 | `0` |
| **maxRetries** | int | 否 | 任务失败后的最大重试次数 | `3` |
| **retryDelay** | int | 否 | 失败重试的延迟间隔（秒） | `1` |
| **callbackUrl** | string | 否 | 任务完成后的 Webhook 回调通知地址（仅支持外网） | - |
| **callbackMethod** | string | 否 | 回调请求方式 (GET/POST) | `POST` |
| **id** | string | 否 | 自定义任务 ID (UUID)，不填系统会自动生成 | - |

### 3. 操作示例

```php
$taskManage->createTask([
    'className'      => \App\Jobs\SendMailJob::class,
    'methodName'     => 'handle',
    'args'           => [
        'to'      => 'user@example.com',
        'subject' => '欢迎注册'
    ],
    'priority'       => 3,
    'scheduledAt'    => time() + 30,         // 30秒后执行
    'dedupeKey'      => 'welcome_mail_1001', // 24小时内防止重复投递
    'timeout'        => 60,                  // 60秒超时强制终止
    'maxRetries'     => 5,                   // 允许重试 5 次
    'retryDelay'     => 10,                  // 每次重试间隔 10 秒
    'callbackUrl'    => 'https://api.yourdomain.com/v1/task/callback',
    'callbackMethod' => 'POST'
]);
```


**总结：**

1. **args**: 传关联数组
```
[
    'to'      => 'user@example.com',
    'subject' => '欢迎注册'
]
```
1. **handle**: 接收独立参数 `handle($to, $subject)`，利用 PHP 8 命名参数特性。
3. **服务调用**: 使用**构造函数注入**，这是最标准、现代化且易于测试的方法。不要在 `handle` 内部去 `new` 服务或手动 `get` 容器。


---

## 三、 Supervisor 部署教程

由于调度器是常驻进程，必须使用进程监控工具（如 Supervisor）来管理。

### 1. 安装 Supervisor (CentOS/Ubuntu)
```bash
# Ubuntu
sudo apt install supervisor
# CentOS
sudo yum install supervisor
```

### 2. 创建配置文件
在 `/etc/supervisor/conf.d/` 目录下创建 `wellcms_scheduler.conf`:

```ini
[program:wellcms-worker]
# 网站代码根目录
directory=/home/wwwroot/wellcms
# 运行脚本命令
# --max-runs: 处理 5000 个任务后重启进程（释放内存）
# --memory: 占用内存超过 128MB 时重启进程
command=/usr/bin/php bin/scheduler --max-runs=5000 --memory=128
# 自动启动 & 崩溃自动重启
autostart=true
autorestart=true
# 运行用户
user=www
# 并发进程数，可以根据服务器性能开启多个
numprocs=1
# 日志配置
redirect_stderr=true
stdout_logfile=/home/wwwroot/wellcms/storage/logs/scheduler_worker.log
```

### 3. 启动任务
```bash
# 重新加载配置
sudo supervisorctl update
# 查看状态
sudo supervisorctl status
# 启动
sudo supervisorctl start wellcms-worker:*
```

---

## 四、 核心审计与注意事项

1.  **超时保护机制**：调度器使用了 `pcntl_fork` 子进程模式（如果系统支持）。主进程监控子进程执行，若超过 `timeout` 设置，主进程会强制 `kill` 子进程并自动续期 Redis 锁，防止死锁。
2.  **原子锁**：任务弹出时会通过 Redis 锁确保“单任务单执行”，即便开启多个 `numprocs` 进程也不会产生并发冲突。
3.  **日志监控**：系统日志保存在 `storage/logs/YYYYMM/scheduler.log`。可以通过后台查看任务执行状态。
4.  **幂等性**：建议在 Job 逻辑中自行处理幂等（如检查数据库状态），或通过 `dedupeKey` 在投递端防御。
