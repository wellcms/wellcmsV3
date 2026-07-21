<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache;

/**
 * 支持配置驱动 + 按需选择的多层 CacheManager
 */
class CacheManager implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var \Framework\Cache\Interfaces\CacheInterface[] 按 stores 顺序加载的驱动 */
    protected $drivers = [];

    /** @var \Framework\Cache\Pool\RedisPool[] 仅保存 RedisPool 实例，key = 驱动名 */
    protected $redisPools = [];

    /** @var \Framework\Cache\Pool\MemcachedPool[] 仅保存 MemcachedPool 实例，key = 驱动名 */
    protected $memPools = [];

    /** @var array 用于当次请求内的静态缓存 */
    protected $static = [];

    /** @var array 方法到驱动名的映射缓存，用于 O(1) 转发 */
    protected $methodMap = [];

    /**
     * 构造函数：根据 config/cache.php 中的 stores 加载对应驱动，并为 Redis/Memcached 初始化连接池
     *
     * @param array $cacheConfig 由 config/cache.php 返回的数组
     */
    public function __construct(array $cacheConfig = [])
    {
        if (empty($cacheConfig)) {
            throw new \RuntimeException("必须配置 'stores'");
        }

        // 先按配置文件里的顺序初始化并排序 drivers
        $stores = $cacheConfig['stores'];
        foreach ($stores as $name => $cfg) {
            switch (strtolower($name)) {
                case 'redis':
                    if (!extension_loaded('redis')) {
                        throw new \Exception('Redis extension not loaded, please check your PHP version');
                    }
                    // 为 redis 驱动准备一个连接池
                    $poolSize = isset($cfg['pool_size']) ? (int)$cfg['pool_size'] : 10;
                    $pool = \Framework\Cache\Pool\RedisPool::getInstance($cfg, $poolSize);
                    $this->redisPools['redis'] = $pool;
                    // 通过闭包把“借还连接”的细节下沉到驱动内部
                    $withConn = function (callable $runner) use ($pool) {
                        $redis = $pool->getConnection();
                        try {
                            return $runner($redis);
                        } catch (\Throwable $e) {
                            $pool->markSuspect($redis);
                            throw $e;
                        } finally {
                            $pool->releaseConnection($redis);
                        }
                    };
                    $this->drivers['redis'] = new \Framework\Cache\Drivers\RedisCache($cfg, $withConn);
                    break;
                case 'memcached':
                    if (!extension_loaded('memcached')) {
                        throw new \Exception('Memcached extension not loaded, please check your PHP version');
                    }
                    // 为 memcached 驱动准备一个连接池
                    $poolSize = isset($cfg['pool_size']) ? (int)$cfg['pool_size'] : 10;
                    // memcached 配置里必须有 'servers' 和可选 'persistent_id'
                    $servers = isset($cfg['servers'])      ? $cfg['servers']      : [];
                    $persistentId = isset($cfg['persistent_id']) ? $cfg['persistent_id'] : 'memcached_pool';
                    $pool = \Framework\Cache\Pool\MemcachedPool::getInstance(
                        ['servers' => $servers, 'persistent_id' => $persistentId],
                        $poolSize
                    );
                    $this->memPools['memcached'] = $pool;
                    // 下沉借还逻辑
                    $withConn = function (callable $runner) use ($pool) {
                        $mc = $pool->getConnection();
                        try {
                            return $runner($mc);
                        } catch (\Throwable $e) {
                            $pool->markSuspect($mc);
                            throw $e;
                        } finally {
                            $pool->releaseConnection($mc);
                        }
                    };
                    $this->drivers['memcached'] = new \Framework\Cache\Drivers\MemcachedCache($cfg, $withConn);
                    break;
                case 'apcu':
                    if (!function_exists('apcu_fetch')) {
                        if (!extension_loaded('apcu')) {
                            throw new \Exception('APCu extension not loaded, please check your PHP version');
                        }
                    }
                    $this->drivers['apcu'] = new \Framework\Cache\Drivers\ApcuCache($cfg);
                    break;
                case 'yac':
                    if (!function_exists('yac')) {
                        // 判断 Yac 扩展装载与否，用 function_exists('yac') 也可
                        if (!extension_loaded('yac')) {
                            throw new \Exception('Yac extension not loaded, please check your PHP version');
                        }
                    }
                    $this->drivers['yac'] = new \Framework\Cache\Drivers\YacCache($cfg);
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown cache driver: {$name}");
            }
        }

        // 强制按照配置文件里 stores 的“顺序”对 $this->drivers 排序
        $order = array_keys($stores);
        uksort($this->drivers, function ($a, $b) use ($order) {
            return array_search($a, $order, true) <=> array_search($b, $order, true);
        });

        // 构建高级指令路由表
        $this->buildMethodMap();
    }

    /**
     * 构建 O(1) 方法路由表
     */
    protected function buildMethodMap(): void
    {
        $this->methodMap = [];
        // 排除 CacheManager 自身已实现的方法，避免死循环
        $ownMethods = get_class_methods($this);
        foreach ($this->drivers as $name => $drv) {
            $methods = get_class_methods($drv);
            foreach ($methods as $m) {
                if (!isset($this->methodMap[$m]) && !in_array($m, $ownMethods)) {
                    $this->methodMap[$m] = $name;
                }
            }
        }
    }

    /** 获取驱动内部经过前缀和哈希处理后的真实 Key */
    public function withPrefix(string $key): string
    {
        reset($this->drivers);
        $drv = current($this->drivers);
        return $drv ? $drv->withPrefix($key) : $key;
    }

    public function getPrefix(): string
    {
        reset($this->drivers);
        $drv = current($this->drivers);
        return $drv ? $drv->getPrefix() : '';
    }

    /**
     * 获取缓存
     *
     * @param string   $key     键名
     * @param mixed    $default 默认值
     * @param array $only    可选，仅使用哪些驱动（驱动名数组）
     * @return mixed
     */
    public function get(string $key, $default = null, array $only = [])
    {
        if (isset($this->static[$key])) return $this->static[$key];

        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);

        $missed = [];
        $found  = null;

        foreach ($drivers as $drvKey => $drv) {
            $val = $drv->get($key, null); // 统一通过驱动调用
            if (null !== $val) {
                $found = $val;
                break;
            }
            $missed[] = $drvKey;
        }

        if (null !== $found && !empty($missed)) {
            $this->set($key, $found, 1800, $missed);
            $this->static[$key] = $found;
            return $found;
        }

        if (null !== $found) {
            $this->static[$key] = $found;
            return $found;
        }

        return $default;
    }

    /** 批量获取缓存
     * @param array $keys
     * @param mixed $default
     * @param array $only 可选，仅在哪些驱动上执行
     * @return array
     */
    public function getMulti(array $keys, $default = null, array $only = []): array
    {
        if (empty($keys)) return [];
        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);

        $result = [];
        $remainingKeys = $keys;
        $driverFoundKeys = []; // 记录各层驱动命中的键，用于闭环回填

        foreach ($drivers as $drvKey => $drv) {
            $batch = $drv->getMulti($remainingKeys, null);
            $foundInThisDriver = [];
            foreach ($batch as $k => $v) {
                if ($v !== null) {
                    $result[$k] = $v;
                    $this->static[$k] = $v;
                    $foundInThisDriver[$k] = $v;
                }
            }
            if (!empty($foundInThisDriver)) {
                $driverFoundKeys[$drvKey] = $foundInThisDriver;
            }
            $remainingKeys = array_diff($remainingKeys, array_keys($result));
            if (empty($remainingKeys)) break;
        }

        // 逻辑闭环：批量回填 (Back-filling)
        // 如果在低级驱动命中，将其回填到之前未命中的高级驱动中
        if (!empty($driverFoundKeys)) {
            $driverKeysOriginal = array_keys($drivers);
            foreach ($driverFoundKeys as $drvKey => $foundItems) {
                $drvPos = array_search($drvKey, $driverKeysOriginal);
                // 将结果写回所有位置更靠前的驱动（即之前 Miss 的高级缓存）
                for ($i = 0; $i < $drvPos; $i++) {
                    $higherDrvKey = $driverKeysOriginal[$i];
                    $this->drivers[$higherDrvKey]->setMulti($foundItems, 1800);
                }
            }
        }

        foreach ($remainingKeys as $k) {
            $result[$k] = $default;
        }

        return $result;
    }

    /**
     * @param null $default
     */
    public function getMultiple(array $keys, $default = null): array
    {
        return $this->getMulti($keys, $default);
    }

    /** 批量写入缓存
     * @param array $items
     * @param int   $ttl
     * @param array $only  可选，写入哪些驱动
     * @return bool
     */
    public function setMulti(array $items, int $ttl = 0, array $only = []): bool
    {
        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);
        $okAll = false;
        foreach ($drivers as $drv) {
            $ok = (bool)$drv->setMulti($items, $ttl);
            $okAll = $ok || $okAll;
        }
        foreach ($items as $k => $v) {
            $this->static[$k] = $v;
        }
        return $okAll;
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    /**
     * 设置缓存
     *
     * @param string   $key   键名
     * @param mixed    $value 值
     * @param int      $ttl   存活时间（秒）
     * @param array $only  可选，仅写入哪些驱动（驱动名数组）
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0, array $only = []): bool
    {
        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);
        $okAll = false;
        foreach ($drivers as $drv) {
            $ok = (bool)$drv->set($key, $value, $ttl);
            $okAll = $ok || $okAll;
        }
        $this->static[$key] = $value;
        return $okAll;
    }

    /**
     * 删除缓存
     *
     * @param string   $key
     * @param array $only 可选，仅删除哪些驱动
     * @return bool
     */
    public function delete(string $key, array $only = []): bool
    {
        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);
        $okAll = true;
        foreach ($drivers as $drv) {
            $ok = (bool)$drv->delete($key);
            if ($ok === false) $okAll = false;
        }
        unset($this->static[$key]);
        return $okAll;
    }

    /**
     * 自增缓存值（仅在“第一个”驱动执行）
     *
     * @param string $key
     * @param int    $step
     * @param int    $ttl
     * @return int
     */
    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        reset($this->drivers);
        $firstKey = key($this->drivers);
        $drv = $this->drivers[$firstKey];
        if (!method_exists($drv, 'increment')) throw new \RuntimeException("Driver {$firstKey} does not support increment");

        return (int)$drv->increment($key, $step, $ttl);
    }

    /**
     * 清空所有缓存
     *
     * @param array $only 可选，仅清空哪些驱动
     * @return bool
     */
    public function clear(array $only = []): bool
    {
        $drivers = empty($only) ? $this->drivers : (array_intersect_key($this->drivers, array_flip($only)) ?: $this->drivers);
        $okAll = true;
        foreach ($drivers as $drv) {
            $ok = (bool)$drv->clear();
            if (!$ok) $okAll = false;
        }
        $this->static = [];
        return $okAll;
    }

    /**
     * 给定一个 key，尝试在第一个驱动上加锁
     *
     * @param string $key
     * @param int    $ttl 锁超时时间，单位秒
     * @return string|null 返回 token，或加锁失败返回 null
     */
    public function lock(string $key, int $ttl = 30)
    {
        reset($this->drivers);
        $firstKey = key($this->drivers);
        $drv = $this->drivers[$firstKey];
        if (!method_exists($drv, 'lock')) return null;
        return $drv->lock($key, $ttl);
    }

    /**
     * 解锁
     *
     * @param string $key
     * @param string $token
     * @return bool
     */
    public function unlock(string $key, string $token){
        reset($this->drivers);
        $firstKey = key($this->drivers);
        $drv = $this->drivers[$firstKey];
        if (!method_exists($drv, 'unlock')) return false;
        return (bool)$drv->unlock($key, (string)$token);
    }

    /**
     * 判断是否被锁
     *
     * @param string $key
     * @return bool
     */
    public function isLocked(string $key){
        reset($this->drivers);
        $firstKey = key($this->drivers);
        $drv = $this->drivers[$firstKey];
        if (!method_exists($drv, 'isLocked')) return false;
        return (bool)$drv->isLocked($key);
    }

    /**
     * 令牌桶限流
     * @param string $key      维度标识（ip:1.2.3.4 / UserID:123）
     * @param int    $cap      桶容量（令牌个数）瞬时可突发的最大请求数，当桶已满，再来令牌会被丢弃，不再累积。
     * @param int    $rate     速率（每秒补充令牌数）决定 平均持续 QPS，不会让总令牌数超过 $cap。
     * @param array  $only     使用哪个驱动（驱动名数组），若为空，则默认选择第一个。
     * @return bool  true=放行，false=限流
     *
     * 先按时间差计算应补充的令牌数（= $rate × Δt），把新令牌加进桶，若超过 $capacity 就截断，检查桶里有没有 ≥ 1 个令牌：有 → 消耗 1 个，返回 true（放行）；没有 → 返回 false（限流）。
     *
     * $cache->allow("ip:$ip", 40, 20, ['yac']);
     * $cache->allow("ip:$ip", 40, 20, ['apcu']);
     * $cache->allow("ip:$ip", 200, 40, ['memcached']);
     * $cache->allow("ip:$ip", 600, 60, ['redis']);
     *
     * 组合打法
     * Nginx limit_req_zone (粗限)
     * YAC / APCu 本机预限流（1 ms）
     *   ↓
     * Memcached 业务级限流
     *   ↓
     * Redis 核心限流 + 黑名单
     */
    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        $drivers = [];
        $onlyCache = false;
        if (!empty($only)) {
            $drivers = array_intersect_key($this->drivers, array_flip($only));
            $onlyCache = true;
        }

        if (false === $onlyCache) {
            reset($this->drivers);
            $firstKey = key($this->drivers);
            $drivers = [$this->drivers[$firstKey]];
        }

        foreach ($drivers as $drv) {
            if (method_exists($drv, 'allow')) {
                return (bool)$drv->allow($key, $cap, $rate);
            }
        }

        return false;
    }

    /**
     * 返回原始驱动实例（不使用池，而是直接 new 出来的对象）
     *
     * @param string $only 驱动名
     * @return \Framework\Cache\Interfaces\CacheInterface|null
     */
    public function original(string $only = '')
    {
        return $only && isset($this->drivers[$only]) ? $this->drivers[$only] : null;
    }

    /**
     * 魔术方法：转发高级指令给支持该指令的底层驱动
     * @param string $name
     * @param array $arguments
     */
    public function __call($name, $arguments)
    {
        if (isset($this->methodMap[$name])) {
            return call_user_func_array([$this->drivers[$this->methodMap[$name]], $name], $arguments);
        }
        throw new \BadMethodCallException("Cache driver(s) do not support method: {$name}");
    }

    /*
    $this->navigations = $this->cache->cacheWithLock(
        'NavigationList',
        'lock:NavigationList',
        function () {
            return $this->find([], [], 1, 1000, 'id');
        }
    );
    if ($this->navigations) return $this->navigations;
     */
    use \Framework\Cache\Traits\CacheWithLockTrait;
}

