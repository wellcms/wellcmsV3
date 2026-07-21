<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Pool;

/**
 * RedisPool：支持协程与 FPM 的 Redis 连接池
 */
class RedisPool
{
    /** @var object[]|array[] 内部连接存储 */
    private $pool = [];

    /** @var object[] 协程通道池缓存 */
    private static $channels = [];

    /** @var int 当前已创建连接数 */
    private $currentSize = 0;

    /** @var int 最大连接数上限 */
    private $maxSize;

    /** @var array 连接配置 */
    private $config;

    /** @var array 记录连接最后活跃时间 */
    private $lastUsed = [];

    /** @var array<string,self> 多例实例（按配置哈希区分） */
    private static $instances = [];

    /**
     * 私有构造，防止外部 new
     * @param array $config
     * @param int   $maxSize
     */
    private function __construct(array $config, int $maxSize)
    {
        $this->config  = $config;
        $this->maxSize = $maxSize;
    }

    /**
     * 获取单例实例
     * @param array $config
     * @param int   $maxSize
     * @return self
     */
    public static function getInstance(array $config, int $maxSize = 10): self
    {
        // 基于配置+maxSize 做实例分桶，避免不同配置冲突
        $key = md5(json_encode([
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 6379,
            'db'   => (int)($config['dbname'] ?? 0),
            'pid'  => $config['persistent_id'] ?? '',
            'size' => $maxSize,
        ]));
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config, $maxSize);
        }
        return self::$instances[$key];
    }

    public function getConnection(): \Redis
    {
        $coroClass = "\\Swoole\\Coroutine";
        $isCoroutine = \extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0;

        // 1. 尝试从池中获取
        if ($isCoroutine) {
            $channel = $this->getChannel();
            /** @noinspection PhpUndefinedMethodInspection */
            if (!$channel->isEmpty()) {
                /** @noinspection PhpUndefinedMethodInspection */
                $redis = $channel->pop(0.001);
                if ($redis instanceof \Redis) {
                    try {
                        $hash = spl_object_hash($redis);
                        $last = $this->lastUsed[$hash] ?? 0;
                        // 消极心跳：若空闲超过 60s 或从未记录，则 ping 校验
                        if (time() - $last < 60 || $redis->ping()) {
                            return $redis;
                        }
                        // 校验失败，减少当前计数
                        $this->currentSize--;
                    } catch (\RedisException $e) {
                        $this->currentSize--;
                    }
                }
            }
        } else {
            while (!empty($this->pool)) {
                $redis = array_pop($this->pool);
                try {
                    $hash = spl_object_hash($redis);
                    $last = $this->lastUsed[$hash] ?? 0;
                    if (time() - $last < 60 || $redis->ping()) {
                        return $redis;
                    }
                    $this->currentSize--;
                } catch (\RedisException $e) {
                    $this->currentSize--;
                }
            }
        }

        // 2. 池空且未超限，新建连接
        if ($this->currentSize < $this->maxSize) {
            $redis = $this->createConnection();
            $this->currentSize++;
            return $redis;
        }

        // 3. 达到上限，如果是协程则阻塞等待，FPM 则抛异常
        if ($isCoroutine) {
            /** @noinspection PhpUndefinedMethodInspection */
            $redis = $this->getChannel()->pop($this->config['timeout'] ?? 1.0);
            if ($redis instanceof \Redis) return $redis;
        }

        throw new \RuntimeException("Redis 连接池已满（maxSize={$this->maxSize}）");
    }

    /**
     * 创建原生 Redis 连接
     */
    private function createConnection(): \Redis
    {
        $redis = new \Redis();
        $host       = $this->config['host'] ?? '127.0.0.1';
        $port       = (int)($this->config['port'] ?? 6379);
        $timeout    = (float)($this->config['timeout'] ?? 1.0);
        $persistent = $this->config['persistent_id'] ?? null;
        try {
            if ($persistent) {
                $redis->pconnect($host, $port, $timeout, $persistent);
            } else {
                $redis->connect($host, $port, $timeout);
            }
            if (!empty($this->config['password'])) {
                $redis->auth($this->config['password']);
            }
            if (!empty($this->config['dbname'])) {
                $redis->select((int)$this->config['dbname']);
            }
        } catch (\RedisException $e) {
            throw new \RuntimeException("Redis 连接失败: {$host}:{$port}, 错误: " . $e->getMessage());
        }
        return $redis;
    }

    /**
     * 获取协程通道
     * @return object 实际为 \Swoole\Coroutine\Channel
     */
    private function getChannel(): object
    {
        $key = spl_object_hash($this);
        if (!isset(self::$channels[$key])) {
            $channelClass = "\\Swoole\\Coroutine\\Channel";
            self::$channels[$key] = new $channelClass($this->maxSize);
        }
        return self::$channels[$key];
    }

    /**
     * 标记连接可疑（如操作失败时调用），强制下次获取时进行心跳检测
     */
    public function markSuspect(\Redis $redis): void
    {
        $this->lastUsed[spl_object_hash($redis)] = 0;
    }

    public function releaseConnection(\Redis $redis): void
    {
        $this->lastUsed[spl_object_hash($redis)] = time();

        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            /** @noinspection PhpUndefinedMethodInspection */
            $this->getChannel()->push($redis);
        } else {
            if (count($this->pool) < $this->maxSize) {
                $this->pool[] = $redis;
            } else {
                try {
                    $redis->close();
                } catch (\Throwable $_) {
                }
                $this->currentSize = max(0, $this->currentSize - 1);
            }
        }
    }

    /**
     * 关闭所有空闲连接并清空池
     */
    public function closeAll(): void
    {
        // 1. 清理 FPM 模式下的连接
        foreach ($this->pool as $redis) {
            try {
                $redis->close();
            } catch (\Throwable $_) {
            }
        }
        $this->pool = [];

        // 2. 清理协程模式下的连接
        $channel = $this->getChannel();
        /** @noinspection PhpUndefinedMethodInspection */
        while (!$channel->isEmpty()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $redis = $channel->pop(0.001);
            if ($redis instanceof \Redis) {
                try {
                    $redis->close();
                } catch (\Throwable $_) {
                }
            }
        }

        $this->currentSize = 0;
        $this->lastUsed = [];
    }
}
