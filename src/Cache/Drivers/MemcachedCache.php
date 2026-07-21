<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Drivers;

use Memcached;
use Redis as PhpRedis;
use Framework\Exception\Infra\ConfigException;

/**
 * Class MemcachedCache
 *
 * - 基于 Memcached 扩展实现缓存接口（PHP 7.2+ 兼容）
 * - 分布式锁支持：
 *     1) 当 `lock_servers` 配置 >= 3 时，使用 Redis RedLock 算法
 *     2) 当 `lock_servers` 配置为 1 或 2 时，使用单节点 Redis 简易锁
 *     3) 当未配置 Redis 时，退回到 Memcached 自身的 add-based 简易锁
 *
 * 配置示例：
 * [
 *   'host'         => '127.0.0.1',    // Memcached 主机
 *   'port'         => 11211,          // Memcached 端口
 *   'cachepre'     => 'app1:',        // 键名前缀，可选
 *   'lock_servers' => [               // 可选，最多 N 台 Redis 节点
 *       ['host'=>'127.0.0.1','port'=>6379,'timeout'=>1.0,'dbname'=>0,'password'=>'','persistent_id'=>'app_redis','cachepre'=>'well:redis_','pool_size'=>3],
 *       ['host'=>'127.0.0.1','port'=>6380,'timeout'=>1.0,'dbname'=>0,'password'=>'','persistent_id'=>'app_redis','cachepre'=>'well:redis_','pool_size'=>3],
 *       ['host'=>'127.0.0.1','port'=>6381,'timeout'=>1.0,'dbname'=>0,'password'=>'','persistent_id'=>'app_redis','cachepre'=>'well:redis_','pool_size'=>3],
 *   ],
 * ]
 *
 * 锁行为：
 * - 若 lock_servers >= 3：RedLock 分布式锁；
 * - 若 lock_servers === 1 或 2：使用单节点 Redis 简易锁 (SET NX PX)；
 * - 若未配置 lock_servers：使用 Memcached::add 实现最简易锁；
 */