/*
默认驱动（配置驱动）调用示例
$cacheConfigArray = include __DIR__.'/config/cache.php';
$cache = new CacheManager($cacheConfigArray);

// set/get（底层 Redis 与 Memcached 都会走各自的池）
$cache->set('foo', ['a'=>1], 600);      // 写到 Yac、APCu、Redis、Memcached（因为 stores 里所有键都初始化了驱动）
$value = $cache->get('foo');            // 先查 Yac（命中就返回），否则查 APCu，再 Redis，再 Memcached

// increment
$new = $cache->increment('counter', 1, 300);  // 底层只在 “第一个驱动” 上 incr；如果“第一个”是 Redis，就从 RedisPool 拿连接执行 incr

// delete/clear
$cache->delete('foo', ['redis','memcached']); // 仅删除 Redis & Memcached 上的键，不动 Yac/APCu
$cache->clear();                              // 清空所有驱动

// lock/unlock
$token = $cache->lock('my_lock_key', 5);      // 底层第一个支持 lock 的驱动（依配置顺序）会真正加锁
if ($cache->isLocked('my_lock_key')) { // … }
$cache->unlock('my_lock_key', $token);

// cacheWithLock 示例
$data = $cache->cacheWithLock(
    'NavigationList',
    'lock:NavigationList',
    function () {
        // 如果缓存里没有，执行匿名函数去查库或其它地方拿数据
        return $this->find([], [], 1, 1000, 'id');
    },
    5,      // 最多 5 次抢锁
    3600,   // 数据缓存 1 小时
    3       // 锁超时 3 秒
);

可选 stores 列表（按需选择）调用示例
// 仅使用 Redis 驱动
$set('orders', $orderData, 600, ['redis']);
$data = $get('orders', null, ['redis']);
*/
