<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * 自动清理过期会话任务 (Session Garbage Collection Job)
 * 
 * 职责：
 * 调用 Session 存储驱动的 gc() 方法，物理删除过期的数据行。
 * 使用自循环机制，确保任务持续运行而无需依赖外部 Crontab。
 */
class SessionGCJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Session\Handler\DatabaseSessionHandler */
    private $handler;

    /** @var \Framework\Scheduler\TaskManage */
    private $taskManage;

    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;

    /** @var array */
    private $sessionConfig;

    public function __construct(\App\Session\Handler\DatabaseSessionHandler $handler, \Framework\Scheduler\TaskManage $taskManage, \Framework\Cache\Interfaces\CacheInterface $cache, array $sessionConfig)
    {
        $this->handler = $handler;
        $this->taskManage = $taskManage;
        $this->cache = $cache;
        $this->sessionConfig = $sessionConfig;
    }

    /**
     * 执行执行逻辑
     */
    public function handle(?string $_task_id = null): array
    {
        // 1. 获取过期时间配置 (默认 1800 秒)
        $maxLifetime = (int)($this->sessionConfig['online_hold_time'] ?? 1800);

        // 2. 执行物理清理
        $cleanedCount = $this->handler->gc($maxLifetime);

        // 3. 更新集群/全局最后的 GC 时间
        // 这可以让 FPM 环境下的 SessionManager 看到“任务已被完成”，从而跳过手动 GC 逻辑
        $this->cache->set('session_gc_last_time', time());

        // 4. [核心] 注册下一次清理任务 (自循环)
        // 间隔时间取 gc_recycle_time (默认 600 秒)
        $interval = (int)($this->sessionConfig['gc_recycle_time'] ?? 600);

        $this->taskManage->createTask([
            'className'   => self::class,
            'methodName'  => 'handle',
            'args'        => [],
            'priority'    => 10, // 低优先级，不抢占业务任务
            'scheduledAt' => time() + $interval,
            'dedupeKey'   => 'system:session_gc_monitor'
        ]);

        return [
            'status'  => 'success',
            'cleaned' => $cleanedCount,
            'next_run_at' => date('Y-m-d H:i:s', time() + $interval)
        ];
    }
}
