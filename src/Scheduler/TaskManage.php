<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

// 任务管理类（新增/取消/查询/重试/死信等）需要安装redis扩展
class TaskManage
{
    /** @var \Framework\Scheduler\RedisTaskQueue */
    private $queue;

    /** @var \Framework\Cache\Drivers\RedisCache */
    private $redis;

    /**
     * 最大参数长度
     * @var int
     */
    protected $maxArgLength = 4096;

    public function __construct(\Framework\Cache\Drivers\RedisCache $redis)
    {
        $this->queue = new \Framework\Scheduler\RedisTaskQueue($redis);
        $this->redis = $redis;
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

    /** 后台新增任务（支持 scheduledAt / dedupeKey） */
    public function createTask(array $payload): array
    {
        try {
            $p = $this->normalizeAndValidate($payload);

            // 幂等去重（可选）
            if (!empty($p['dedupeKey'])) {
                $k = 'scheduler:dedupe:' . $p['dedupeKey'];
                // 使用 SET NX EX 原子操作保证幂等
                // TTL 30 天：避免插件升级/重装等低频但跨天的场景下 dedupeKey 过早过期导致重复入队
                if (!$this->redis->setNx($k, 1, 2592000)) {
                    return ['status' => 'duplicate', 'msg' => 'duplicate task'];
                }
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
    public function failedList(): array
    {
        $arr = $this->redis->lRange('scheduler:queue:failed_list', 0, 99);
        $items = array_map(function ($x) {
            return json_decode($x, true);
        }, $arr);
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
            $task->status = 'pending';
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
        return $this->redis->lLen('scheduler:queue:failed_list');
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
                            if (empty($task['status']) || !in_array($task['status'], ['pending', 'retrying', 'running'])) {
                                $task['status'] = 'running';
                            }
                            $allTasks[] = $task;
                        }
                    }
                }
            }
        }

        // 3. 获取 Failed 任务
        if ($status === 'failed' || $status === '') {
            $failedRaw = $this->redis->lRange('scheduler:queue:failed_list', 0, 499);
            foreach ($failedRaw as $raw) {
                $task = json_decode($raw, true);
                if ($task) {
                    $allTasks[] = $task;
                }
            }
        }

        // 4. 获取 Success 任务
        if ($status === 'success' || $status === '') {
            $successRaw = $this->redis->lRange('scheduler:queue:success_list', 0, 499);
            foreach ($successRaw as $raw) {
                $task = json_decode($raw, true);
                if ($task) {
                    $allTasks[] = $task;
                }
            }
        }

        // 5. 若指定了具体状态，进行二次过滤
        if ($status && in_array($status, ['pending', 'retrying', 'running', 'failed', 'success'])) {
            $allTasks = array_filter($allTasks, function ($task) use ($status) {
                return ($task['status'] ?? '') === $status;
            });
            $allTasks = array_values($allTasks);
        }

        // 5. 关键词过滤
        if ($keywords) {
            $kw = mb_strtolower($keywords);
            $allTasks = array_filter($allTasks, function ($task) use ($kw) {
                return strpos(mb_strtolower($task['id'] ?? ''), $kw) !== false
                    || strpos(mb_strtolower($task['className'] ?? ''), $kw) !== false
                    || strpos(mb_strtolower($task['methodName'] ?? ''), $kw) !== false;
            });
        }

        // 6. 排序 (按更新时间降序)
        usort($allTasks, function ($a, $b) {
            $ta = $a['updatedAt'] ?? $a['createdAt'] ?? 0;
            $tb = $b['updatedAt'] ?? $b['createdAt'] ?? 0;
            return (int)$tb - (int)$ta;
        });

        // 7. 分页处理
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
            'queue_size' => 0,
            'memory_usage' => '0%',
            'last_execution' => 0,
            'errors_last_hour' => 0
        ];

        try {
            // 检查 Redis 连接
            $health['redis_connected'] = $this->redis->ping();

            // 队列大小
            $health['queue_size'] = $this->getPendingCount();

            // 内存使用情况
            $info = $this->redis->info();
            $usedMemory = (int)($info['used_memory'] ?? 0);
            $maxMemory = (int)($info['maxmemory'] ?? 0);
            if ($maxMemory > 0 && $usedMemory > 0) {
                $health['memory_usage'] = min(100, round($usedMemory / $maxMemory * 100)) . '%';
            } elseif ($usedMemory > 0) {
                // 未设置 maxmemory，尝试读取系统总内存（Linux）
                $memTotal = 0;
                if (is_readable('/proc/meminfo')) {
                    $memInfo = file_get_contents('/proc/meminfo');
                    if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $matches)) {
                        $memTotal = (int)$matches[1] * 1024;
                    }
                }
                if ($memTotal > 0) {
                    $health['memory_usage'] = min(100, round($usedMemory / $memTotal * 100)) . '%';
                } else {
                    $health['memory_usage'] = '0%';
                }
            } else {
                $health['memory_usage'] = '0%';
            }

            // 最后执行时间
            $lastExec = $this->redis->get('scheduler:stats:last_execution');
            $health['last_execution'] = $lastExec ? (int)$lastExec : 0;

            // 最近1小时错误数
            $hourAgo = time() - 3600;
            $errorCount = $this->redis->zCount('scheduler:stats:errors', $hourAgo, time());
            $health['errors_last_hour'] = $errorCount ?: 0;
        } catch (\Exception $e) {
            // 记录错误但不抛出
            $health['error'] = $e->getMessage();
            $health['memory_usage'] = '0%';
        }

        return $health;
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
            $adjustableStatuses = ['pending', 'retrying'];
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
        // 限制遍历范围，避免遍历整个失败队列（可能非常大）
        // 只搜索前 1000 条记录，超过这个数量的任务不应该频繁重试
        $arr = $this->redis->lRange('scheduler:queue:failed_list', 0, 999);
        foreach ($arr as $row) {
            $obj = json_decode($row, true);
            if (($obj['id'] ?? '') === $id) return $obj;
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
        $id = !empty($p['id']) ? (string)$p['id'] : $this->uuidV4();
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

    private function uuidV4(): string
    {
        // 兼容 PHP 7.2 & 足够随机
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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