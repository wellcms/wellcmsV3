<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

use Framework\Scheduler\Task;
use Framework\Utils\UuidHelper;

// 任务管理类（新增/取消/查询/重试/死信等）需要安装redis扩展
class TaskManage
{
    /** @var \Framework\Scheduler\RedisTaskQueue */
    private $queue;

    /** @var \Framework\Cache\Drivers\RedisCache */
    private $redis;

    /** @var \Framework\Logger\LoggerInterface|null */
    private $logger = null;

    /** @var \Framework\Scheduler\Interfaces\TaskStorageInterface|null MySQL 存储层（v3.4 新增，cancelTasksByClass 用） */
    private $taskStorage = null;

    /**
     * 最大参数长度
     * @var int
     */
    protected $maxArgLength = 4096;

    /** @var array v2 工业级配置缓存（由 ServiceProvider 注入） */
    private static $schedulerConfig = [];

    /**
     * 由 ServiceProvider 注入 v2 配置
     *
     * @param array $config
     */
    public static function setSchedulerConfig(array $config): void
    {
        self::$schedulerConfig = $config;
    }

    /**
     * 检测 v2 dual_write 是否激活
     *
     * @return bool
     */
    public function isV2DualWriteActive(): bool
    {
        $cfg = self::$schedulerConfig;
        return !empty($cfg['v2_enabled']) && !empty($cfg['dual_write']['enabled']);
    }

    public function __construct(\Framework\Cache\Drivers\RedisCache $redis)
    {
        $this->queue = new \Framework\Scheduler\RedisTaskQueue($redis);
        $this->redis = $redis;
    }

