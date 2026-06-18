<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Pool;

/**
 * MemcachedPool：支持协程与 FPM 的 Memcached 连接池
 */
class MemcachedPool
{
    /** @var object[]|array[] 内部连接存储 */
    private $pool = [];

    /** @var object[] 协程通道池缓存 */
    private static $channels = [];

    /** @var int 当前已创建连接数 */
    private $currentSize = 0;

    /** @var array 记录连接最后活跃时间 */
    private $lastUsed = [];

    /** @var int 最大连接数上限 */
    private $maxSize;

    /** @var array 连接配置 */
    private $config;

    /** @var array<string,self> 多例实例 */
    private static $instances = [];

    /**
     * 私有构造
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
     * @param array $config   如 ['servers'=>[['host'=>'127.0.0.1','port'=>11211],...], 'persistent_id'=>'myPool']
     * @param int   $maxSize
     * @return self
     */
    public static function getInstance(array $config, int $maxSize = 10): self
    {
        // 基于 servers+persistent_id+size 做实例分桶
        $key = md5(json_encode([
            'servers' => $config['servers'] ?? [],
            'pid'     => $config['persistent_id'] ?? 'memcached_pool',
            'size'    => $maxSize,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config, $maxSize);
        }
        return self::$instances[$key];
    }

    public function getConnection(): \Memcached
    {
        $coroClass = "\\Swoole\\Coroutine";
        $isCoroutine = \extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0;

        // 1. 尝试从池中获取
        if ($isCoroutine) {
            $channel = $this->getChannel();
            /** @noinspection PhpUndefinedMethodInspection */
            if (!$channel->isEmpty()) {
                /** @noinspection PhpUndefinedMethodInspection */
                $mc = $channel->pop(0.001);
                if ($mc instanceof \Memcached) {
                    $hash = spl_object_hash($mc);
                    $last = $this->lastUsed[$hash] ?? 0;
                    // 消极心跳：若空闲超过 60s 或从未记录，则验证状态
                    if (time() - $last < 60 || ($mc->getVersion() && $mc->getResultCode() === \Memcached::RES_SUCCESS)) {
                        return $mc;
                    }
                    $this->currentSize--;
                }
            }
        } else {
            while (!empty($this->pool)) {
                $mc = array_pop($this->pool);
                $hash = spl_object_hash($mc);
                $last = $this->lastUsed[$hash] ?? 0;
                // 验证空闲连接状态（消极心跳）
                if (time() - $last < 60 || ($mc->getVersion() && $mc->getResultCode() === \Memcached::RES_SUCCESS)) {
                    return $mc;
                }
                $this->currentSize--;
            }
        }

        // 2. 池空且未超限，新建连接
        if ($this->currentSize < $this->maxSize) {
            $mc = $this->createConnection();
            $this->currentSize++;
            return $mc;
        }

        // 3. 达到上限，如果是协程则阻塞等待，FPM 则抛异常
        if ($isCoroutine) {
            /** @noinspection PhpUndefinedMethodInspection */
            $mc = $this->getChannel()->pop($this->config['timeout'] ?? 1.0);
            if ($mc instanceof \Memcached) return $mc;
        }

        throw new \RuntimeException("Memcached 连接池已满（maxSize={$this->maxSize}）");
    }

    /**
     * 创建原生 Memcached 连接
     */
    private function createConnection(): \Memcached
    {
        $persistentId = $this->config['persistent_id'] ?? 'memcached_pool';
        $mc = new \Memcached($persistentId);
        $mc->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $mc->setOption(\Memcached::OPT_NO_BLOCK, false);
        $mc->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 1000);

        // 仅在第一次实例化时设置服务器列表
        if (count($mc->getServerList()) === 0) {
            $servers = $this->config['servers'] ?? [
                ['host' => '127.0.0.1', 'port' => 11211]
            ];
            $serverList = [];
            foreach ($servers as $sv) {
                $serverList[] = [$sv['host'], $sv['port']];
            }
            if (!$mc->addServers($serverList)) {
                throw new \RuntimeException("Memcached 添加服务器失败");
            }
        }
        return $mc;
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
     * 标记连接可疑，下次获取时强制校验状态
     */
    public function markSuspect(\Memcached $mc): void
    {
        $this->lastUsed[spl_object_hash($mc)] = 0;
    }

    public function releaseConnection(\Memcached $mc): void
    {
        $this->lastUsed[spl_object_hash($mc)] = time();

        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            /** @noinspection PhpUndefinedMethodInspection */
            $this->getChannel()->push($mc);
        } else {
            // 用空闲队列上限判断
            if (count($this->pool) < $this->maxSize) {
                $this->pool[] = $mc;
            } else {
                // 直接丢弃引用
                $mc = null;
                $this->currentSize = max(0, $this->currentSize - 1);
            }
        }
    }

    /**
     * 关闭所有空闲连接并清空池
     */
    public function closeAll(): void
    {
        // 1. 清理 FPM 池
        $this->pool = [];

        // 2. 清理协程通道
        $channel = $this->getChannel();
        /** @noinspection PhpUndefinedMethodInspection */
        while (!$channel->isEmpty()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $mc = $channel->pop(0.001);
            if ($mc instanceof \Memcached) {
                // Memcached 扩展通常没有显式的 close()，直接丢弃引用即可
                $mc = null;
            }
        }

        $this->currentSize = 0;
        $this->lastUsed = [];
    }
}
