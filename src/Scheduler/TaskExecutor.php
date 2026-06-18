<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * TaskExecutor：从队列中取任务、加锁、执行、重试、回调
 * $cacheManager = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
 * $logPath = $container->get('loggerConfig')['file']['path'];
 */
class TaskExecutor
{
    /** @var \Framework\Cache\Drivers\RedisCache */
    protected $redis;
    /** @var \Framework\Scheduler\Interfaces\TaskQueueInterface */
    protected $queue;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var string */
    protected $lockPrefix = 'scheduler:lock:task:';
    /** @var bool 标记是否收到停止信号 */
    protected $shouldQuit = false;
    /** @var \Framework\Scheduler\Logger */
    protected $logger;

    public function __construct(\Framework\Core\Container $container, \Framework\Cache\Drivers\RedisCache $redis, \Framework\Scheduler\Interfaces\TaskQueueInterface $queue, \Framework\Scheduler\Logger $logger)
    {
        $this->container = $container;
        $this->redis = $redis;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    /**
     * 启动守护进程循环
     * @param int $maxRuns 最大处理任务数 (0不限制)
     * @param int $memoryLimit 最大内存限制(MB) (0不限制)
     */
    public function runLoop(int $maxRuns = 1000, int $memoryLimit = 128): void
    {
        // 注册信号处理器
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        // 启动时清理历史孤儿锁（应对 kill -9 / OOM 场景）
        $this->cleanupOrphanLocks();

        $processedCount = 0; // 仅统计实际处理的任务
        $this->log("TaskExecutor started. Target: Process {$maxRuns} tasks or hit {$memoryLimit}MB RAM.");

        while (!$this->shouldQuit) {
            try {
                // 1. 检查资源限制 (自我终结机制)
                if ($this->checkResourceLimits($processedCount, $maxRuns, $memoryLimit)) {
                    break;
                }

                // 更新心跳，标记调度器活跃
                $this->redis->set('scheduler:stats:last_execution', time());

                // 2. 获取任务
                $task = $this->queue->pop();

                if (!$task) {
                    // [Fix 2] 空转不计入 $processedCount，避免频繁重启
                    $this->sleepUs(500000); // 0.5s
                    continue;
                }

                // 3. 执行任务
                $this->executeTask($task);
                $processedCount++; // [Fix 2] 只有处理了任务才计数

            } catch (\Throwable $e) {
                $this->log("Loop exception: " . $e->getMessage());
                $this->sleep(1);
            }
        }

        $this->log("TaskExecutor shutting down gracefully.");
    }

    public function handleSignal($signal): void{
        $this->log("Received signal {$signal}, stopping loop...");
        $this->shouldQuit = true;
    }

    protected function checkResourceLimits(int $runs, int $maxRuns, int $memoryLimit): bool
    {
        // 检查运行次数
        if ($maxRuns > 0 && $runs >= $maxRuns) {
            $this->log("Max processed tasks ({$maxRuns}) reached. Exiting for restart.");
            return true;
        }

        // 检查内存使用
        if ($memoryLimit > 0) {
            $usage = memory_get_usage(true) / 1024 / 1024;
            if ($usage >= $memoryLimit) {
                // 增加 Peak Memory 打印，方便排查内存泄漏
                $peak = memory_get_peak_usage(true) / 1024 / 1024;
                $this->log("Memory limit hit. Usage: " . round($usage, 2) . "MB, Peak: " . round($peak, 2) . "MB. Exiting.");
                return true;
            }
        }

        return false;
    }

    protected function executeTask(\Framework\Scheduler\Task $task): void
    {
        // 获取锁（假定 $this->redis->lock 返回一个锁对象或 false）
        $lockKey = $this->lockPrefix . $task->id;
        $ttl = max(60, $task->timeout + 10);

        // 原子锁
        $lockToken = $this->redis->lock($lockKey, $ttl);
        if (!$lockToken) {
            $this->log("Task {$task->id} is already executing or lock acquisition failed, short delay and requeue");
            // 将任务短暂延后，避免立即抢占造成 busy loop
            // 使用 reschedule 而非 requeue：不递增 retryCount，任务未被实际执行
            $task->scheduledAt = time() + random_int(1, 3);
            $this->queue->reschedule($task);
            // 随机退避
            $this->sleepUs(random_int(100000, 500000));
            return;
        }

        // 标记任务进入运行状态（用于精确统计运行中任务）
        $this->redis->zAdd('scheduler:running:zset', [time() => $task->id]);
        // 记录 PID 与 token，用于孤儿锁清理
        $this->redis->hSet('scheduler:executor:pids', $task->id, $lockToken);

        $startTime = microtime(true);
        $success = false;
        $errorMsg = '';

        $this->log("Task {$task->id} starts executing, P={$task->priority}, R={$task->retryCount}");

        try {
            // 判断是否需要子进程超时保护
            $useTimeout = $task->timeout > 0 && php_sapi_name() === 'cli' && function_exists('pcntl_fork');
            if ($useTimeout) {
                $exitCode = $this->runWithTimeout($task, $lockKey, $lockToken);
                if ($exitCode === 0) {
                    $success = true;
                } else {
                    $errorMsg = $exitCode === 124 ? 'Task timeout' : "Process exit code: {$exitCode}";
                    $task->error = $errorMsg;
                }
            } else {
                $this->invokeCallback($task);
                $success = true;
            }
        } catch (\Throwable $ex) {
            $errorMsg = $ex->getMessage();
            $task->error = $errorMsg;
            $success = false;
        }

        $elapsed = round(microtime(true) - $startTime, 3);

        // 无论成功失败，都必须触发回调，且参数必须正确
        if ($success) {
            $this->log("Task {$task->id} succeeded, {$elapsed}s");
            $task->status = 'success';
            $task->updatedAt = time();

            $this->queue->moveToSuccessQueue($task);
            // 记录执行时间到Hash，用于统计平均执行时间
            $this->redis->hSet('scheduler:execution_times', $task->id, (string)$elapsed);
            // 设置TTL避免无限增长
            $this->redis->expire('scheduler:execution_times', 30 * 24 * 3600);

            // 成功：传递 true
            $this->triggerCallback($task, true, $elapsed, '');
        } else {
            $this->log("Task {$task->id} failed, {$elapsed}s, {$errorMsg}");
            // 失败：传递 false
            $this->triggerCallback($task, false, $elapsed, $errorMsg);

            // 失败重试逻辑
            if ($task->retryCount < $task->maxRetries) {
                $task->status = 'retrying';
                // 指数退避
                $base = max(1, $task->retryDelay ?: 1);
                $cap  = 3600;
                $exp  = min($cap, $base * (1 << min($task->retryCount, 10)));
                $jitter = random_int(0, (int)($exp * 0.25));
                $task->scheduledAt = time() + $exp + $jitter;
                $this->queue->requeue($task);
            } else {
                $task->status = 'failed';
                $this->log("Task {$task->id} has reached the maximum number of retries and is moved to the failed queue.");
                $this->queue->moveToFailedQueue($task);
            }
        }

        // 原子释放锁
        $this->redis->unlock($lockKey, $lockToken);

        // 清理运行状态标记
        $this->redis->zRem('scheduler:running:zset', $task->id);
        $this->redis->hDel('scheduler:executor:pids', $task->id);

        // 仅在任务最终完成（成功 或 彻底失败进入死信队列）时释放幂等锁
        if (!empty($task->dedupeKey)) {
            if ($success || $task->retryCount >= $task->maxRetries) {
                $this->redis->del('scheduler:dedupe:' . $task->dedupeKey);
            }
        }
    }

    /**
     * 子进程执行并支持超时，带锁续期
     */
    protected function runWithTimeout(\Framework\Scheduler\Task $task, string $lockKey, string $lockToken): int
    {
        // 防御：如果 pcntl 不可用则降级到同步执行
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            // 降级直跑（无超时保护）
            $this->invokeCallback($task);
            return 0;
        }

        $pid = pcntl_fork();
        // fork 失败直接抛出异常，严禁降级为同步执行
        if ($pid == -1) {
            throw new \RuntimeException('Could not fork process - System resource limit reached');
        } elseif ($pid === 0) {
            // 子进程
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);

            try {
                // 子进程必须重建 Redis 连接，避免继承父进程 Socket FD 导致数据错乱
                $this->redis->reconnect();
                $this->invokeCallback($task);
                exit(0);
            } catch (\Throwable $e) {
                // 写入 STDERR，便于 Supervisor 捕获日志
                fwrite(STDERR, "Child Process Exception: " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
        }

        // 父进程：监控子进程 & 续期
        $timeout = max(0, $task->timeout ?: 3600);
        $start = microtime(true);
        $renewStep = 5; // 每5秒续期一次
        $nextRenew = microtime(true) + $renewStep;
        $status = 0;

        while (true) {
            $pidWait = pcntl_waitpid($pid, $status, WNOHANG);
            if ($pidWait === -1) {
                if (pcntl_get_last_error() === PCNTL_EINTR) {
                    continue;
                }
                // 子进程不存在或出错
                return 1; // Error
            } elseif ($pidWait > 0) {
                // 子进程结束
                return pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
            }

            // 锁续期逻辑（原子验证 token）
            if (microtime(true) >= $nextRenew) {
                $renewed = $this->redis->renewLock(
                    $lockKey,
                    $lockToken,
                    (int)max(60, $task->timeout + 10)
                );
                if (!$renewed) {
                    $this->log("Task {$task->id} lock renewal failed (token mismatch or lock lost).");
                    // 继续监控子进程，不中断，因为锁丢失不等于任务失败
                }
                $nextRenew = microtime(true) + $renewStep;
            }

            // 检查超时
            if ($timeout > 0 && (microtime(true) - $start) >= $timeout) {
                $this->log("Task {$task->id} timed out. Terminating PID {$pid}...");

                // 发送 SIGTERM 请求子进程退出
                @posix_kill($pid, SIGTERM);
                $graceDeadline = microtime(true) + 2.0; // 2s 宽限
                while (microtime(true) < $graceDeadline) {
                    $r = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($r == -1 || $r > 0) {
                        return pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
                    }
                    $this->sleepUs(100000); // 100ms
                }
                // 强杀
                @posix_kill($pid, SIGKILL);
                while (true) {
                    $r = pcntl_waitpid($pid, $status);
                    if ($r > 0) {
                        break;
                    }
                    if ($r === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                        break;
                    }
                }
                return 124;
            }

            $this->sleepUs(50000); // 50ms
        }

        //return pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
    }

    // Task 的 $className、$methodName 分别指定了类和方法，这个方法负责实际执行任务逻辑。
    // 安全加固的反射调用
    protected function invokeCallback(\Framework\Scheduler\Task $task)
    {
        \Framework\Scheduler\Task::validateClassExecutable($task->className);

        $className  = $task->className;
        $methodName = $task->methodName;

        try {
            // 实例化 — 委托给容器处理依赖注入，支持自动装配
            try {
                $obj = $this->container->get($className);
            } catch (\Throwable $e) {
                // 容器解析失败，降级为手动反射实例化（仅适用于无参构造的简单 Job）
                $ref = new \ReflectionClass($className);
                if ($ref->getConstructor() && $ref->getConstructor()->getNumberOfRequiredParameters() > 0) {
                    throw new \RuntimeException("Class {$className} has dependencies in __construct, but Container failed to resolve it: " . $e->getMessage());
                }
                $obj = $ref->newInstance();
            }

            // 接口检查
            if (!$obj instanceof \Framework\Scheduler\Interfaces\JobInterface) {
                throw new \RuntimeException("{$className} must implement JobInterface");
            }

            // is_callable 可能会被 __call 欺骗，所以必须用 Reflection
            if (!method_exists($obj, $methodName)) {
                throw new \RuntimeException("Method {$methodName} not found");
            }

            // 智能参数匹配 (过滤掉多余的 _task_id 等)
            $reflectionMethod = new \ReflectionMethod($obj, $methodName);
            if (!$reflectionMethod->isPublic()) {
                throw new \RuntimeException("Method {$methodName} must be public");
            }

            // 禁止调用魔术方法 (如 __destruct, __wakeup 等)
            if (strpos($methodName, '__') === 0) {
                throw new \RuntimeException("Magic methods are forbidden: {$methodName}");
            }

            $parameters = $reflectionMethod->getParameters();
            $validArgs  = [];

            // 检查是否为关联数组 (WellCMS 推荐标准)
            $isAssoc = false;
            foreach ($task->args as $k => $v) {
                if (is_string($k)) {
                    $isAssoc = true;
                    break;
                }
            }

            if (PHP_VERSION_ID >= 80000) {
                // PHP 8.0+: 支持命名参数解包。
                // 必须过滤掉方法签名中不存在的键，否则会抛出 "Unknown named parameter" 错误。
                $paramNames = [];
                foreach ($parameters as $p) {
                    $paramNames[$p->getName()] = true;
                }

                foreach ($task->args as $key => $value) {
                    if (is_int($key) || isset($paramNames[$key])) {
                        $validArgs[$key] = $value;
                    }
                }
            } else {
                // PHP 7.x: 不支持命名参数解包 (...$assoc 会报错)。
                // 必须根据方法签名构造索引数组（位置参数）。
                if (!$isAssoc) {
                    // 纯索引数组，直接透传
                    $validArgs = array_values($task->args);
                } else {
                    // 关联数组，根据反射定义的顺序重新排序
                    foreach ($parameters as $p) {
                        $name = $p->getName();
                        if (array_key_exists($name, $task->args)) {
                            $validArgs[] = $task->args[$name];
                        } elseif ($p->isDefaultValueAvailable()) {
                            $validArgs[] = $p->getDefaultValue();
                        }
                    }
                    // 注意：如果 args 中有多余的 key (如 _task_id)，在 PHP 7 下通过这种方式自然被忽略
                }
            }

            // 执行核心业务逻辑
            return $obj->{$methodName}(...$validArgs);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to execute callback {$className}::{$methodName}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * [优化点] 异步触发回调
     * 将回调操作封装为一个新的系统级任务推入队列，避免阻塞当前进程
     */
    protected function triggerCallback(\Framework\Scheduler\Task $task, bool $isSuccess, float $elapsed, string $error): void
    {
        // 如果没有设置回调地址，直接忽略
        if (empty($task->callbackUrl)) return;

        try {
            // 构造回调任务的参数
            // 我们传递原始任务数据的数组副本，避免对象引用问题
            $jobArgs = [
                'taskData'  => $task->toArray(),
                'isSuccess' => $isSuccess,
                'elapsed'   => $elapsed,
                'error'     => $error
            ];

            // 创建一个新的 Task，指向 CallbackJob
            // 优先级设为 0 (最高优先级)，确保回调尽快发出
            $callbackTask = new \Framework\Scheduler\Task(
                uniqid('sys_cb_'), // 唯一 ID
                \Framework\Scheduler\Jobs\CallbackJob::class, // 处理类
                'handle', // 处理方法
                $jobArgs, // 参数
                0, // 优先级：最高
                3, // 重试次数
                5, // 重试间隔
                30 // 超时时间
            );

            // 重要：清除新任务的 callbackUrl，防止无限循环回调
            $callbackTask->callbackUrl = '';

            // 推入队列
            $this->queue->push($callbackTask);
        } catch (\Throwable $e) {
            $this->log("Failed to queue callback: " . $e->getMessage());
        }
    }

    protected function sleep(int $sec): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (class_exists($coroClass) && call_user_func([$coroClass, 'getCid']) > 0) {
            call_user_func([$coroClass, 'sleep'], $sec);
        } else {
            sleep($sec);
        }
    }

    protected function sleepUs(int $microseconds): void
    {
        if ($microseconds <= 0) return;

        $coroClass = "\\Swoole\\Coroutine";
        if (class_exists($coroClass) && call_user_func([$coroClass, 'getCid']) > 0) {
            call_user_func([$coroClass, 'usleep'], $microseconds);
        } else {
            usleep($microseconds);
        }
    }

    /**
     * 启动时清理历史孤儿锁（kill -9 / OOM 导致锁未释放）
     */
    protected function cleanupOrphanLocks(): void
    {
        if (!function_exists('posix_getpid') || !function_exists('posix_kill')) {
            return;
        }

        $currentPid = posix_getpid();
        $items = $this->redis->hGetAll('scheduler:executor:pids');
        if (empty($items)) {
            return;
        }

        foreach ($items as $taskId => $token) {
            if (!is_string($token) || strpos($token, ':') === false) {
                continue;
            }
            $parts = explode(':', $token, 2);
            $pid = isset($parts[0]) ? (int)$parts[0] : 0;
            if ($pid > 0 && $pid !== $currentPid && !posix_kill($pid, 0)) {
                $lockKey = $this->lockPrefix . $taskId;
                $this->redis->unlock($lockKey, $token);
                $this->redis->zRem('scheduler:running:zset', $taskId);
                $this->redis->hDel('scheduler:executor:pids', $taskId);
                $this->log("Cleaned orphan lock for task {$taskId} from dead PID {$pid}", 'WARNING');
            }
        }
    }

    protected function log(string $msg, string $level = 'INFO'): bool
    {
        return $this->logger->log($msg, $level);
        /* $logPath = __DIR__ . '/../../storage/logs/' . date('Ym');
        if (!is_dir($logPath)) @mkdir($logPath, 0755, true);

        $logFile = $logPath . '/scheduler.log';
        $line = '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
        //echo $line;
        $times = 5;
        while ($times-- > 0) {
            $fp = fopen($logFile, 'ab');
            //$fp = file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            if ($fp and flock($fp, LOCK_EX)) {
                $n = fwrite($fp, $line);
                flock($fp, LOCK_UN);
                fclose($fp);
                clearstatcache();
                return true;
            } else {
                sleep(1);
            }
        }
        return false; */
    }
}