    /**
     * 可选注入日志器
     *
     * TaskManage 的构造函数仅接受 RedisCache（保持向后兼容），
     * 调用方可在构造后通过此方法注入框架日志器。
     * 注入后，内部 catch 块使用结构化日志代替 error_log() 兜底。
     */
    public function setLogger(\Framework\Logger\LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 注入 TaskStorageInterface（由 ServiceProvider 在 v2 模式下调用）
     */
    public function setTaskStorage(\Framework\Scheduler\Interfaces\TaskStorageInterface $storage): void
    {
        $this->taskStorage = $storage;
    }

    /**
     * 注入装饰后的队列（如 PersistenceQueue），由容器在 ServiceProvider 中调用
     */
    public function setQueue(\Framework\Scheduler\Interfaces\TaskQueueInterface $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * 取消指定类的所有 pending/retrying 任务。
     * 关闭配置时清理同类孤儿任务，确保 MySQL 无残留。
     * 使用游标分页（id > ?），不走 OFFSET。
     * 低频路径，不做复合索引。
     *
     * @param string $className
     * @return int 取消的数量
     */
    public function cancelTasksByClass(string $className): int
    {
        if ($this->taskStorage === null || !$this->isV2DualWriteActive()) {
            return 0;
        }

        $cancelled = 0;
        try {
            $lastId = '';
            $limit = 500;
            do {
                // 游标分页查询：每次取游标之后 500 条
                $tasks = $this->taskStorage->findPendingByClass($className, $lastId, $limit);
                $count = count($tasks);
                if ($count === 0) {
                    break;
                }
                foreach ($tasks as $row) {
                    $taskId = \Framework\Utils\UuidHelper::fromBinary($row['id']);

                    // —— R2 修复：跳过正在 executor 中运行的任务 ——
                    // executor 在 lock 成功后→zAdd(running_zset)→handle()。
                    // 锁存在 = 任务已弹出队列、正在或即将被执行，不应取消。
                    // 未锁 = 确认为 pending 队列中的待执行任务，安全取消。
                    $lockKey = 'scheduler:lock:task:' . $taskId;
                    if ($this->redis->exists($lockKey)) {
                        continue;  // 任务正在 executor 中，跳过
                    }
                    // 辅助检测：已在 running_zset 中（锁可能恰好已释放但任务还在运行）
                    $score = $this->redis->zScore('scheduler:running:zset', $taskId);
                    if ($score !== false) {
                        continue;  // 任务仍在运行中，跳过
                    }
                    // —— 检测结束 ——

                    $this->cancelTask($taskId);
                    $cancelled++;
                }
                if ($count > 0) {
                    // 本页最后一条的 BINARY(16) id 转 UUID 串，作为下一页游标
                    $lastId = \Framework\Utils\UuidHelper::fromBinary($tasks[$count - 1]['id']);
                }
            } while ($count === $limit);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error(sprintf(
                    '[TaskManage] cancelTasksByClass(%s) failed: %s', $className, $e->getMessage()
                ));
            }
        }

        return $cancelled;
    }

    /**
     * 检查某类 Job 是否有活跃任务
     * #6 修复: 声明式调度自愈的依赖方法
     *
     * @param string $className
     * @return bool true=有 pending/retrying/running 任务
     */
    public function hasActiveTaskOfClass(string $className): bool
    {
        // 检查 pending/retrying 队列（扫描范围扩大，避免遗漏长排队任务）
        $ids = $this->redis->zRange('scheduler:queue:zset', 0, 10000);
        if (!empty($ids)) {
            $raws = $this->redis->hMGet('scheduler:queue:hash', $ids);
            foreach ($raws as $raw) {
                if (empty($raw)) {
                    continue;
                }
                $task = json_decode($raw, true);
                if (!empty($task) && ($task['className'] ?? '') === $className
                    && in_array($task['status'] ?? -1, [Task::STATUS_PENDING, Task::STATUS_RETRYING], true)) {
                    return true;
                }
            }
        }

        // 检查 running 队列（扫描 24 小时内活跃任务，避免长任务被误判为无活跃）
        $runningIds = $this->redis->zRangeByScore('scheduler:running:zset', time() - 86400, time());
        if (!empty($runningIds)) {
            $raws = $this->redis->hMGet('scheduler:queue:hash', $runningIds);
            foreach ($raws as $raw) {
                if (empty($raw)) {
                    continue;
                }
                $task = json_decode($raw, true);
                if (!empty($task) && ($task['className'] ?? '') === $className
                    && ($task['status'] ?? -1) === Task::STATUS_RUNNING) {
                    // 验证锁活性：锁存在才是真正活跃的任务，避免僵尸任务阻塞 CDJ 播种
                    $lockKey = $this->lockPrefix . ($task['id'] ?? '');
                    if (!empty($task['id']) && $this->redis->exists($lockKey)) {
                        return true;
                    }
                    // 锁已过期 → 僵尸任务，不视为活跃，继续检查下一条
                }
            }
        }

        return false;
    }

    /**
     * 判断调度器是否存活 (最近 5 分钟内有心跳)
     */
    public function isAlive(int $threshold = 300): bool
    {
        try {
            $lastExec = $this->redis->get('scheduler:stats:last_execution');
            if (!$lastExec) return false;
            return (time() - (int)$lastExec) <= $threshold;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 轻量调度器脉冲检测
     *
     * 仅检查 Redis 连通性和调度器心跳时间戳，不调用 info()/zCard()/zCount()，
     * 避免在加固 Redis 部署中因 ACL 限制或 rename-command 导致误判。
     *
     * 设计原则：
     * - 永远只做最少的事：ping + get(last_execution)
     * - 调用方可随时调用：FPM / CLI / 调度器内部均安全
     * - 符合 Iron Law #25：catch 块必须记日志
     *
     * @param int $heartbeatThreshold  心跳超时阈值（秒），默认 600
     * @return bool                    true 表示调度器存活且心跳在阈值内
     */
    public function isSchedulerAlive(int $heartbeatThreshold = 600): bool
    {
        try {
            if (!$this->redis->ping()) {
                return false;
            }
            $lastExec = $this->redis->get('scheduler:stats:last_execution');
            if (!$lastExec) {
                return false;
            }
            return (time() - (int)$lastExec) <= $heartbeatThreshold;
        } catch (\Exception $e) {
            // 使用 PHP 原生 error_log() 兜底（TaskManage 无容器依赖，不强制调用方注入日志器）
            // 调用方可通过 setLogger() 注入框架日志器实现结构化日志
            if ($this->logger) {
                $this->logger->error(sprintf('[TaskManage::isSchedulerAlive] %s', $e->getMessage()));
            } else {
                error_log(sprintf('[TaskManage::isSchedulerAlive] %s', $e->getMessage()));
            }
            return false;
        }
    }

    /**
     * 创建任务（支持跳过 dedupeKey 检查）
     *
     * @param array $payload   任务负载
     * @param bool  $ignoreDedupe 为 true 时跳过 dedupeKey 幂等检查（修复：
     *                            scheduler 崩溃后 dedupeKey 残留导致 bootstrap 无法恢复）
     * @return array
     */
    public function createTask(array $payload, bool $ignoreDedupe = false): array
    {
        try {
            $p = $this->normalizeAndValidate($payload);

            if (!empty($p['dedupeKey']) && !$ignoreDedupe) {
                $k = 'scheduler:dedupe:' . $p['dedupeKey'];
                // 使用 SET NX EX 原子操作保证幂等
                // TTL 30 天：避免插件升级/重装等低频但跨天的场景下 dedupeKey 过早过期导致重复入队
                if (!$this->redis->setNx($k, 1, 2592000)) {
                    return ['status' => 'duplicate', 'msg' => 'duplicate task'];
                }
            }

            if ($ignoreDedupe && !empty($p['dedupeKey'])) {
                // 强制模式：先删旧 dedupeKey 再重新设置
                $k = 'scheduler:dedupe:' . $p['dedupeKey'];
                $this->redis->del($k);
                $this->redis->setNx($k, 1, 2592000);
            }

            $task = $this->makeTask($p);
            if (!empty($p['dedupeKey'])) {
                $task->dedupeKey = $p['dedupeKey'];
            }
            $this->queue->push($task);

            return ['status' => 'success', 'taskId' => $task->id];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'msg' => $e->getMessage()];
        }
    }

    /** 批量新增（后台批量按钮/导入用） */
    public function bulkCreate(array $items): array
    {
        $out = ['ok' => 0, 'fail' => 0, 'items' => []];
        foreach ($items as $payload) {
            try {
                $p = $this->normalizeAndValidate($payload);
                if (!empty($p['dedupeKey'])) {
                    $k = 'scheduler:dedupe:' . $p['dedupeKey'];
                    // TTL 30 天：避免插件升级/重装等低频但跨天的场景下 dedupeKey 过早过期导致重复入队
                    if (!$this->redis->setNx($k, 1, 2592000)) {
                        $out['fail']++;
                        $out['items'][] = ['error' => 'duplicate'];
                        continue;
                    }
                }
                $task = $this->makeTask($p);
                $this->queue->push($task);
                $out['ok']++;
                $out['items'][] = ['taskId' => $task->id];
            } catch (\Throwable $e) {
                $out['fail']++;
                $out['items'][] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** 取消任务（从主队列删除） */
    public function cancelTask(string $taskId): array
    {
        $this->queue->remove($taskId);
        return ['status' => 'success', 'taskId' => $taskId];
    }

    /** 查询任务详情（HASH中） */
    public function showTask(string $taskId): array
    {
        $raw = $this->redis->hGet('scheduler:queue:hash', $taskId);
        if (!$raw) return ['status' => 'not_found'];
        return ['status' => 'success', 'task' => json_decode($raw, true)];
    }

    /** 强制重试一次（立即或根据 retryDelay 重排） */
    public function retryTask(string $taskId): array
    {
        $raw = $this->redis->hGet('scheduler:queue:hash', $taskId);
        if (!$raw) return ['status' => 'not_found'];
        $task = Task::fromArray(json_decode($raw, true), false);
        $task->maxRetries = max($task->maxRetries, $task->retryCount + 1);
        $this->queue->requeue($task);
        return ['status' => 'success', 'taskId' => $taskId];
    }

    /** 死信列表（前 100 条） */
    /**
     * 获取失败任务列表
     *
     * 兼容两种失败队列模式：
     * - v1（PersistenceQueue 未启用）：从 scheduler:queue:failed_list（List）读取
     * - v2（PersistenceQueue 启用）：从 scheduler:dlq:max_retry / scheduler:dlq:other（ZSET）读取
     * 新增消费失败队列的代码必须同时兼容两种格式。
     */
    public function failedList(): array
    {
        // v2 dual_write 关闭时回退旧 List
        if (!$this->isV2DualWriteActive()) {
            $arr = $this->redis->lRange('scheduler:queue:failed_list', 0, 99);
            $items = array_map(function ($x) {
                return json_decode($x, true);
            }, $arr);
            return ['status' => 'success', 'items' => $items];
        }

        // 新: 从 scheduler:dlq:* ZSET 读 ID → HASH 取详情
        $ids = $this->redis->zRevRange('scheduler:dlq:max_retry', 0, 49);
        $ids2 = $this->redis->zRevRange('scheduler:dlq:other', 0, 49);
        $allIds = array_unique(array_merge($ids ?: [], $ids2 ?: []));
        $allIds = array_slice($allIds, 0, 100);

        $items = [];
        if (!empty($allIds)) {
            $raws = $this->redis->hMGet('scheduler:queue:hash', $allIds);
            foreach ($allIds as $id) {
                if (!empty($raws[$id])) {
                    $items[] = json_decode($raws[$id], true);
                }
            }
        }
        return ['status' => 'success', 'items' => $items];
    }

    /** 从死信回捞 */
    public function requeueFailed(array $taskIds): array
    {
        $ok = 0;
        foreach ($taskIds as $id) {
            $hit = $this->findInFailed($id);
            if (!$hit) continue;
            $task = Task::fromArray($hit, false);
            $task->retryCount = 0;
            $task->status = Task::STATUS_PENDING;
            $task->scheduledAt = time();
            $this->queue->push($task);
            $ok++;
        }
        return ['status' => 'success', 'count' => $ok];
    }

    /**
     * 获取待处理任务数量
     */
    public function getPendingCount(): int
    {
        return $this->redis->zCard('scheduler:queue:zset');
    }

    /**
     * 获取执行中任务数量（通过运行状态 ZSET 判断，更准确）
     */
    public function getRunningCount(): int
    {
        $fiveMinutesAgo = time() - 300;
        $count = $this->redis->zCount('scheduler:running:zset', $fiveMinutesAgo, time());
        // 顺手清理超过 1 小时的残留数据，防止 ZSET 无限增长
        $this->redis->zRemRangeByScore('scheduler:running:zset', 0, time() - 3600);
        return $count;
    }

    /**
     * 获取失败任务数量
     */
    public function getFailedCount(): int
    {
        if (!$this->isV2DualWriteActive()) {
            return $this->redis->lLen('scheduler:queue:failed_list');
        }

        return ($this->redis->zCard('scheduler:dlq:max_retry') ?: 0)
             + ($this->redis->zCard('scheduler:dlq:other') ?: 0);
    }

    /**
     * 获取成功任务数量（需要额外存储成功记录）
     */
    public function getSuccessCount(): int
    {
        return $this->redis->zCard('scheduler:stats:success') ?: 0;
    }

    /**
     * 获取最近任务列表
     */
    public function getRecentTasks(int $limit = 10): array
    {
        $res = $this->listTasks('', '', 1, $limit);
        return $res['items'];
    }

    /**
     * 任务列表查询 (支持分页和筛选)
     */
    public function listTasks(string $status = '', string $keywords = '', int $page = 1, int $pageSize = 20): array
    {
        $allTasks = [];
        $limit = 1000; // 总量限制，避免大数量下 Redis 阻塞

        // API 传入的 status 字符串转 DB int 常量值，用于后续与 Redis 中的 int status 比较
        $statusToInt = [
            'pending'  => Task::STATUS_PENDING,
            'retrying' => Task::STATUS_RETRYING,
            'running'  => Task::STATUS_RUNNING,
            'success'  => Task::STATUS_SUCCESS,
            'failed'   => Task::STATUS_FAILED,
        ];

        // 1. 获取主队列任务（pending + retrying）
        if ($status === 'pending' || $status === 'retrying' || $status === '') {
            $ids = $this->redis->zRange('scheduler:queue:zset', 0, $limit - 1);
            if (!empty($ids)) {
                $raws = $this->redis->hMGet('scheduler:queue:hash', $ids);
                foreach ($raws as $id => $raw) {
                    if ($raw) {
                        $task = json_decode($raw, true);
                        if ($task) {
                            $allTasks[] = $task;
                        }
                    }
                }
            }
        }

        // 2. 获取 Running 任务
        if ($status === 'running' || $status === '') {
            $runningIds = $this->redis->zRangeByScore('scheduler:running:zset', time() - 300, time());
            if (!empty($runningIds)) {
                $raws = $this->redis->hMGet('scheduler:queue:hash', $runningIds);
                foreach ($raws as $raw) {
                    if ($raw) {
                        $task = json_decode($raw, true);
                        if ($task) {
                            if (empty($task['status']) || !in_array($task['status'], [Task::STATUS_PENDING, Task::STATUS_RETRYING, Task::STATUS_RUNNING])) {
                                $task['status'] = Task::STATUS_RUNNING;
                            }
                            $allTasks[] = $task;
                        }
                    }
                }
            }
        }

        // 3. 获取 Failed 任务
        if ($status === 'failed' || $status === '') {
            if ($this->isV2DualWriteActive()) {
                $failedIds = $this->redis->zRevRange('scheduler:dlq:max_retry', 0, 249);
                $failedIds2 = $this->redis->zRevRange('scheduler:dlq:other', 0, 249);
                $allFailed = array_unique(array_merge($failedIds ?: [], $failedIds2 ?: []));
                if (!empty($allFailed)) {
                    $raws = $this->redis->hMGet('scheduler:queue:hash', $allFailed);
                    foreach ($allFailed as $id) {
                        if (!empty($raws[$id])) {
                            $task = json_decode($raws[$id], true);
                            if ($task) {
                                $allTasks[] = $task;
                            }
                        }
                    }
                }
            } else {
                $failedRaw = $this->redis->lRange('scheduler:queue:failed_list', 0, 499);
                foreach ($failedRaw as $raw) {
                    $task = json_decode($raw, true);
                    if ($task) {
                        $allTasks[] = $task;
                    }
                }
            }
        }

        // 4. 获取 Success 任务
        if ($status === 'success' || $status === '') {
            $successIds = $this->redis->zRevRange('scheduler:stats:success', 0, 499);
            if (!empty($successIds)) {
                $raws = $this->redis->hMGet('scheduler:queue:hash', $successIds);
                foreach ($successIds as $id) {
                    if (!empty($raws[$id])) {
                        $task = json_decode($raws[$id], true);
                        if ($task) {
                            $allTasks[] = $task;
                        }
                    }
                }
            }
        }

        // 5. 按 ID 去重（同一条任务可能出现在多个队列中，保留第一个出现）
        $seen = [];
        $deduped = [];
        foreach ($allTasks as $task) {
            $tid = $task['id'] ?? '';
            if ($tid === '' || isset($seen[$tid])) continue;
            $seen[$tid] = true;
            $deduped[] = $task;
        }
        $allTasks = $deduped;

        // 6. 若指定了具体状态，进行二次过滤（$status 为 API 传入的字符串，转 int 与 Redis 数据比较）
        if ($status && in_array($status, ['pending', 'retrying', 'running', 'failed', 'success'])) {
            $statusCode = $statusToInt[$status] ?? -1;
            $allTasks = array_filter($allTasks, function ($task) use ($statusCode) {
                return ($task['status'] ?? -1) === $statusCode;
            });
            $allTasks = array_values($allTasks);
        }

        // 7. 关键词过滤
        if ($keywords) {
            $kw = mb_strtolower($keywords);
            $allTasks = array_filter($allTasks, function ($task) use ($kw) {
                return strpos(mb_strtolower($task['id'] ?? ''), $kw) !== false
                    || strpos(mb_strtolower($task['className'] ?? ''), $kw) !== false
                    || strpos(mb_strtolower($task['methodName'] ?? ''), $kw) !== false;
            });
        }

        // 8. 排序 (按更新时间降序)
        usort($allTasks, function ($a, $b) {
            $ta = $a['updatedAt'] ?? $a['createdAt'] ?? 0;
            $tb = $b['updatedAt'] ?? $b['createdAt'] ?? 0;
            return (int)$tb - (int)$ta;
        });

        // 9. 分页处理
        $total = count($allTasks);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($allTasks, $offset, $pageSize);

        return [
            'status' => 'success',
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize
        ];
    }

    /**
     * 获取系统健康状态
     */
    public function getSystemHealth(): array
    {
        $health = [
            'redis_connected' => false,
            'queue_size'      => 0,
            'memory_usage'    => '0%',
            'last_execution'  => 0,
            'errors_last_hour'=> 0,
        ];

        try {
            // 第一组：连通性与队列（基础指标）
            $health['redis_connected'] = $this->redis->ping();
            $health['queue_size']      = $this->getPendingCount();

            // 第二组：内存信息（info() 可能被 ACL 限制，独立 try-catch）
            $health['memory_usage'] = $this->getMemoryUsage();

            // 第三组：调度器心跳（核心指标，与 info() 解耦）
            $lastExec = $this->redis->get('scheduler:stats:last_execution');
            $health['last_execution'] = $lastExec ? (int)$lastExec : 0;

            // 第四组：错误统计
            $hourAgo = time() - 3600;
            $errorCount = $this->redis->zCount('scheduler:stats:errors', $hourAgo, time());
            $health['errors_last_hour'] = $errorCount ?: 0;
        } catch (\Exception $e) {
            $health['error'] = $e->getMessage();
            $health['memory_usage'] = '0%';
        }

        return $health;
    }

    /**
     * 内存使用率（从 Redis info 计算，可能被 ACL 限制）
     * 独立 try-catch，失败不影响其他健康指标
     */
    private function getMemoryUsage(): string
    {
        try {
            $info = $this->redis->info();
            $usedMemory = (int)($info['used_memory'] ?? 0);
            $maxMemory  = (int)($info['maxmemory'] ?? 0);

            if ($maxMemory > 0 && $usedMemory > 0) {
                return min(100, round($usedMemory / $maxMemory * 100)) . '%';
            }
            if ($usedMemory > 0) {
                $memTotal = 0;
                if (is_readable('/proc/meminfo')) {
                    $memInfo = file_get_contents('/proc/meminfo');
                    if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $matches)) {
                        $memTotal = (int)$matches[1] * 1024;
                    }
                }
                if ($memTotal > 0) {
                    return min(100, round($usedMemory / $memTotal * 100)) . '%';
                }
            }
        } catch (\Exception $e) {
            // 加固型 Redis 部署不支持 info()，静默降级
            if ($this->logger) {
                $this->logger->warning(sprintf('[TaskManage::getMemoryUsage] %s', $e->getMessage()));
            }
        }
        return '0%';
    }

    /**
     * 获取任务执行统计
     */
    public function getExecutionStats(string $period = 'today'): array
    {
        $now = time();
        $startTime = $this->getPeriodStartTime($period);

        return [
            'period' => $period,
            'success_count' => $this->getSuccessCountByPeriod($startTime, $now),
            'failed_count' => $this->getFailedCountByPeriod($startTime, $now),
            'avg_execution_time' => $this->getAvgExecutionTime($startTime, $now),
            'throughput' => $this->getThroughput($startTime, $now)
        ];
    }

    /**
     * 获取任务日志（用于前端展示）
     * @param string $taskId 任务ID，如果为空则获取所有日志
     * @param int $limit 限制返回数量
     * @return array 日志条目数组
     */
    public function getLogs(string $taskId = '', int $limit = 100): array
    {
        $logs = [];
        $logPath = $this->getLogPath();
        $logFile = $logPath . '/scheduler.log';

        if (!file_exists($logFile)) {
            return $logs;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $logs;
        }

        // 反向读取（最新的在前）
        $lines = array_reverse($lines);
        $count = 0;

        foreach ($lines as $line) {
            // 解析日志格式：[2025-01-06 19:45:00] [INFO] Task xxx succeeded
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\] (.+)$/', $line, $matches)) {
                $timestamp = $matches[1];
                $level = $matches[2];
                $message = $matches[3];

                // 如果指定了taskId，只返回该任务的日志
                if ($taskId !== '' && strpos($message, $taskId) === false) {
                    continue;
                }

                $logs[] = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'message' => $message
                ];

                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $logs;
    }

    /**
     * 获取日志文件路径
     */
    private function getLogPath(): string
    {
        return __DIR__ . '/../../storage/logs/' . date('Ym');
    }

    /**
     * 动态调整任务优先级
     * @param string $taskId 任务ID
     * @param int $newPriority 新优先级（0-10，越小越优先）
     * @return array 操作结果
     */
    public function updatePriority(string $taskId, int $newPriority): array
    {
        try {
            // 验证新优先级
            $newPriority = Task::sanitizePriority($newPriority);

            // 从 HASH 中获取任务
            $raw = $this->redis->hGet('scheduler:queue:hash', $taskId);
            if (!$raw) {
                return ['status' => 'not_found', 'msg' => "Task not found: {$taskId}"];
            }

            $taskData = json_decode($raw, true);
            if ($taskData === null) {
                return ['status' => 'error', 'msg' => 'Invalid task data'];
            }

            // 确保任务是 pending 或 retrying 状态才能调整优先级
            $adjustableStatuses = [Task::STATUS_PENDING, Task::STATUS_RETRYING];
            if (!in_array($taskData['status'], $adjustableStatuses)) {
                return [
                    'status' => 'error',
                    'msg' => "Only pending or retrying tasks can have priority adjusted. Current status: {$taskData['status']}"
                ];
            }

            // 创建 Task 对象并更新优先级
            $task = \Framework\Scheduler\Task::fromArray($taskData);
            $task->priority = $newPriority;
            $task->updatedAt = time();

            // 重新推入队列（会从原位置移除并重新评分）
            $this->queue->push($task);

            $this->redis->hSet(
                'scheduler:queue:hash',
                $taskId,
                json_encode($task->toArray(), JSON_UNESCAPED_UNICODE)
            );

            return [
                'status' => 'success',
                'taskId' => $taskId,
                'newPriority' => $newPriority
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => $e->getMessage()];
        }
    }

    /**
     * 获取吞吐量统计
     */
    public function getThroughput(int $startTime, int $endTime): float
    {
        $totalTasks = $this->getSuccessCountByPeriod($startTime, $endTime)
            + $this->getFailedCountByPeriod($startTime, $endTime);

        if ($totalTasks === 0) {
            return 0.0;
        }

        $duration = max(1, $endTime - $startTime); // 避免除零
        return round($totalTasks / ($duration / 3600), 2); // 任务/小时
    }

    /* private function json(int $code, array $body): ResponseInterface
    {
        return new Response($code, $body);
    } */

    private function findInFailed(string $id): ?array
    {
        // v2: 直接从 Hash 查找任务（替代原 failed_list 遍历）
        if ($this->isV2DualWriteActive()) {
            $raw = $this->redis->hGet('scheduler:queue:hash', $id);
            if (!$raw) {
                return null;
            }
            $task = json_decode($raw, true);
            return is_array($task) ? $task : null;
        }

        // 限制遍历范围，避免遍历整个失败队列（可能非常大）
        // 只搜索前 1000 条记录，超过这个数量的任务不应该频繁重试
        $arr = $this->redis->lRange('scheduler:queue:failed_list', 0, 999);
        foreach ($arr as $row) {
            $obj = json_decode($row, true);
            if (($obj['id'] ?? '') === $id) {
                return $obj;
            }
        }
        return null;
    }



    /**
     * 根据时间段获取开始时间戳
     */
    private function getPeriodStartTime(string $period): int
    {
        $now = time();
        switch ($period) {
            case 'hour':
                return $now - 3600;
            case 'today':
                return strtotime('today');
            case 'week':
                return $now - 7 * 86400;
            case 'month':
                return $now - 30 * 86400;
            default:
                return $now - 86400; // 默认24小时
        }
    }

    /**
     * 获取时间段内成功任务数量
     */
    private function getSuccessCountByPeriod(int $startTime, int $endTime): int
    {
        // 使用 Redis ZSET 存储成功任务的时间戳
        return $this->redis->zCount('scheduler:stats:success', $startTime, $endTime) ?: 0;
    }

    /**
     * 获取时间段内失败任务数量
     */
    private function getFailedCountByPeriod(int $startTime, int $endTime): int
    {
        // 使用 Redis ZSET 存储失败任务的时间戳
        return $this->redis->zCount('scheduler:stats:errors', $startTime, $endTime) ?: 0;
    }

    /**
     * 获取平均执行时间
     */
    private function getAvgExecutionTime(int $startTime, int $endTime): float
    {
        // 获取时间段内的执行时间
        $taskIds = $this->redis->zRangeByScore('scheduler:stats:success', $startTime, $endTime);

        if (empty($taskIds)) {
            return 0.0;
        }

        // 批量获取执行时间，使用 hMGet 替代 native pipeline 以保证前缀一致性并减少复杂度
        $res = $this->redis->hMGet('scheduler:execution_times', $taskIds);

        // 计算平均值
        $total = 0.0;
        $count = 0;
        foreach ($res as $time) {
            if ($time !== null) {
                $total += (float)$time;
                $count++;
            }
        }

        return $count > 0 ? round($total / $count, 3) : 0.0;
    }

    private function normalizeAndValidate(array $p): array
    {
        // 必填验证：仅 className 为必须
        if (empty($p['className'])) {
            throw new \InvalidArgumentException('className required');
        }

        // methodName 默认为 handle
        $p['methodName'] = $p['methodName'] ?? 'handle';
        if (empty($p['methodName'])) {
            $p['methodName'] = 'handle';
        }

        // 使用 Task 的统一验证方法（移除重复验证逻辑）
        $p['className'] = \Framework\Scheduler\Task::sanitizeClassName($p['className']);

        $args = isset($p['args']) ? (array)$p['args'] : [];
        $p['args'] = \Framework\Scheduler\Task::sanitizeArgs($args);

        // 默认值（使用 Task 的 sanitize 方法保证一致性）
        $p['priority'] = \Framework\Scheduler\Task::sanitizePriority($p['priority'] ?? 5);
        $p['maxRetries'] = \Framework\Scheduler\Task::sanitizeRetryCount($p['maxRetries'] ?? 3);
        $p['retryDelay'] = \Framework\Scheduler\Task::sanitizeRetryDelay($p['retryDelay'] ?? 1);
        $p['timeout'] = \Framework\Scheduler\Task::sanitizeTimeout($p['timeout'] ?? 0);
        $p['callbackUrl'] = \Framework\Scheduler\Task::sanitizeUrl($p['callbackUrl'] ?? '');
        if ($p['callbackUrl'] !== '' && $this->isPrivateUrl($p['callbackUrl'])) {
            throw new \InvalidArgumentException('Callback URL must not point to private network');
        }
        $p['callbackMethod'] = \Framework\Scheduler\Task::sanitizeHttpMethod($p['callbackMethod'] ?? 'POST');
        $p['status'] = \Framework\Scheduler\Task::sanitizeStatus($p['status'] ?? 'pending');

        // scheduledAt：支持时间戳或可解析字符串
        if (!empty($p['scheduledAt'])) {
            $p['scheduledAt'] = is_numeric($p['scheduledAt']) ? (int)$p['scheduledAt'] : (int)strtotime($p['scheduledAt']);
        } else {
            $p['scheduledAt'] = \Framework\Scheduler\Task::sanitizeTimestamp(time());
        }
        $p['scheduledAt'] = \Framework\Scheduler\Task::sanitizeTimestamp($p['scheduledAt']);

        // 去重键可选
        if (!empty($p['dedupeKey'])) {
            $p['dedupeKey'] = (string)$p['dedupeKey'];
        }

        return $p;
    }

    private function makeTask(array $p): \Framework\Scheduler\Task
    {
        $id = !empty($p['id']) ? (string)$p['id'] : UuidHelper::generate(false);
        $p['args']['_task_id'] = $id; // 自动传入任务ID，方便回调使用
        $p['args']['_session_id'] = $id; // 自动传入任务ID，方便回调使用
        $t = new \Framework\Scheduler\Task(
            $id,
            $p['className'],
            $p['methodName'],
            $p['args'],
            $p['priority'],
            $p['maxRetries'],
            $p['retryDelay'],
            $p['timeout'],
            $p['callbackUrl'],
            $p['callbackMethod']
        );
        $t->scheduledAt = $p['scheduledAt'];
        return $t;
    }

    /** 检查 callbackUrl 是否指向内网 */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return true;
        $ip = gethostbyname($host);
        return (
            preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])|169\.254\.)/', $ip) === 1
        );
    }
}

