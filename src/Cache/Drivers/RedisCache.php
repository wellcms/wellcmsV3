<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Drivers;

use Redis;
use RedisException;

/**
 * Class RedisCache
 *
 * 一个兼容 PHP 7.2+ 的 Redis 封装，包含常见 Redis 数据结构与操作：String、Hash、List、Set、Sorted Set、Bitmap，
 * 事务 (WATCH/MULTI/EXEC)、发布/订阅、过期、key 相关操作等。
 */
class RedisCache implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var Redis|null 拥有型连接（兼容旧版）。使用连接池时为 null */
    private $redis;
    /** @var string */
    private $prefix;
    /** @var callable|null 借还连接闭包：function(callable $runner{ return $runner(\$r); }) */
    private $withConn = null;
    /** @var array */
    private $config;
    private /** @var array */
    static $sha = []; // 本地 SHA 缓存

    /* ① 比较后删除（解锁） */
    private const S_COMPARE_DEL = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /* ② INCR / DECR + 首次 EXPIRE  (delta 可正可负) */
    private const S_ADJ_EXPIRE = <<<'LUA'
local v = redis.call('INCRBY', KEYS[1], ARGV[1])
if tonumber(ARGV[2]) > 0 and redis.call('TTL', KEYS[1]) < 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return v
LUA;

    /* ③ 令牌桶 */
    private const S_TOKEN_BUCKET = <<<'LUA'
