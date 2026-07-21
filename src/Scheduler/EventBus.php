<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * 可观测性事件总线
 *
 * 基于 Redis List 的轻量级事件管道。
 * - emit() 为 fire-and-forget，异常不抛出。
 * - read() 非破坏性读取。
 * - consume() 破坏性消费（rPop）。
 * - watch() 非破坏性读取 + 游标追踪，供健康检查脚本使用。
 *
 * PHP 7.2 兼容。
 */
class EventBus
{
    /** @var \Framework\Cache\Drivers\RedisCache */
    private $redis;

    /** @var string */
    private $prefix = 'scheduler:events';

    /** @var int */
    private $maxEvents = 10000;

    /** @var \Framework\Scheduler\Logger */
    private $logger;

    public function __construct(\Framework\Cache\Drivers\RedisCache $redis, ?\Framework\Scheduler\Logger $logger = null)
    {
        $this->redis  = $redis;
        $this->logger = $logger ?? new \Framework\Scheduler\Logger();
    }

    public function setMaxEvents(int $maxEvents): void
    {
        $this->maxEvents = $maxEvents;
    }

    /**
     * fire-and-forget: 异常不抛出
     *
     * @param string $eventName
     * @param array  $payload
     */
    public function emit(string $eventName, array $payload): void
    {
        try {
            $event = json_encode(array_merge([
                'event' => $eventName,
                'ts' => microtime(true),
                'pid' => getmypid(),
            ], $payload), JSON_UNESCAPED_UNICODE);

            if ($event === false) {
                return;
            }

            $this->redis->lPush($this->prefix, $event);
            $this->redis->lTrim($this->prefix, 0, $this->maxEvents - 1);
        } catch (\Throwable $e) {
            $this->logger->log('emit failed: ' . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * 读取（非破坏性）
     *
     * @param int $start
     * @param int $limit
     * @return array
     */
    public function read(int $start = 0, int $limit = 100): array
    {
        try {
            $raw = $this->redis->lRange($this->prefix, $start, $start + $limit - 1);
            if (empty($raw)) {
                return [];
            }
            $events = [];
            foreach ($raw as $item) {
                $decoded = json_decode($item, true);
                if ($decoded !== null) {
                    $events[] = $decoded;
                }
            }
            return $events;
        } catch (\Throwable $e) {
            $this->logger->log('read failed: ' . $e->getMessage(), 'WARNING');
            return [];
        }
    }

    /**
     * 监控消费：非破坏性读取 + 游标追踪
     * 健康检查脚本定期调用，只看到"新"事件。
     *
     * 由于事件使用 LPUSH 写入（队列头部为最新事件），游标记录的是
     * 已消费的事件长度而非 List 下标，每次 watch 返回自上次以来新增的
     * 头部事件。
     *
     * @param int $limit
     * @return array
     */
    public function watch(int $limit = 100): array
    {
        $cursorKey = $this->prefix . ':cursor';
        $previous = (int)($this->redis->get($cursorKey) ?: 0);
        $total = (int)$this->redis->lLen($this->prefix);

        $newCount = max(0, $total - $previous);
        if ($newCount <= 0) {
            return [];
        }

        $events = $this->read(0, min($limit, $newCount));

        if (!empty($events)) {
            $this->redis->set($cursorKey, $previous + count($events));
            // 游标 24h TTL，健康检查脚本长期停启不会读到过期位置
            $this->redis->expire($cursorKey, 86400);
        }

        return $events;
    }

    /**
     * 消费（破坏性 rPop）
     *
     * @param int $limit
     * @return array
     */
    public function consume(int $limit = 100): array
    {
        $events = [];
        try {
            for ($i = 0; $i < $limit; $i++) {
                $raw = $this->redis->rPop($this->prefix);
                if ($raw === false || $raw === null) {
                    break;
                }
                $decoded = json_decode($raw, true);
                if ($decoded !== null) {
                    $events[] = $decoded;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->log('consume failed: ' . $e->getMessage(), 'WARNING');
        }
        return $events;
    }
}
