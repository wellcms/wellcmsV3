<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * Worker 协调器
 *
 * FPM 模式: 进程心跳；Swoole 模式: 协程 Timer 心跳。
 * v3.2 精简: 仅保留 Redis 心跳相关能力，僵尸检测 SQL 逻辑迁出至 ZombieHandler。
 * PHP 7.2 兼容。
 */
class WorkerCoordinator
{
    /** @var \Framework\Cache\Drivers\RedisCache */
    private $redis;

    /** @var string */
    private $workerId;

    /** @var int */
    private $heartbeatTtl = 30;

    /**
     * @param \Framework\Cache\Drivers\RedisCache $redis
     */
    public function __construct(\Framework\Cache\Drivers\RedisCache $redis)
    {
        $this->redis = $redis;
        $host = gethostname();
        $pid = getmypid();
        $cid = 0;
        if (\extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            $cid = \Swoole\Coroutine::getCid();
        }
        $this->workerId = $host . ':' . $pid . ':' . $cid;
    }

    /**
     * v3.2 精简: 仅保留 heartbeatTtl，移除 zombieThreshold 参数
     *
     * @param int $heartbeatTtl
     */
    public function configure(int $heartbeatTtl): void
    {
        $this->heartbeatTtl = $heartbeatTtl;
    }

    /**
     * 发送心跳
     */
    public function heartbeat(): void
    {
        $this->redis->setex('scheduler:workers:' . $this->workerId, $this->heartbeatTtl, json_encode([
            'pid' => getmypid(),
            'host' => gethostname(),
            'started_at' => time(),
        ]));
    }

    /**
     * 注销
     */
    public function unregister(): void
    {
        $this->redis->del('scheduler:workers:' . $this->workerId);
    }

    /**
     * @return string
     */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }
}