local key,now,cap,rate = KEYS[1],tonumber(ARGV[1]),tonumber(ARGV[2]),tonumber(ARGV[3])
local d = redis.call('HMGET', key,'tokens','ts')
local tk = tonumber(d[1]) or cap
local ts = tonumber(d[2]) or now
tk = math.min(cap, tk + (now - ts)*rate/1000)
if tk < 1 then return 0 end
tk = tk - 1
redis.call('HMSET', key,'tokens',tk,'ts',now)
redis.call('PEXPIRE', key, math.ceil(cap/rate*1000))
return 1
LUA;

    /**
     * RedisCache constructor.
     *
     * @param array         $cacheConfig
     * @param null|callable $withConn    [MOD] 可选：连接池注入闭包。传入则不自建连接。
     *   - host / port / timeout / password / dbname / cachepre / persistent_id
     */
    public function __construct(array $cacheConfig, ?callable $withConn = null)
    {
        // [Fix] 兼容两种配置结构：
        // 1. 直接传入 Redis 驱动配置: ['cachepre' => 'xxx', 'host' => ...]
        // 2. 传入整个 cache.php 配置: ['stores' => ['redis' => ['cachepre' => 'xxx']]]
        // 容器自动解析时注入的是整个 cacheConfig，而 CacheManager 传入的是 stores['redis']
        $driverConfig = isset($cacheConfig['stores']) && is_array($cacheConfig['stores'])
            ? ($cacheConfig['stores']['redis'] ?? [])
            : $cacheConfig;

        $this->config   = $driverConfig;
        $this->prefix   = $driverConfig['cachepre'] ?? '';
        $this->withConn = $withConn;

        if ($this->withConn === null) {
            $this->initConnection();
        }
    }

    /**
     * [Fix] 强制重连：特别用于 pcntl_fork 后的子进程，清除父进程继承的 Socket
     */
    public function reconnect(): void
    {
        if ($this->withConn !== null) return;

        if ($this->redis) {
            try {
                @$this->redis->close();
            } catch (\Throwable $_) {
            }
            $this->redis = null;
        }

        $this->initConnection();
    }

    private function initConnection(): void
    {
        if ($this->withConn !== null) return;

        $host     = $this->config['host']     ?? '127.0.0.1';
        $port     = $this->config['port']     ?? 6379;
        $timeout  = $this->config['timeout']  ?? 1.0;
        $password = $this->config['password'] ?? null;
        $dbname   = $this->config['dbname']   ?? null;

        try {
            $this->redis = new Redis();
            if (!empty($this->config['persistent_id'])) {
                $this->redis->pconnect($host, $port, $timeout, $this->config['persistent_id']);
            } else {
                $this->redis->connect($host, $port, $timeout);
            }
        } catch (RedisException $e) {
            throw new \RuntimeException("Redis 连接失败: {$host}:{$port}, 错误: " . $e->getMessage());
        }

        if (!empty($password)) {
            try {
                if (!$this->redis->auth($password)) {
                    throw new RedisException("Redis 认证失败");
                }
            } catch (RedisException $e) {
                throw new \RuntimeException("Redis 认证失败: " . $e->getMessage());
            }
        }

        if (!empty($dbname)) {
            $this->redis->select((int)$dbname);
        }
    }

    public function __destruct() {
        // 仅在“自建连接”模式下关闭；使用连接池时不关闭
        if ($this->withConn === null && $this->redis) {
            try {
                $this->redis->close();
            } catch (\Throwable $_) {
            }
        }
    }


    /**
     * 通用辅助方法
     **/

    /**
     * 获取经过前缀和哈希处理后的真实 Key
     * 优化 (Stage 5): 如果总长度超过 32 字符，仅对 key 部分进行哈希，保留前缀。
     * 这样可以确保 Redis 的 SCAN 指令配合前缀模式仍然能查找到这些键，从而让 clear() 操作生效。
     */
    public function withPrefix(string $key): string
    {
        $fullKey = $this->prefix . $key;
        if (strlen($fullKey) > 32) {
            // 保留前缀，仅对 key md5，以支持前缀清理
            return $this->prefix . md5($key);
        }
        return $fullKey;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** 统一执行入口：借还连接（连接池）或直连，并支持一次自动重试断线 */
    private function run(callable $runner)
    {
        $attempts = 0;
        while (true) {
            try {
                if ($this->withConn) {
                    return ($this->withConn)($runner);
                }
                return $runner($this->redis);
            } catch (\RedisException $e) {
                $attempts++;
                $msg = $e->getMessage();
                // 如果是连接断开相关错误，且是第一次尝试，则重试（由连接池保证取到新连接）
                if ($attempts < 2 && (
                    strpos($msg, 'lost') !== false ||
                    strpos($msg, 'read error') !== false ||
                    strpos($msg, 'Socket error') !== false ||
                    strpos($msg, 'went away') !== false
                )) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * 检测 Redis 是否可用
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            return $this->run(function ($r) {
                // RedisCluster
                if ($r instanceof \RedisCluster) {
                    foreach ($r->_masters() as $node) {
                        // 只要不抛异常即可
                        $r->ping($node);
                    }
                    return true;
                }

                // 单机 Redis
                $r->ping();
                return true;
            });
        } catch (\Throwable $e) {
            // 安全兜底：连接异常
            return false;
        }
    }

    /**
     * 获取 Redis 服务器信息
     *
     * @return array
     */
    public function info(): array
    {
        try {
            return $this->run(function ($r) {
                $info = [];

                // RedisCluster
                if ($r instanceof \RedisCluster) {
                    foreach ($r->_masters() as $node) {
                        try {
                            $res = $r->info($node);
                            if (is_array($res)) {
                                $info[$node] = $res;
                            } else {
                                // phpredis <= 5 返回 string，需要解析
                                $info[$node] = $this->parseInfoString($res);
                            }
                        } catch (\Throwable $e) {
                            $info[$node] = ['error' => $e->getMessage()];
                        }
                    }
                    return $info;
                }

                // 单机 Redis
                $res = $r->info();
                if (is_array($res)) {
                    return $res;
                }
                return $this->parseInfoString($res);
            });
        } catch (\Throwable $e) {
            // 安全兜底：连接异常
            return [];
        }
    }

    /**
     * 解析 Redis info 返回的字符串（phpredis <=5）
     */
    protected function parseInfoString(string $str): array
    {
        $result = [];
        foreach (explode("\n", $str) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2) + [null, null];
            if ($key !== null) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 根据传入的值决定是否将其 JSON 编码后返回
     *
     * 仅针对字符串相关操作 (set/get) 中的 value 部分。对于其他数据结构 (Hash、List...)，不做自动编码。
     *
     * @param mixed $value
     * @return string
     */
    public function serializeValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                // 如果 JSON 编码失败（通常因包含二进制、循环引用等），尝试使用 serialize 兜底，或返回空串及日志记录
                // 此处为了保证类型安全且最大程度保留数据，可尝试更宽松的编码或抛出异常
                // 为了兼容生产稳定性，返回 json_encode 失败后的错误状态或空串
                return '';
            }
            return $json;
        }
        // 其他类型直接 cast 成字符串
        return (string)$value;
    }

    /**
     * 将从 Redis 中取回的原始字符串尝试 JSON 解码
     *
     * @param string|false|null $raw
     * @param mixed $default
     * @return mixed
     */
    public function unserializeValue($raw, $default = null)
    {
        if ($raw === false || $raw === null) return $default;

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        return $raw;
    }

    /**
     *  一、Key 相关操作 -
     **/

    /**
     * 检查给定 key 是否存在
     *
     * 对应命令: EXISTS key
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        return $this->run(function ($r) use ($key) {
            return $r->exists($this->withPrefix($key)) > 0;
        });
    }

    /**
     * 删除一个或多个 key，返回删除的 key 数量
     *
     * 对应命令: DEL key [key ...]
     *
     * @param string|array $keys
     * @return int
     */
    public function del($keys): int
    {
        return $this->run(function ($r) use ($keys) {
            if (is_array($keys)) {
                $full = array_map([$this, 'withPrefix'], $keys);
                return $r->del($full);
            }
            return $r->del($this->withPrefix($keys));
        });
    }

    /**
     * 设置 key 的过期时间 (秒)
     *
     * 对应命令: EXPIRE key seconds
     *
     * @param string $key
     * @param int $seconds
     * @return bool
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->run(function ($r) use ($key, $seconds) {
            return $r->expire($this->withPrefix($key), $seconds);
        });
    }

    /**
     * 设置 key 的过期时间 (Unix 时间戳)
     *
     * 对应命令: EXPIREAT key timestamp
     *
     * @param string $key
     * @param int $timestamp
     * @return bool
     */
    public function expireAt(string $key, int $timestamp): bool
    {
        return $this->run(function ($r) use ($key, $timestamp) {
            return $r->expireAt($this->withPrefix($key), $timestamp);
        });
    }

    /**
     * 取消对 key 的过期时间，使其永久保存
     *
     * 对应命令: PERSIST key
     *
     * @param string $key
     * @return bool
     */
    public function persist(string $key): bool
    {
        return $this->run(function ($r) use ($key) {
            return $r->persist($this->withPrefix($key));
        });
    }

    /**
     * 查看 key 的剩余生存时间 (秒)
     *
     * 对应命令: TTL key
     *
     * @param string $key
     * @return int  当 key 不存在时返回 -2，当 key 存在但无过期时间时返回 -1，否则返回剩余秒数
     */
    public function ttl(string $key): int
    {
        return $this->run(function ($r) use ($key) {
            return $r->ttl($this->withPrefix($key));
        });
    }

    /**
     * 重命名 key。如果 newkey 已存在，则覆盖。
     *
     * 对应命令: RENAME key newkey
     *
     * @param string $key
     * @param string $newKey
     * @return bool
     */
    public function rename(string $key, string $newKey): bool
    {
        return $this->run(function ($r) use ($key, $newKey) {
            return $r->rename($this->withPrefix($key), $this->withPrefix($newKey));
        });
    }

    /**
     * 仅当 newkey 不存在时重命名 key
     *
     * 对应命令: RENAMENX key newkey
     *
     * @param string $key
     * @param string $newKey
     * @return bool
     */
    public function renameNx(string $key, string $newKey): bool
    {
        return $this->run(function ($r) use ($key, $newKey) {
            return $r->renamenx($this->withPrefix($key), $this->withPrefix($newKey));
        });
    }

    /**
     * 返回 key 的类型
     *
     * 对应命令: TYPE key
     *
     * @param string $key
     * @return string  可能值: "none"(不存在)、"string"、"list"、"set"、"zset"、"hash" 等
     */
    public function type(string $key): string
    {
        return $this->run(function ($r) use ($key) {
            return $r->type($this->withPrefix($key));
        });
    }

    /**
     * 查找所有匹配给定模式的 key（不推荐在生产环境大规模使用）
     *
     * 对应命令: KEYS pattern
     *
     * @param string $pattern  例如 "*user*"、"prefix:*" 等
     * @return array
     */
    public function keys(string $pattern): array
    {
        return $this->run(function ($r) use ($pattern) {
            return $r->keys($pattern);
        });
    }

    /**
     *  二、String 操作（扩展） -
     **/

    /**
     * 获取缓存，未命中返回 $default。
     * 如果存储时对数组/对象做了 JSON 编码，此处 JSON 解码并返回，否则返回字符串。
     *
     * 对应命令: GET key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->run(function ($r) use ($key, $default) {
            $raw = $r->get($this->withPrefix($key));
            return $this->unserializeValue($raw, $default);
        });
    }

    /**
     * 设置缓存。若 $value 为数组/对象则做 JSON 编码。
     * $ttl > 0 则调用 SETEX，否则 SET。
     *
     * 对应命令: SET key value [EX seconds]
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl  过期时间(秒)，<=0 时表示永久
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        return $this->run(function ($r) use ($key, $value, $ttl) {
            $full = $this->withPrefix($key);
            $to   = $this->serializeValue($value);
            return $ttl > 0 ? $r->setex($full, $ttl, $to) : $r->set($full, $to);
        });
    }

    /**
     * SETEX 别名：Scheduler 工业级组件使用此命名
     *
     * @param string $key
     * @param int    $ttl
     * @param mixed  $value
     * @return bool
     */
    public function setex(string $key, int $ttl, $value): bool
    {
        return $this->set($key, $value, $ttl);
    }

    /**
     * 原子设置缓存（仅当 key 不存在时）。
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl 过期时间(秒)
     * @return bool 成功设置返回 true，已存在返回 false
     */
    public function setNx(string $key, $value, int $ttl = 0): bool
    {
        return $this->run(function ($r) use ($key, $value, $ttl) {
            $full = $this->withPrefix($key);
            $to   = $this->serializeValue($value);
            $options = ['nx'];
            if ($ttl > 0) $options['ex'] = $ttl;

            return (bool)$r->set($full, $to, $options);
        });
    }

    /**
     * 删除单个缓存键。
     *
     * 对应命令: DEL key
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->run(function ($r) use ($key) {
            return $r->del($this->withPrefix($key)) > 0;
        });
    }

    /* 辅助：加载 + NOSCRIPT 自愈 */
    public function call(string $lua, array $argv, int $numKeys)
    {
        return $this->run(function ($r) use ($lua, $argv, $numKeys) {
            $tag = md5($lua);
            if (!isset(self::$sha[$tag])) self::$sha[$tag] = $r->script('load', $lua);
            try {
                return $r->evalSha(self::$sha[$tag], $argv, $numKeys);
            } catch (RedisException $e) {
                if (strpos($e->getMessage(), 'NOSCRIPT') === 0) {
                    self::$sha[$tag] = $r->script('load', $lua);
                    return $r->evalSha(self::$sha[$tag], $argv, $numKeys);
                }
                throw $e;
            }
        });
    }

    /**
     * 原子自增。若 $step > 0，则调用 INCRBY，否则调用 INCR。
     * 如果设置了 $ttl，会对键重新设置过期时间。
     * 返回自增后的整数值。
     *
     * 对应命令: INCR key 或 INCRBY key increment
     *
     * @param string $key
     * @param int $step
     * @param int $ttl
     * @return int
     */
    /* public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $fullKey = $this->withPrefix($key);
        if ($ttl > 0) {
            $script = <<<'LUA'
            local key = KEYS[1]
            local step = tonumber(ARGV[1])
            local ttl = tonumber(ARGV[2])
            local newVal = redis.call('INCRBY', key, step)
            redis.call('EXPIRE', key, ttl)
            return newVal
            LUA;
            $sha = $this->redis->script('load', $script);
            $new = $this->redis->evalSha($sha, [$fullKey, $step, $ttl], 1);
            return is_int($new) ? $new : (int)$new;
        }

        return $this->redis->incrBy($fullKey, $step);
    } */

    /**
     * 原子自增。若 $step > 0，则调用 INCRBY，否则调用 INCR。
     * 如果设置了 $ttl，会对键重新设置过期时间。
     * 返回自增后的整数值。
     *
     * 对应命令: INCR key 或 INCRBY key increment
     *
     * @param string $key
     * @param int $step
     * @param int $ttl
     * @return int
     */
    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $full = $this->withPrefix($key);
        return (int)$this->call(self::S_ADJ_EXPIRE, [$full, $step, $ttl], 1);
    }

    /**
     * INCR 别名：Scheduler 工业级组件使用此命名
     *
     * @param string $key
     * @return int
     */
    public function incr(string $key): int
    {
        return $this->increment($key, 1, 0);
    }

    /**
     * 原子自减。若 $step > 0，则调用 DECRBY，否则调用 DECR。
     * 如果设置了 $ttl，会对键重新设置过期时间。
     * 返回自减后的整数值。
     *
     * 对应命令: DECR key 或 DECRBY key decrement
     *
     * @param string $key
     * @param int $step
     * @param int $ttl
     * @return int
     */
    /* public function decrement(string $key, int $step = 1, int $ttl = 0): int
    {
        $fullKey = $this->withPrefix($key);
        if ($ttl > 0) {
            $script = <<<'LUA'
                local newVal = redis.call('DECRBY', KEYS[1], ARGV[1])
                redis.call('EXPIRE', KEYS[1], ARGV[2])
                return newVal
            LUA;
            $sha = $this->redis->script('load', $script);
            $new = $this->redis->evalSha($sha, [$fullKey, $step, $ttl], 1);
            return is_int($new) ? $new : (int)$new;
        }
        return $this->redis->decrBy($fullKey, $step);
    } */
    public function decrement(string $key, int $step = 1, int $ttl = 0): int
    {
        return $this->increment($key, -$step, $ttl);
    }

    public function scan(
        string $pattern = '*',
        int $count = 1000,
        int $limit = 10000
    ): array {
        $keys = [];

        foreach ($this->scanGenerator($pattern, $count, $limit) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }

    public function scanGenerator(
        string $pattern = '*',
        int $count = 1000,
        int $limit = 0
    ): \Generator {
        $yielded = 0;

        // ---------- RedisCluster ----------
        try {
            $isCluster = $this->run(function ($r) {
                return $r instanceof \RedisCluster;
            });
        } catch (\Throwable $_) {
            $isCluster = false;
        }

        if ($isCluster) {
            $masters = $this->run(function ($r) {
                return $r->_masters();
            });
            foreach ($masters as $node) {
                $it = null;
                while (true) {
                    $batch = $this->run(function ($r) use (&$it, $node, $pattern, $count) {
                        return $r->scan($it, $node, $pattern, $count);
                    });
                    if ($batch === false || empty($batch)) break;

                    foreach ($batch as $key) {
                        yield $key;
                        $yielded++;
                        if ($limit > 0 && $yielded >= $limit) return;
                    }
                    if ($it === 0 || $it === null) break;
                }
            }
            return;
        }

        // ---------- 单机 Redis ----------
        $it = null;
        while (true) {
            $batch = $this->run(function ($r) use (&$it, $pattern, $count) {
                return $r->scan($it, $pattern, $count);
            });
            if ($batch === false || empty($batch)) break;

            foreach ($batch as $key) {
                yield $key;
                $yielded++;
                if ($limit > 0 && $yielded >= $limit) return;
            }
            if ($it === 0 || $it === null) break;
        }
    }

    /**
     * 清除所有以当前前缀开头的键。
     * 支持单机与 RedisCluster (Stage 5 优化)
     */
    public function clear(): bool
    {
        $prefix = $this->prefix;
        return $this->run(function ($r) use ($prefix) {
            $pattern = $prefix . '*';

            // 适配集群与单机
            $nodes = ($r instanceof \RedisCluster) ? $r->_masters() : [null];

            foreach ($nodes as $node) {
                $it = null;
                while (true) {
                    $keys = ($node === null)
                        ? $r->scan($it, $pattern, 1000)
                        : $r->scan($it, $node, $pattern, 1000);

                    if ($keys === false) break;

                    if (!empty($keys)) {
                        $r->multi(\Redis::PIPELINE);
                        foreach ($keys as $k) {
                            $r->del($k);
                        }
                        $r->exec();
                    }

                    if ($it == 0) break;
                }
            }
            return true;
        });
    }

    /**
     *  三、Hash 操作 -
     **/

    /**
     * 查看 Hash 中是否存在指定 field
     *
     * 对应命令: HEXISTS key field
     *
     * @param string $key
     * @param string $field
     * @return bool
     */
    public function hExists(string $key, string $field): bool
    {
        return $this->run(function ($r) use ($key, $field) {
            return $r->hExists($this->withPrefix($key), $field);
        });
    }

    /**
     * 从 Hash 中取出指定 field 的值
     *
     * 对应命令: HGET key field
     *
     * @param string $key
     * @param string $field
     * @return string|null  field 不存在时返回 null
     */
    public function hGet(string $key, string $field)
    {
        return $this->run(function ($r) use ($key, $field) {
            $raw = $r->hGet($this->withPrefix($key), $field);
            return $raw === false ? null : $raw;
        });
    }

    /**
     * 向 Hash 中设置一个 field-value 对，如果 field 存在则覆盖
     *
     * 对应命令: HSET key field value
     *
     * @param string $key
     * @param string $field
     * @param mixed $value
     * @return bool  如果 field 是新字段返回 1，否则 0
     */
    public function hSet(string $key, string $field, $value): bool
    {
        return $this->run(function ($r) use ($key, $field, $value) {
            $to = $this->serializeValue($value);
            return $r->hSet($this->withPrefix($key), $field, $to) >= 0;
        });
    }

    /**
     * 批量向 Hash 中设置多个 field-value
     *
     * 对应命令: HMSET key field1 value1 [field2 value2 ...]
     *
     * @param string $key
     * @param array $items  格式 ['field1' => value1, 'field2' => value2, ...]
     * @return bool
     */
    public function hMSet(string $key, array $items): bool
    {
        return $this->run(function ($r) use ($key, $items) {
            $to = [];
            foreach ($items as $f => $v) {
                $to[$f] = $this->serializeValue($v);
            }
            return $r->hMSet($this->withPrefix($key), $to);
        });
    }

    /**
     * 从 Hash 中批量获取多个 field 的值
     *
     * 对应命令: HMGET key field1 [field2 ...]
     *
     * @param string $key
     * @param array $fields
     * @return array  返回一个关联数组 ['field1' => value1, 'field2' => value2, ...]，如果某个 field 不存在则值为 null
     */
    public function hMGet(string $key, array $fields): array
    {
        return $this->run(function ($r) use ($key, $fields) {
            $raws = $r->hMGet($this->withPrefix($key), $fields);
            $res = [];
            foreach ($fields as $f) {
                $raw = $raws[$f] ?? false;
                $res[$f] = ($raw === false ? null : $raw);
            }
            return $res;
        });
    }

    /**
     * 从 Hash 中删除一个或多个 field，返回被删除 field 的数量
     *
     * 对应命令: HDEL key field [field ...]
     *
     * @param string $key
     * @param string|array $fields
     * @return int
     */
    public function hDel(string $key, $fields): int
    {
        return $this->run(function ($r) use ($key, $fields) {
            if (is_array($fields)) {
                return $r->hDel($this->withPrefix($key), ...$fields);
            }
            return $r->hDel($this->withPrefix($key), $fields);
        });
    }

    /**
     * 返回 Hash 中所有的 field 列表
     *
     * 对应命令: HKEYS key
     *
     * @param string $key
     * @return array
     */
    public function hKeys(string $key): array
    {
        return $this->run(function ($r) use ($key) {
            return $r->hKeys($this->withPrefix($key));
        });
    }

    /**
     * 返回 Hash 中所有的 value 列表
     *
     * 对应命令: HVALS key
     *
     * @param string $key
     * @return array
     */
    public function hVals(string $key): array
    {
        return $this->run(function ($r) use ($key) {
            return $r->hVals($this->withPrefix($key));
        });
    }

    /**
     * 返回 Hash 中 field 的数量
     *
     * 对应命令: HLEN key
     *
     * @param string $key
     * @return int
     */
    public function hLen(string $key): int
    {
        return $this->run(function ($r) use ($key) {
            return $r->hLen($this->withPrefix($key));
        });
    }

    /**
     * 返回 Hash 中所有 field => value 的关联数组
     *
     * 对应命令: HGETALL key
     *
     * @param string $key
     * @return array  ['field1' => 'value1', 'field2' => 'value2', ...]
     */
    public function hGetAll(string $key): array
    {
        return $this->run(function ($r) use ($key) {
            return $r->hGetAll($this->withPrefix($key));
        });
    }

    /**
     * 对 Hash 中的指定 field 进行原子增量操作
     *
     * 对应命令: HINCRBY key field increment
     *
     * @param string $key
     * @param string $field
     * @param int $increment
     * @return int  返回执行 HINCRBY 后 field 的新值
     */
    public function hIncrBy(string $key, string $field, int $increment): int
    {
        return $this->run(function ($r) use ($key, $field, $increment) {
            return $r->hIncrBy($this->withPrefix($key), $field, $increment);
        });
    }

    /**
     * 对 Hash 中的指定 field 进行原子浮点增量操作
     *
     * 对应命令: HINCRBYFLOAT key field increment
     *
     * @param string $key
     * @param string $field
     * @param float $increment
     * @return float  返回执行 HINCRBYFLOAT 后 field 的新浮点值
     */
    public function hIncrByFloat(string $key, string $field, float $increment): float
    {
        return $this->run(function ($r) use ($key, $field, $increment) {
            return $r->hIncrByFloat($this->withPrefix($key), $field, $increment);
        });
    }

    /**
     *  四、List 操作 -
     **/

    /**
     * 在 List 左端插入一个或多个值
     *
     * 对应命令: LPUSH key value [value ...]
     *
     * @param string $key
     * @param mixed ...$values  可以传字符串或数字，也可以传数组（自动拆开）
     * @return int  返回 List 的长度
     * @param array $values
     */
    public function lPush(string $key, ...$values): int
    {
        return $this->run(function ($r) use ($key, $values) {
            $full = $this->withPrefix($key);
            if (count($values) === 1 && is_array($values[0])) {
                return $r->lPush($full, ...$values[0]);
            }
            return $r->lPush($full, ...$values);
        });
    }

    /**
     * 在 List 右端插入一个或多个值
     *
     * 对应命令: RPUSH key value [value ...]
     *
     * @param string $key
     * @param mixed ...$values
     * @return int
     * @param array $values
     */
    public function rPush(string $key, ...$values): int
    {
        return $this->run(function ($r) use ($key, $values) {
            $full = $this->withPrefix($key);
            if (count($values) === 1 && is_array($values[0])) {
                return $r->rPush($full, ...$values[0]);
            }
            return $r->rPush($full, ...$values);
        });
    }

    /**
     * 从 List 左端弹出一个元素
     *
     * 对应命令: LPOP key
     *
     * @param string $key
     * @return string|null  如果列表为空返回 null
     */
    public function lPop(string $key)
    {
        return $this->run(function ($r) use ($key) {
            $val = $r->lPop($this->withPrefix($key));
            return $val === false ? null : $val;
        });
    }

    /**
     * 从 List 右端弹出一个元素
     *
     * 对应命令: RPOP key
     *
     * @param string $key
     * @return string|null
     */
    public function rPop(string $key)
    {
        return $this->run(function ($r) use ($key) {
            $val = $r->rPop($this->withPrefix($key));
            return $val === false ? null : $val;
        });
    }

    /**
     * 返回 List 长度
     *
     * 对应命令: LLEN key
     *
     * @param string $key
     * @return int
     */
    public function lLen(string $key): int
    {
        return $this->run(function ($r) use ($key) {
            return $r->lLen($this->withPrefix($key));
        });
    }

    /**
     * 返回 List 指定范围内的元素 (包含 start 和 stop)
     *
     * 对应命令: LRANGE key start stop
     *
     * @param string $key
     * @param int $start
     * @param int $stop
     * @return array
     */
    public function lRange(string $key, int $start, int $stop): array
    {
        return $this->run(function ($r) use ($key, $start, $stop) {
            return $r->lRange($this->withPrefix($key), $start, $stop);
        });
    }

    /**
     * 通过索引获取 List 中的元素
     *
     * 对应命令: LINDEX key index
     *
     * @param string $key
     * @param int $index
     * @return string|null
     */
    public function lIndex(string $key, int $index)
    {
        return $this->run(function ($r) use ($key, $index) {
            $val = $r->lIndex($this->withPrefix($key), $index);
            return $val === false ? null : $val;
        });
    }

    /**
     * 通过索引设置 List 中元素的值
     *
     * 对应命令: LSET key index value
     *
     * @param string $key
     * @param int $index
     * @param mixed $value
     * @return bool
     */
    public function lSet(string $key, int $index, $value): bool
    {
        return $this->run(function ($r) use ($key, $index, $value) {
            return $r->lSet($this->withPrefix($key), $index, $this->serializeValue($value));
        });
    }

    /**
     * 在 List 中删除与 value 相同的元素，count > 0 时从左向右删除 count 个，
     * count < 0 时从右向左删除 abs(count) 个，count = 0 时删除所有
     *
     * 对应命令: LREM key count value
     *
     * @param string $key
     * @param int $count
     * @param mixed $value
     * @return int  被删除元素的数量
     */
    public function lRem(string $key, int $count, $value): int
    {
        return $this->run(function ($r) use ($key, $count, $value) {
            return $r->lRem($this->withPrefix($key), $this->serializeValue($value), $count);
        });
    }

    /**
     * 对 List 进行修剪，只保留指定区间内的元素
     *
     * 对应命令: LTRIM key start stop
     *
     * @param string $key
     * @param int $start
     * @param int $stop
     * @return bool
     */
    public function lTrim(string $key, int $start, int $stop): bool
    {
        return $this->run(function ($r) use ($key, $start, $stop) {
            return $r->lTrim($this->withPrefix($key), $start, $stop);
        });
    }

    /**
     *  五、Set 操作 -
     **/

    /**
     * 向 Set 中添加一个或多个成员
     *
     * 对应命令: SADD key member [member ...]
     *
     * @param string $key
     * @param mixed ...$members  可以传字符串或数字，也可以传数组
     * @return int  返回新添加的成员数
     * @param array $members
     */
    public function sAdd(string $key, ...$members): int
    {
        return $this->run(function ($r) use ($key, $members) {
            $full = $this->withPrefix($key);
            if (count($members) === 1 && is_array($members[0])) {
                return $r->sAdd($full, ...$members[0]);
            }
            return $r->sAdd($full, ...$members);
        });
    }

    /**
     * 从 Set 中移除一个或多个成员
     *
     * 对应命令: SREM key member [member ...]
     *
     * @param string $key
     * @param mixed ...$members
     * @return int  返回被移除的成员数
     * @param array $members
     */
    public function sRem(string $key, ...$members): int
    {
        return $this->run(function ($r) use ($key, $members) {
            $full = $this->withPrefix($key);
            if (count($members) === 1 && is_array($members[0])) {
                return $r->sRem($full, ...$members[0]);
            }
            return $r->sRem($full, ...$members);
        });
    }

    /**
     * 判断 member 是否是 Set 的成员
     *
     * 对应命令: SISMEMBER key member
     *
     * @param string $key
     * @param mixed $member
     * @return bool
     */
    public function sIsMember(string $key, $member): bool
    {
        return $this->run(function ($r) use ($key, $member) {
            return $r->sIsMember($this->withPrefix($key), (string)$member);
        });
    }

    /**
     * 返回 Set 中的所有成员
     *
     * 对应命令: SMEMBERS key
     *
     * @param string $key
     * @return array
     */
    public function sMembers(string $key): array
    {
        return $this->run(function ($r) use ($key) {
            return $r->sMembers($this->withPrefix($key));
        });
    }

    /**
     * 返回 Set 的基数 (成员数量)
     *
     * 对应命令: SCARD key
     *
     * @param string $key
     * @return int
     */
    public function sCard(string $key): int
    {
        return $this->run(function ($r) use ($key) {
            return $r->sCard($this->withPrefix($key));
        });
    }

    /**
     * 从 Set 中随机弹出一个或多个成员
     *
     * 对应命令: SPOP key [count]
     *
     * @param string $key
     * @param int|null $count  可选，如果不传或为 1 返回单个元素，否则返回数组
     * @return string|string[]|null
     */
    public function sPop(string $key, ?int $count = null)
    {
        return $this->run(function ($r) use ($key, $count) {
            $full = $this->withPrefix($key);
            if ($count === null) {
                $val = $r->sPop($full);
                return $val === false ? null : $val;
            }
            $vals = $r->sPop($full, $count);
            return $vals === false ? [] : $vals;
        });
    }

    /**
     *  六、Sorted Set (ZSet) 操作 -
     **/

    /**
     * 向有序集合中添加一个或多个成员及其分数
     *
     * 对应命令: ZADD key [NX|XX] [CH] [INCR] score member [score member ...]
     *
     * 这里简化为最常用模式：不使用 NX/XX/CH/INCR 标志，直接添加 score => member
     *
     * @param string $key
     * @param array $scoreMembers  格式: [score1 => member1, score2 => member2, ...]
     * @return int  返回被成功添加的新成员的数量
     */
    public function zAdd(string $key, array $scoreMembers): int
    {
        return $this->run(function ($r) use ($key, $scoreMembers) {
            return $r->zAdd($this->withPrefix($key), ...$this->flattenScoreMembers($scoreMembers));
        });
    }

    /**
     * 辅助：将 [score=>member, ...] 转为 [score, member, score, member, ...]
     *
     * @param array $scoreMembers
     * @return array
     */
    protected function flattenScoreMembers(array $scoreMembers): array
    {
        $flat = [];
        foreach ($scoreMembers as $score => $member) {
            // 如果键是字符串且可转为数字，则自动强转
            $flat[] = (float)$score;
            $flat[] = (string)$member;
        }
        return $flat;
    }

    /**
     * 返回有序集合中成员的数量
     *
     * 对应命令: ZCARD key
     *
     * @param string $key
     * @return int
     */
    public function zCard(string $key): int
    {
        return $this->run(function ($r) use ($key) {
            return $r->zCard($this->withPrefix($key));
        });
    }

    /**
     * 返回有序集合中某个成员的分数，如果 member 不存在则返回 null
     *
     * 对应命令: ZSCORE key member
     *
     * @param string $key
     * @param string $member
     * @return float|null
     */
    public function zScore(string $key, string $member)
    {
        return $this->run(function ($r) use ($key, $member) {
            $score = $r->zScore($this->withPrefix($key), $member);
            return $score === false ? null : $score;
        });
    }

    /**
     * 返回有序集合中成员的排名 (按分数从小到大排列，排名从 0 开始)
     *
     * 对应命令: ZRANK key member
     *
     * @param string $key
     * @param string $member
     * @return int|null  不存在时返回 null
     */
    public function zRank(string $key, string $member)
    {
        return $this->run(function ($r) use ($key, $member) {
            $rank = $r->zRank($this->withPrefix($key), $member);
            return $rank === false ? null : $rank;
        });
    }

    /**
     * 返回有序集合中成员的逆序排名 (按分数从大到小排列，排名从 0 开始)
     *
     * 对应命令: ZREVRANK key member
     *
     * @param string $key
     * @param string $member
     * @return int|null
     */
    public function zRevRank(string $key, string $member)
    {
        return $this->run(function ($r) use ($key, $member) {
            $rank = $r->zRevRank($this->withPrefix($key), $member);
            return $rank === false ? null : $rank;
        });
    }

    /**
     * 返回有序集合中指定排名区间的成员及其分数，
     * 如果 withScores=true，则返回 ['member1'=>score1, 'member2'=>score2, ...]，否则只返回成员数组
     *
     * 对应命令: ZRANGE key start stop [WITHSCORES]
     *
     * @param string $key
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array
     */
    public function zRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        return $this->run(function ($r) use ($key, $start, $stop, $withScores) {
            if ($withScores) return $r->zRange($this->withPrefix($key), $start, $stop, ['withscores' => true]);
            return $r->zRange($this->withPrefix($key), $start, $stop);
        });
    }

    /**
     * 返回有序集合中指定排名区间的成员（按分数从大到小）
     *
     * 对应命令: ZREVRANGE key start stop [WITHSCORES]
     *
     * @param string $key
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array
     */
    public function zRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        return $this->run(function ($r) use ($key, $start, $stop, $withScores) {
            if ($withScores) return $r->zRevRange($this->withPrefix($key), $start, $stop, ['withscores' => true]);
            return $r->zRevRange($this->withPrefix($key), $start, $stop);
        });
    }

    /**
     * 返回有序集合中分数在 [min,max] 范围内的成员列表（按分数从小到大）
     *
     * 对应命令: ZRANGEBYSCORE key min max [WITHSCORES]
     *
     * @param string $key
     * @param float|string $min
     * @param float|string $max
     * @param bool $withScores
     * @return array
     */
    public function zRangeByScore(string $key, $min, $max, bool $withScores = false): array
    {
        return $this->run(function ($r) use ($key, $min, $max, $withScores) {
            if ($withScores) return $r->zRangeByScore($this->withPrefix($key), (string)$min, (string)$max, ['withscores' => true]);
            return $r->zRangeByScore($this->withPrefix($key), (string)$min, (string)$max);
        });
    }

    /**
     * 返回有序集合中分数在 [min,max] 范围内的成员数量
     *
     * 对应命令: ZCOUNT key min max
     *
     * @param string $key
     * @param float|string $min
     * @param float|string $max
     * @return int
     */
    public function zCount(string $key, $min, $max): int
    {
        return $this->run(function ($r) use ($key, $min, $max) {
            return $r->zCount($this->withPrefix($key), (string)$min, (string)$max);
        });
    }

    /**
     * 对有序集合中指定成员的分数做增量操作
     *
     * 对应命令: ZINCRBY key increment member
     *
     * @param string $key
     * @param float $increment
     * @param string $member
     * @return float|null  返回新分数，失败时返回 null
     */
    public function zIncrBy(string $key, float $increment, string $member)
    {
        return $this->run(function ($r) use ($key, $increment, $member) {
            $new = $r->zIncrBy($this->withPrefix($key), $increment, $member);
            return $new === false ? null : $new;
        });
    }

    /**
     * 从有序集合中删除一个或多个成员
     *
     * 对应命令: ZREM key member [member ...]
     *
     * @param string $key
     * @param string|array $members
     * @return int  删除的成员数
     */
    public function zRem(string $key, $members): int
    {
        return $this->run(function ($r) use ($key, $members) {
            if (is_array($members)) {
                return $r->zRem($this->withPrefix($key), ...$members);
            }
            return $r->zRem($this->withPrefix($key), $members);
        });
    }

    /**
     * 根据分数区间删除有序集合成员
     * 对应 Redis 命令：ZREMRANGEBYSCORE key min max
     *
     * @param string $key
     * @param mixed  $min
     * @param mixed  $max
     * @return int   删除的成员数
     */
    public function zRemRangeByScore(string $key, $min, $max): int
    {
        return $this->run(function ($r) use ($key, $min, $max) {
            return $r->zRemRangeByScore($this->withPrefix($key), (string)$min, (string)$max);
        });
    }

    /**
     * 从有序集合中弹出分数最小的元素
     * Redis ≥5 + phpredis ≥5 才有 zPopMin；老版本自动退化
     * 对应 Redis 命令：ZPOPMIN key [count]
     *
     * @param string $key
     * @param int    $count 要弹出的元素数量，默认为 1
     * @return array        返回格式：['member1' => score1, 'member2' => score2, …]
     */
    public function zPopMin(string $key, int $count = 1): array
    {
        return $this->run(function ($r) use ($key, $count) {
            $res = $r->zPopMin($this->withPrefix($key), $count);
            return is_array($res) ? $res : [];
        });
    }

    /**
     * 从有序集合中弹出分数最大的元素
     *
     * 对应 Redis 命令：ZPOPMAX key [count]
     *
     * @param string $key
     * @param int    $count 要弹出的元素数量，默认为 1
     * @return array        返回格式：['member1' => score1, 'member2' => score2, …]
     */
    public function zPopMax(string $key, int $count = 1): array
    {
        return $this->run(function ($r) use ($key, $count) {
            $res = $r->zPopMax($this->withPrefix($key), $count);
            return is_array($res) ? $res : [];
        });
    }

    /**
     *  七、Bitmap 操作 -
     **/

    /**
     * 对字符串值的偏移量 bit 进行设置
     *
     * 对应命令: SETBIT key offset value
     *
     * @param string $key
     * @param int $offset
     * @param int $value  0 或 1
     * @return int  返回原来偏移量上的 bit 值（0 或 1）
     */
    public function setBit(string $key, int $offset, int $value): int
    {
        return $this->run(function ($r) use ($key, $offset, $value) {
            return $r->setBit($this->withPrefix($key), $offset, (bool)$value);
        });
    }

    /**
     * 获取字符串值的偏移量 bit
     *
     * 对应命令: GETBIT key offset
     *
     * @param string $key
     * @param int $offset
     * @return int  0 或 1
     */
    public function getBit(string $key, int $offset): int
    {
        return $this->run(function ($r) use ($key, $offset) {
            return $r->getBit($this->withPrefix($key), $offset);
        });
    }

    /**
     * 计算字符串值中，偏移量从 0 到该偏移量之间的 bit 值为 1 的个数
     *
     * 对应命令: BITCOUNT key [start end]
     *
     * @param string $key
     * @param int|null $start  可选，字节单位
     * @param int|null $end    可选，字节单位
     * @return int
     */
    public function bitCount(string $key, ?int $start = null, ?int $end = null): int
    {
        return $this->run(function ($r) use ($key, $start, $end) {
            if ($start !== null && $end !== null) return $r->bitCount($this->withPrefix($key), $start, $end);
            return $r->bitCount($this->withPrefix($key));
        });
    }

    /**
     *  八、Publish/Subscribe -
     **/

    /**
     * 发布消息到指定频道
     *
     * 对应命令: PUBLISH channel message
     *
     * @param string $channel
     * @param string $message
     * @return int  返回该频道当前的订阅者数量
     */
    public function publish(string $channel, string $message): int
    {
        return $this->run(function ($r) use ($channel, $message) {
            return $r->publish($this->withPrefix($channel), $message);
        });
    }

    /**
     * 订阅频道（阻塞式，会在回调里持续监听）
     *
     * 对应命令: SUBSCRIBE channel [channel ...]
     *
     * 注意：此方法会阻塞当前脚本，除非在协程环境/多进程环境使用，否则请谨慎调用。
     *
     * @param array $channels  要订阅的频道名数组
     * @param callable $callback  回调函数，签名 function ($redisInstance, $channelName, $message)。
     */
    public function subscribe(array $channels, callable $callback): void
    {
        $this->run(function ($r) use ($channels, $callback) {
            $pref = array_map([$this, 'withPrefix'], $channels);
            $r->subscribe($pref, $callback);
        });
    }

    /**
     * 订阅模式匹配的频道（阻塞式）
     *
     * 对应命令: PSUBSCRIBE pattern [pattern ...]
     *
     * @param array $patterns  模式数组，如 ["news.*", "user.*"]
     * @param callable $callback  回调函数，签名 function ($redisInstance, $pattern, $channelName, $message)。
     */
    public function psubscribe(array $patterns, callable $callback): void
    {
        $this->run(function ($r) use ($patterns, $callback) {
            $pref = array_map([$this, 'withPrefix'], $patterns);
            $r->psubscribe($pref, $callback);
        });
    }

    /**
     *  九、事务 (WATCH / MULTI / EXEC / DISCARD) -
     **/

    /**
     * 对一个或多个 key 进行乐观锁定
     *
     * 对应命令: WATCH key [key ...]
     *
     * @param string|array $keys
     * @return bool
     */
    public function watch($keys): bool
    {
        return $this->run(function ($r) use ($keys) {
            if (is_array($keys)) {
                $full = array_map([$this, 'withPrefix'], $keys);
                return $r->watch($full);
            }
            return $r->watch($this->withPrefix($keys));
        });
    }

    /**
     * 开启一个事务或管道 (Multi/Exec 模式)
     *
     * 对应命令: MULTI
     *
     * @param int $mode Redis::MULTI (默认) 或 Redis::PIPELINE
     * @return Redis|bool
     */
    public function multi(int $mode = Redis::MULTI)
    {
        return $this->run(function ($r) use ($mode) {
            return $r->multi($mode);
        });
    }

    /**
     * 提交事务
     *
     * 对应命令: EXEC
     *
     * @return array|bool  Arrays of replies, or FALSE if the transaction was aborted (e.g.,因为 watched key 变更)
     */
    public function exec()
    {
        return $this->run(function ($r) {
            return $r->exec();
        });
    }

    /**
     * 取消事务
     *
     * 对应命令: DISCARD
     *
     * @return bool
     */
    public function discard(): bool
    {
        return $this->run(function ($r) {
            return $r->discard();
        });
    }

    /**
     *  十、Geo 操作 -
     **/

    /**
     * 向地理位置索引中添加一个或多个成员
     *
     * 对应命令: GEOADD key longitude latitude member [longitude latitude member ...]
     *
     * @param string $key
     * @param array $locations  格式: [
     *     ['longitude' => 13.361389, 'latitude' => 38.115556, 'member' => 'Palermo'],
     *     ['longitude' => 15.087269, 'latitude' => 37.502669, 'member' => 'Catania'],
     * ]
     * @return int  成功添加的成员数
     */
    public function geoAdd(string $key, array $locations): int
    {
        return $this->run(function ($r) use ($key, $locations) {
            $flat = [];
            foreach ($locations as $loc) {
                $flat[] = (float)$loc['longitude'];
                $flat[] = (float)$loc['latitude'];
                $flat[] = (string)$loc['member'];
            }
            return $r->geoAdd($this->withPrefix($key), ...$flat);
        });
    }

    /**
     * 返回地理位置坐标 (longitude, latitude)
     *
     * 对应命令: GEOPOS key member [member ...]
     *
     * @param string $key
     * @param array $members
     * @return array  返回格式: [
     *   ['13.361389338970184','38.115556395496299'], // 经纬度字符串
     *   null, // 如果该 member 不存在
     * ]
     */
    public function geoPos(string $key, array $members): array
    {
        return $this->run(function ($r) use ($key, $members) {
            return $r->geoPos($this->withPrefix($key), ...$members);
        });
    }

    /**
     * 返回两个 member 之间的距离
     *
     * 对应命令: GEODIST key member1 member2 [unit]
     *
     * @param string $key
     * @param string $member1
     * @param string $member2
     * @param string $unit  单位: "m"、"km"、"mi"、"ft" 等
     * @return float|null  以指定单位表示的距离，如果任一 member 不存在则返回 null
     */
    public function geoDist(string $key, string $member1, string $member2, string $unit = 'm')
    {
        return $this->run(function ($r) use ($key, $member1, $member2, $unit) {
            $dist = $r->geoDist($this->withPrefix($key), $member1, $member2, $unit);
            return $dist === false ? null : $dist;
        });
    }

    /**
     * 基于经纬度坐标返回指定半径范围内的 member 列表
     *
     * 对应命令: GEORADIUS key longitude latitude radius unit [WITHCOORD] [WITHDIST] [WITHHASH] [COUNT count] [ASC|DESC]
     *
     * @param string $key
     * @param float $longitude
     * @param float $latitude
     * @param float $radius
     * @param string $unit     单位: "m","km","mi","ft"
     * @param bool $withCoord  是否返回坐标信息
     * @param bool $withDist   是否返回距离
     * @param bool $withHash   是否返回哈希值
     * @param int|null $count  限制返回数量
     * @param bool $asc        true 为从小到大 (默认)
     * @return array
     */
    public function geoRadius(
        string $key,
        float $longitude,
        float $latitude,
        float $radius,
        string $unit = 'm',
        bool $withCoord = false,
        bool $withDist = false,
        bool $withHash = false,
        ?int $count = null,
        bool $asc = true
    ): array {
        return $this->run(function ($r) use (
            $key,
            $longitude,
            $latitude,
            $radius,
            $unit,
            $withCoord,
            $withDist,
            $withHash,
            $count,
            $asc
        ) {
            $opts = [];
            if ($withCoord) $opts['withcoord'] = true;
            if ($withDist)  $opts['withdist']  = true;
            if ($withHash)  $opts['withhash']  = true;
            if ($count !== null) $opts['count'] = $count;
            $opts[$asc ? 'asc' : 'desc'] = true;
            return $r->geoRadius($this->withPrefix($key), $longitude, $latitude, $radius, $unit, $opts);
        });
    }

    /**
     * 基于 member 返回指定半径范围内的 member 列表
     *
     * 对应命令: GEORADIUSBYMEMBER key member radius unit [WITHCOORD] [WITHDIST] [WITHHASH] [COUNT count] [ASC|DESC]
     *
     * @param string $key
     * @param string $member
     * @param float $radius
     * @param string $unit
     * @param bool $withCoord
     * @param bool $withDist
     * @param bool $withHash
     * @param int|null $count
     * @param bool $asc
     * @return array
     */
    public function geoRadiusByMember(
        string $key,
        string $member,
        float $radius,
        string $unit = 'm',
        bool $withCoord = false,
        bool $withDist = false,
        bool $withHash = false,
        ?int $count = null,
        bool $asc = true
    ): array {
        return $this->run(function ($r) use (
            $key,
            $member,
            $radius,
            $unit,
            $withCoord,
            $withDist,
            $withHash,
            $count,
            $asc
        ) {
            $opts = [];
            if ($withCoord) $opts['withcoord'] = true;
            if ($withDist)  $opts['withdist']  = true;
            if ($withHash)  $opts['withhash']  = true;
            if ($count !== null) $opts['count'] = $count;
            $opts[$asc ? 'asc' : 'desc'] = true;
            return $r->geoRadiusByMember($this->withPrefix($key), $member, $radius, $unit, $opts);
        });
    }

    /**
     *  十一、事务型流水线 (Pipeline) -
     **/

    /**
     * 执行 Redis Pipeline。回调中可接收 Redis 实例并直接调用 Redis 方法，
     * 最终 collect 并返回一个结果数组，对应每个命令的返回值。
     *
     * 对应命令: MULTI + EXEC 但以 PIPELINE 模式批量发送
     *
     * @param callable $callback  回调参数: function($redis)
     * @return array  返回每条命令对应的返回值组成的数组
     */
    public function pipeline(callable $callback): array
    {
        return $this->run(function ($r) use ($callback) {
            $r->multi(Redis::PIPELINE);
            $callback($r);
            $replies = $r->exec();
            return $replies ?: [];
        });
    }

    /**
     *  十二、批量操作 (MGET / MSET / MDEL) -
     **/

    /**
     * 批量获取。使用 MGET 一次性取回多个键，
     * 然后对每个值做和 get() 中相同的 JSON 解码逻辑，不存在时返回 null。
     *
     * 对应命令: MGET key1 key2 ...
     *
     * @param array $keys
     * @return array  ['key1' => value1, 'key2' => value2, ...]
     * @param null $default
     */
    public function getMulti(array $keys, $default = null): array
    {
        if (empty($keys)) return [];
        return $this->run(function ($r) use ($keys, $default) {
            $full = array_map([$this, 'withPrefix'], $keys);
            $raws = $r->mGet($full);
            $res = [];
            foreach ($keys as $i => $k) {
                $raw = $raws[$i] ?? null;
                if ($raw === false || $raw === null) {
                    $res[$k] = $default;
                } else {
                    $decoded = json_decode($raw, true);
                    $res[$k] = (json_last_error() === JSON_ERROR_NONE && !is_numeric($raw)) ? $decoded : $raw;
                }
            }
            return $res;
        });
    }

    public function mGet(array $keys): array
    {
        return $this->getMulti($keys);
    }

    /**
     * @param null $default
     */
    public function getMultiple(array $keys, $default = null): array
    {
        return $this->getMulti($keys, $default);
    }

    /**
     * 批量写入。若 $ttl <= 0，则用 MSET；
     * 如果 $ttl > 0，则用 Pipeline 对每个键执行 SETEX。
     * 支持数组/对象值自动 JSON 编码，返回 bool 表示是否都成功。
     *
     * 对应命令: MSET / SETEX (Pipeline)
     *
     * @param array $items   例如 ['key1'=>value1, 'key2'=>value2, ...]
     * @param int $ttl       过期时间(秒)，<=0 时使用 MSET，无过期；>0 时对每个键 SETEX
     * @return bool
     */
    public function setMulti(array $items, int $ttl = 0): bool
    {
        if (empty($items)) return true;
        // 注意：mSet 可能不支持集群跨节点，但在 WellCMS 单点/常规架构下 OK
        // 如果 TTL > 0，走 Pipeline 以保证原子性/效率
        return $this->run(function ($r) use ($items, $ttl) {
            if ($ttl <= 0) {
                $to = [];
                foreach ($items as $k => $v) {
                    $to[$this->withPrefix($k)] = $this->serializeValue($v);
                }
                return $r->mSet($to);
            }
            $r->multi(Redis::PIPELINE);
            foreach ($items as $k => $v) {
                $r->setex($this->withPrefix($k), $ttl, $this->serializeValue($v));
            }
            $replies = $r->exec();
            return is_array($replies);
        });
    }

    public function mSet(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    /**
     * 批量删除。将所有 fullKey 一次性传给 DEL，返回成功删除的键数量。
     *
     * 对应命令: DEL key1 key2 ...
     *
     * @param array $keys
     * @return int  被删除键的数量
     */
    public function mDelete(array $keys): int
    {
        if (empty($keys)) return 0;
        return $this->run(function ($r) use ($keys) {
            $full = array_map([$this, 'withPrefix'], $keys);
            return $r->del($full);
        });
    }

    /**
     *  十三、分布式锁 (Lock / Unlock / isLocked) -
     **/

    /**
     * 获取锁：尝试用 SET NX EX 原子设置一个锁键，成功时返回随机生成的 token，否则返回 null。
     *
     * 对应命令: SET lock_key token NX EX expire
     *
     * @param string $key    锁标识
     * @param int $expire    锁过期时间 (秒)，默认 3
     * @return string|null   如果获得锁返回 token，否则 null
     * @throws \Exception
     */
    public function lock(string $key, int $ttl = 3)
    {
        $fullLock = 'lock_' . $this->withPrefix($key);                 // [MOD] 统一前缀
        return $this->run(function ($r) use ($fullLock, $ttl) {
            $startTime = microtime(true);
            $pid = function_exists('posix_getpid') ? posix_getpid() : 0;
            $token = $pid . ':' . bin2hex(random_bytes(16));
            $maxWait = $ttl; // 最多等待 ttl 秒钟来获取锁
            $backoffBase = 50000; // 50ms

            while ((microtime(true) - $startTime) < $maxWait) {
                // SET NX PX
                $ok = $r->set($fullLock, $token, ['nx', 'ex' => $ttl]);
                if ($ok) {
                    return $token;
                }
                // 否则退避一段时间再试 —— 指数退避 + 随机抖动（避免雪崩）
                $elapsed = microtime(true) - $startTime;
                $attempt = (int)($elapsed * 10); // 估算尝试次数
                $sleepUs = (int)min(100000, $backoffBase * 0.5 * min($attempt, 3));
                usleep($sleepUs);
            }

            return null;
        });
    }

    /**
     * 解锁：通过 Lua 脚本，只有当锁键的值与传入的 $token 相同时才删除，返回 true/false。
     *
     * 对应命令: EVAL(lua脚本, [key, token], 1)
     *
     * @param string $key
     * @param string $token
     * @return bool
     */
    public function unlock(string $key, string $token)
    {
        $fullLock = 'lock_' . $this->withPrefix($key);
        return (int)$this->call(self::S_COMPARE_DEL, [$fullLock, $token], 1) === 1;
    }

    /**
     * 锁续期：仅当锁键的值与传入的 $token 相同时才续期 TTL。
     *
     * @param string $key
     * @param string $token
     * @param int $ttl
     * @return bool
     */
    public function renewLock(string $key, string $token, int $ttl): bool
    {
        $fullLock = 'lock_' . $this->withPrefix($key);
        $lua = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;
        return (int)$this->call($lua, [$fullLock, $token, $ttl], 1) === 1;
    }

    /**
     * 判断锁是否存在。仅当键存在时返回 true，否则 false。
     *
     * 对应命令: EXISTS lock_key
     *
     * @param string $key
     * @return bool
     */
    public function isLocked(string $key)
    {
        $fullLock = 'lock_' . $this->withPrefix($key);
        return $this->run(function ($r) use ($fullLock) {
            return $r->exists($fullLock) > 0;
        });
    }

    /**
     * 令牌桶限流
     * @param string $key      维度标识（ip:1.2.3.4 / UserID:123）
     * @param int    $capacity 桶容量（令牌个数）
     * @param int    $rate     速率（每秒补充令牌数）
     * @return bool  true=放行，false=限流
     */
    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        $nowMs = (int)(microtime(true) * 1000);
        $bucketKey = $this->withPrefix($key);
        return 1 === $this->call(self::S_TOKEN_BUCKET, [$bucketKey, $nowMs, $cap, $rate], 1);
    }

    public function original(string $only = '')
    {
        // 连接池模式下没有固定底层连接，返回 null；兼容旧行为返回自建连接
        return $this->withConn ? null : $this->redis;
    }

    use \Framework\Cache\Traits\CacheWithLockTrait;
}