/*
use Framework\Scheduler\TaskManage;
use Framework\Cache\CacheManager;

// 新增任务 需要安装redis扩展
// $cache 来自你的容器，例如：$cache = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
$taskManage = new TaskManage($cache);

$payload = [
    'className' => 'App\\Jobs\\SendEmail',
    'methodName' => 'handle',
    'args' => [
        'to' => 'user@example.com',
        'subject' => '欢迎',
        'body' => '你好，欢迎使用。'
    ],
    'priority' => 3,
    'maxRetries' => 3,
    'retryDelay' => 5,
    'timeout' => 30,
    'callbackUrl' => 'https://example.com/task-callback',
    'callbackMethod' => 'POST',
    'scheduledAt' => time() + 10, // 10 秒后执行
    'dedupeKey' => 'sendemail_user_example_com' // 幂等去重 key（可选）
];

$result = $taskManage->createTask($payload);
var_dump($result);
// 期望： ['status' => 'success', 'taskId' => '...'] 或 { 'status' => 'duplicate' } / error

// 批量创建
$items = [
    [
        'className' => 'App\\Jobs\\SendEmail',
        'methodName' => 'handle',
        'args' => ['to' => 'a@example.com', 'subject' => 'x', 'body' => '...']
    ],
    [
        'className' => 'App\\Jobs\\SendEmail',
        'methodName' => 'handle',
        'args' => ['to' => 'b@example.com', 'subject' => 'y', 'body' => '...'],
        'dedupeKey' => 'send_b'
    ]
];

$res = $taskManage->bulkCreate($items);
print_r($res);

// 取消任务
$res = $taskManage->cancelTask($taskId);
print_r($res); // ['status' => 'success', 'taskId' => 'the-task-id']

// 查询任务详情
$res = $taskManage->showTask($taskId);
if ($res['status'] === 'success') {
    $task = $res['task'];
    print_r($task);
}

// 强制重试
$res = $taskManage->retryTask($taskId);
print_r($res);

// 查看死信（前 100 条）
$res = $taskManage->failedList();
foreach ($res['items'] as $item) {
    // $item 是 Task 的数组形式
}

// 从死信回捞若干任务并重新入队
$res = $taskManage->requeueFailed(['taskId1', 'taskId2']);
print_r($res); // ['status'=>'success','count'=>n] */