class MemcachedCache implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var Memcached|null */
    private $mc;

    /** @var string 缓存 key 前缀 */
    private $prefix;

    /** @var PhpRedis[]|null RedLock 多节点原生 Redis 连接 */
    private $redlockConns = null;

    /** @var PhpRedis|null 单节点原生 Redis 连接（简易锁） */
    private $redisSingle = null;

    /** @var bool 标记是否使用 RedLock */
    private $useRedlock = false;

    /** @var bool 标记是否使用单节点 Redis 锁 */
    private $useRedisLock = false;

    /** @var array */
    private $lockRetryTimes;
    private $lockRetryDelayBase;

    /** @var callable|null 从连接池借还 Memcached：function(callable $runner){ return $runner(Memcached $mc);} */
    private $withConn = null;

    /**
     * 构造：初始化 Memcached，并根据 lock_servers 决定锁方式
     *
     * @param array          $cacheConfig
     *   - host/port/cachepre/persistent_id
     *   - lock_servers: [['host'=>'','port'=>, 'password'?(可选),'dbname'?(可选), 'timeout'?(可选)]...]
     *   - lock_retry_times / lock_retry_delay_base(ms)
     * @param null|callable  $withConn  注入连接池闭包（有则不自建 Memcached）
     * @throws Exception
     */
    public function __construct(array $cacheConfig, ?callable $withConn = null)
    {
        $this->withConn = $withConn;

        if (!extension_loaded('memcached')) {
            throw new ConfigException("MemcachedCache 初始化失败：未检测到 memcached 扩展");
        }
        $host = $cacheConfig['host'] ?? '127.0.0.1';
        $port = $cacheConfig['port'] ?? 11211;
        $this->prefix = $cacheConfig['cachepre'] ?? '';

        // 仅在无连接池注入时自建 Memcached
        if ($this->withConn === null) {
            $persistentId = $cacheConfig['persistent_id'] ?? 'memcached_pool';
            $this->mc = new Memcached($persistentId);
            $this->mc->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            $this->mc->setOption(Memcached::OPT_NO_BLOCK, false);
            $this->mc->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);
            if (count($this->mc->getServerList()) === 0) {
                if ($this->mc->addServer($host, $port) === false) {
                    throw new ConfigException("MemcachedCache 初始化失败：无法连接 Memcached {$host}:{$port}");
                }
            }
        }

        // 检查 lock_servers 配置（使用原生 \Redis 连接，保证 RedLock 同 token 能力）
        if (!empty($cacheConfig['lock_servers']) && is_array($cacheConfig['lock_servers'])) {
            $servers = $cacheConfig['lock_servers'];
            $count = count($servers);
            if ($count >= 3) {
                if (!extension_loaded('redis')) {
                    throw new ConfigException("要使用 RedLock，需安装 phpredis 扩展");
                }
                $this->useRedlock = true;
                $this->redlockConns = [];
                foreach ($servers as $idx => $sv) {
                    if (empty($sv['host']) || empty($sv['port'])) {
                        throw new ConfigException("lock_servers[{$idx}] 缺少 host 或 port 配置");
                    }
                    $this->redlockConns[] = $this->buildRedisRaw($sv);
                }
            } elseif ($count >= 1) {
                if (!extension_loaded('redis')) {
                    throw new ConfigException("要使用 Redis 简易锁，需安装 phpredis 扩展");
                }
                $this->useRedisLock = true;
                $sv = $servers[0];
                if (empty($sv['host']) || empty($sv['port'])) {
                    throw new ConfigException("lock_servers[0] 缺少 host 或 port 配置");
                }
                $this->redisSingle = $this->buildRedisRaw($sv);
            }
        }

        // 默认重试次数与退避参数
        $this->lockRetryTimes     = (int)($cacheConfig['lock_retry_times'] ?? 3);
        $this->lockRetryDelayBase = (int)($cacheConfig['lock_retry_delay_base'] ?? 50); // ms
    }


    public function __destruct() {
        // 仅当我们自建了原生 redis 连接时尝试关闭
        if ($this->useRedlock && is_array($this->redlockConns)) {
            foreach ($this->redlockConns as $conn) {
                try {
                    $conn->close();
                } catch (\Throwable $_) {
                }
            }
        }
        if ($this->useRedisLock && $this->redisSingle instanceof PhpRedis) {
            try {
                $this->redisSingle->close();
            } catch (\Throwable $_) {
            }
        }
        // Memcached：只有自建时我们才持有；连接池模式不关闭
        if ($this->withConn === null && $this->mc instanceof Memcached) {
            try {
                $this->mc->quit();
            } catch (\Throwable $_) {
            }
        }
    }

    /** 构建原生 \Redis 连接（支持 password/dbname/timeout） */
    private function buildRedisRaw(array $cfg): PhpRedis
    {
        $r = new PhpRedis();
        $host    = $cfg['host'] ?? '127.0.0.1';
        $port    = (int)($cfg['port'] ?? 6379);
        $timeout = (float)($cfg['timeout'] ?? 1.0);
        if (!empty($cfg['persistent_id'])) {
            $r->pconnect($host, $port, $timeout, $cfg['persistent_id']);
        } else {
            $r->connect($host, $port, $timeout);
        }

        if (!empty($cfg['password'])) {
            $r->auth($cfg['password']);
        }

        if (!empty($cfg['dbname'])) {
            $r->select((int)$cfg['dbname']);
        }

        return $r;
    }

    public function getCacheKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    public function withPrefix(string $key): string
    {
        return $this->getCacheKey($this->prefix . $key);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** 统一执行：连接池 or 直连，并支持一次自动重试断线 */
    private function run(callable $runner)
    {
        $attempts = 0;
        while (true) {
            try {
                if ($this->withConn) {
                    return ($this->withConn)($runner);
                }
                return $runner($this->mc);
            } catch (\Throwable $e) {
                $attempts++;
                $msg = $e->getMessage();
                $resCode = ($this->withConn === null && $this->mc instanceof \Memcached) ? $this->mc->getResultCode() : 0;

                // 如果是连接断开相关错误，且是第一次尝试，则重试
                if ($attempts < 2 && (
                    $resCode === \Memcached::RES_CONNECTION_FAILURE ||
                    $resCode === \Memcached::RES_SERVER_ERROR ||
                    strpos($msg, 'lost') !== false ||
                    strpos($msg, 'failure') !== false
                )) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * 一、缓存基础方法 （Memcached）
     *
     ** @param null $default
     *
     ** @return array
     **/
    public function get(string $key, $default = null)
    {
        return $this->run(function ($mc) use ($key, $default) {
            $fullKey = $this->withPrefix($key);
            $val = $mc->get($fullKey);
            if ($val === false && $mc->getResultCode() === Memcached::RES_NOTFOUND) {
                return $default;
            }
            return $val;
        });
    }

    public function set(string $key, $value, int $ttl = 0): bool
    {
        return $this->run(function ($mc) use ($key, $value, $ttl) {
            return $mc->set($this->withPrefix($key), $value, $ttl);
        });
    }

    public function delete(string $key): bool
    {
        return $this->run(function ($mc) use ($key) {
            return $mc->delete($this->withPrefix($key));
        });
    }

    // 原子自增
    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        return $this->run(function ($mc) use ($key, $step, $ttl) {
            $full = $this->withPrefix($key);
            $new  = $mc->increment($full, $step);
            if ($new === false) {
                $mc->add($full, $step, $ttl ?: 0);
                return $step;
            }
            if ($ttl > 0) {
                $mc->touch($full, $ttl);
            }
            return $new;
        });
    }

    // 原子自减
    public function decrement(string $key, int $step = 1, int $ttl = 0)
    {
        return $this->run(function ($mc) use ($key, $step, $ttl) {
            $full = $this->withPrefix($key);
            $new  = $mc->decrement($full, $step);
            if ($new === false) {
                $mc->add($full, 0, $ttl ?: 0);
                return 0;
            }
            return $new;
        });
    }

    public function replace(string $key, $value, int $ttl = 0): bool
    {
        return $this->run(function ($mc) use ($key, $value, $ttl) {
            return $mc->replace($this->withPrefix($key), $value, $ttl);
        });
    }

    public function add(string $key, $value, int $ttl = 0): bool
    {
        return $this->run(function ($mc) use ($key, $value, $ttl) {
            return $mc->add($this->withPrefix($key), $value, $ttl);
        });
    }

    public function append(string $key, string $suffix): bool
    {
        return $this->run(function ($mc) use ($key, $suffix) {
            return $mc->append($this->withPrefix($key), $suffix);
        });
    }

    public function prepend(string $key, string $prefixStr): bool
    {
        return $this->run(function ($mc) use ($key, $prefixStr) {
            return $mc->prepend($this->withPrefix($key), $prefixStr);
        });
    }

    public function touch(string $key, int $ttl): bool
    {
        return $this->run(function ($mc) use ($key, $ttl) {
            return $mc->touch($this->withPrefix($key), $ttl);
        });
    }

    /**
     * 二、批量操作
     *
     ** @param null $default
     **/
    public function getMulti(array $keys, $default = null): array
    {
        return $this->run(function ($mc) use ($keys, $default) {
            $result = [];
            if (empty($keys)) return $result;
            $fullKeys = array_map([$this, 'withPrefix'], $keys);
            $vals = $mc->getMulti($fullKeys);
            foreach ($keys as $idx => $k) {
                $fk = $fullKeys[$idx];
                $result[$k] = isset($vals[$fk]) ? $vals[$fk] : $default;
            }
            return $result;
        });
    }

    /**
     * @param null $default
     */
    public function getMultiple(array $keys, $default = null): array
    {
        return $this->getMulti($keys, $default);
    }

    public function setMulti(array $items, int $ttl = 0): bool
    {
        return $this->run(function ($mc) use ($items, $ttl) {
            if (empty($items)) return true;
            $prefixed = [];
            foreach ($items as $k => $v) {
                $prefixed[$this->withPrefix($k)] = $v;
            }
            return $mc->setMulti($prefixed, $ttl);
        });
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    public function deleteMulti(array $keys): int
    {
        return $this->run(function ($mc) use ($keys) {
            if (empty($keys)) return 0;
            $fullKeys = array_map([$this, 'withPrefix'], $keys);
            $res = $mc->deleteMulti($fullKeys);
            // Memcached::deleteMulti 返回删除成功的数组或布尔，统计成功数量
            if (is_array($res)) return count($res);
            return $res ? count($keys) : 0;
        });
    }

    public function clear(): bool
    {
        return $this->run(function ($mc) {
            return $mc->flush();
        });
    }

    /**
     * 三、键相关
     **/
    public function exists(string $key): bool
    {
        return $this->run(function ($mc) use ($key) {
            $val = $mc->get($this->withPrefix($key));
            return !($val === false && $mc->getResultCode() === Memcached::RES_NOTFOUND);
        });
    }

    public function stats(): ?array
    {
        return $this->run(function ($mc) {
            $st = $mc->getStats();
            return is_array($st) ? $st : null;
        });
    }

    public function version(): ?array
    {
        return $this->run(function ($mc) {
            $ver = $mc->getVersion();
            return is_array($ver) ? $ver : null;
        });
    }

    /**
     * 四、锁相关
     **/

    /**
     * 获取锁：
     * - 若 useRedlock 为 true，使用 RedLock 算法
     * - 若 useRedisLock 为 true，使用单节点 Redis 简易锁 (SET NX PX)
     * - 否则使用 Memcached add-based 最简易锁
     *
     * @param string $key
     * @param int    $expire  过期时间 (秒)
     * @return string|null    返回随机 token（16 bytes hex）或 null
     * @throws Exception
     */
    public function lock(string $key, int $expire = 3)
    {
        $resource = $this->withPrefix("lock_{$key}");
        $ttlMs    = $expire * 1000;
        $drift   = (int)($ttlMs * 0.01) + 2; // 1% + 2ms
        $deadline = microtime(true) + $expire;

        $attempt = 0;
        $token   = bin2hex(random_bytes(16));

        if ($this->useRedlock && is_array($this->redlockConns)) {
            $quorum = (int)(floor(count($this->redlockConns) / 2) + 1);
            while ($attempt < $this->lockRetryTimes && microtime(true) < $deadline) {
                $nSuccess = 0;
                $startMs  = (int)round(microtime(true) * 1000);

                foreach ($this->redlockConns as $conn) {
                    try {
                        // 同 token、毫秒级过期
                        $ok = $conn->set($resource, $token, ['nx', 'px' => $ttlMs]);
                    } catch (\RedisException $e) {
                        $ok = false;
                    }
                    if ($ok) {
                        $nSuccess++;
                    }
                }

                $elapsed = (int)round(microtime(true) * 1000) - $startMs;
                if ($nSuccess >= $quorum && $elapsed < ($ttlMs - $drift)) {
                    return $token;
                }

                // 回滚已上的锁
                $this->redlockDelIfMatch($resource, $token);

                // 指数退避 + 抖动（上限 100ms）
                $sleepMs = min(100, $this->lockRetryDelayBase * max(1, min($attempt, 3)));
                usleep($sleepMs * 1000);
                $attempt++;
            }
            return null;
        }

        if ($this->useRedisLock && $this->redisSingle instanceof PhpRedis) {
            while ($attempt < $this->lockRetryTimes && microtime(true) < $deadline) {
                try {
                    $ok = $this->redisSingle->set($resource, $token, ['nx', 'px' => $ttlMs]);
                } catch (\RedisException $e) {
                    $ok = false;
                }
                if ($ok) return $token;

                $sleepMs = min(100, $this->lockRetryDelayBase * max(1, min($attempt, 3)));
                usleep($sleepMs * 1000);
                $attempt++;
            }
            return null;
        }

        // 最简：Memcached add 锁
        return $this->run(function ($mc) use ($resource, $expire, $token) {
            $attempt = 0;
            while ($attempt < $this->lockRetryTimes) {
                if ($mc->add($resource, $token, $expire)) return $token;
                $resCode = $mc->getResultCode();
                if ($resCode === Memcached::RES_NOTFOUND) {
                    $attempt++;
                    continue;
                }
                $sleepMs = min(100, $this->lockRetryDelayBase * max(1, min($attempt, 3)));
                usleep($sleepMs * 1000);
                $attempt++;
            }
            return null;
        });
    }

    /**
     * 释放锁：
     * - 若 useRedlock，尝试在所有节点删除
     * - 若 useRedisLock，从单节点删除
     * - 否则从 Memcached 删除
     *
     * @param string $key
     * @param string $token
     * @return bool
     * @throws Exception
     */
    public function unlock(string $key, string $token)
    {
        $resource = $this->withPrefix("lock_{$key}");

        if ($this->useRedlock && is_array($this->redlockConns)) {
            // 任一节点成功删除即视为解锁成功
            $ok = false;
            foreach ($this->redlockConns as $conn) {
                try {
                    $deleted = $this->luaCompareDel($conn, $resource, $token);
                    $ok = $ok || $deleted;
                } catch (\Throwable $_) {
                }
            }
            return $ok;
        }

        if ($this->useRedisLock && $this->redisSingle instanceof PhpRedis) {
            try {
                return $this->luaCompareDel($this->redisSingle, $resource, $token);
            } catch (\Throwable $_) {
                return false;
            }
        }

        // Memcached 简易锁解锁
        return $this->run(function ($mc) use ($resource, $token) {
            $stored = $mc->get($resource);
            if ($mc->getResultCode() === Memcached::RES_NOTFOUND) return false;
            if ($stored === $token) return (bool)$mc->delete($resource);
            return false;
        });
    }

    /**
     * 判断锁是否存在：
     * - RedLock：只要任意节点上 key 存在，就认为锁存在
     * - 单节点 Redis：单节点 exists
     * - Memcached：getResultCode 判断
     *
     * @param string $key
     * @return bool
     * @throws Exception
     */
    public function isLocked(string $key)
    {
        $resource = $this->withPrefix("lock_{$key}");

        if ($this->useRedlock && is_array($this->redlockConns)) {
            foreach ($this->redlockConns as $conn) {
                try {
                    if ($conn->exists($resource)) return true;
                } catch (\Throwable $_) {
                }
            }
            return false;
        }

        if ($this->useRedisLock && $this->redisSingle instanceof PhpRedis) {
            try {
                return $this->redisSingle->exists($resource) > 0;
            } catch (\Throwable $_) {
                return false;
            }
        }

        return $this->run(function ($mc) use ($resource) {
            $val = $mc->get($resource);
            return !($val === false && $mc->getResultCode() === Memcached::RES_NOTFOUND);
        });
    }

    /** RedLock 辅助：比较后删除（Lua） */
    private function luaCompareDel($r, string $key, string $token): bool
    {
        $lua = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
        $res = (int)$r->eval($lua, [$key, $token], 1);
        return $res === 1;
    }

    /** RedLock 辅助：遍历节点删除匹配 token 的锁 */
    private function redlockDelIfMatch(string $key, string $token): void
    {
        foreach ($this->redlockConns as $conn) {
            try {
                $this->luaCompareDel($conn, $key, $token);
            } catch (\Throwable $_) {
            }
        }
    }

    /**
     * RedLock 辅助：在单个 Redis 节点上解锁 (仅当 token 一致时删除，内部调用，不对外暴露)
     *
     * @param Redis  $redis
     * @param string $resource
     * @param string $token
     * @return bool
     */
    /* protected function unlockSingle(Redis $redis, string $key, string $token): bool
    {
        try {
            $res = $redis->unlock($key, $token);
        } catch (\RedisException $e) {
            return false;
        }
        return ((int)$res) > 0;
    } */

    /**
     * 令牌桶限流
     * @param string $key  维度（ip:1.2.3.4 / UserID:123）
     * @param int    $capacity  桶容量
     * @param int    $rate 每秒补充令牌数
     * @return bool  true=放行，false=限流
     */
    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        $bucketKey = $this->withPrefix('tb:' . $key);
        $ttl = (int)ceil($cap / $rate); // 空桶后失活时间

        return $this->run(function ($mc) use ($bucketKey, $cap, $rate, $ttl) {
            $now = microtime(true);
            $maxTry = 3;
            while ($maxTry--) {
                $ext = $mc->get($bucketKey, null, Memcached::GET_EXTENDED);
                $cas = $ext['cas']   ?? null;
                $val = $ext['value'] ?? null;

                [$tokens, $ts] = ($val && is_array($val) && count($val) === 2) ? $val : [$cap, $now];
                $tokens = min($cap, $tokens + ($now - $ts) * $rate);

                if ($tokens < 1) return false;

                $tokens -= 1;
                $newVal = [$tokens, $now];

                if ($cas === null) {
                    if ($mc->add($bucketKey, $newVal, $ttl)) return true;
                } else {
                    if ($mc->cas($cas, $bucketKey, $newVal, $ttl)) return true;
                }
                // CAS 冲突或并发 add 失败，重试
            }
            return false;
        });
    }

    public function original(string $only = '')
    {
        // 返回底层 Memcached 实例（连接池模式下返回 null 更合适，但保持兼容直接返回 null/对象）
        return $this->withConn ? null : $this->mc;
    }

    use \Framework\Cache\Traits\CacheWithLockTrait;
}

/* $cacheConfig = [
    'host'         => '127.0.0.1',
    'port'         => 11211,
    'cachepre'     => 'app1:',
    'lock_servers' => [
        ['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1.0],
        ['host' => '127.0.0.1', 'port' => 6380, 'timeout' => 1.0],
        ['host' => '127.0.0.1', 'port' => 6381, 'timeout' => 1.0],
    ],
];

try {
    $cache = new \App\Cache\MemcachedCache($cacheConfig);
} catch (\Exception $e) {
    die("初始化失败: {$e->getMessage()}");
}

// —— 常规缓存操作 ——
$cache->set('foo', 'bar', 600);
echo $cache->get('foo', '默认值'); // bar
$cache->increment('counter', 1, 60);
$cache->append('foo', '_suffix');
$cache->touch('foo', 300);
$vals = $cache->getMulti(['foo', 'counter', 'nonexistent'], null);
var_dump($vals); // ['foo'=>'bar_suffix', 'counter'=>1, 'nonexistent'=>null]
$cache->delete('foo');
$cache->deleteMulti(['counter']);

// —— 查询缓存服务状态 ——
var_dump($cache->stats());
var_dump($cache->version());

// —— RedLock 分布式锁 ——
try {
    $token = $cache->lock('order:123', 5); // 5 秒后自动过期
    if ($token !== null) {
        // 拿到锁，在此处做“处理订单”的关键业务
        // ……
        // 释放锁
        $cache->unlock('order:123', $token);
    } else {
        echo "获取锁失败，可能被其他进程占用\n";
    }
} catch (\Exception $e) {
    echo "锁操作异常: " . $e->getMessage();
}

// —— 查看锁是否存在（仅作参考） ——
try {
    $locked = $cache->isLocked('order:123');
    var_dump($locked);
} catch (\Exception $e) {
    echo "isLocked 异常: " . $e->getMessage();
}
 */
