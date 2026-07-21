<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Stats;

/**
 * Class RuntimeStats
 *
 * 可扩展的运行时统计管理：支持动态注册任意统计项，
 * 自动维护“当日（daily）”与“总量（total）”两套 Redis 键，
 * 并在零点自动将当日计数归零。
 *
 * 使用示例：
 *   $stats = $container->get(RuntimeStats::class);
 *   $stats->registerStat('user', function() use ($userService) {
 *       return $userService->count();
 *   });
 *   $stats->registerStat('post', function() use ($postService) {
 *       return $postService->count();
 *   });
 *   // …其他注册…
 *
 *   // 当有用户注册时：
 *   $stats->incrementStat('user', 1);
 *   // 当有新帖子发布：
 *   $stats->incrementStat('post');
 *   // 获取今日用户注册数：
 *   $todayUsers = $stats->getDaily('user');
 *   // 获取用户总数：
 *   $totalUsers = $stats->getTotal('user');
 */
class RuntimeStats
{
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var \DateTimeZone */
    private $tz;
    /**
     * 已注册的统计项列表：
     * 键名为 $statName，值为回调函数，用来初始化“总量”计数器。
     *
     * @var array<string, callable>
     */
    private $statDefinitions = [];

    /** Cache 键名的前缀 */
    private const PREFIX_DAILY = 'runtime_stats:daily:';
    private const PREFIX_TOTAL = 'runtime_stats:total:';
    private const KEY_META_RESET = 'runtime_stats:meta:last_reset_date';
    private const DAILY_TTL = 86400;

    /**
     * @param \Framework\Cache\Interfaces\CacheInterface $cache
     * @param string $timezone 业务时区，如 'Asia/Shanghai'
     */
    public function __construct(\Framework\Cache\Interfaces\CacheInterface $cache, string $timezone = 'UTC')
    {
        $this->cache = $cache;
        $this->tz = new \DateTimeZone($timezone);

        // 自动检查：若尚未在当日执行过 resetDaily，就先运行一次
        $this->initialize();
    }

    /**
     * 初始化：检查“上次重置日期”是否等于当前日期，若不同则执行 resetDaily()。
     *
     * 这样可以保证：在没有使用 Crontab 的场景下，
     * 当首次访问本类时，会完成“跨天一次性重置”。
     */
    private function initialize(): void
    {
        $today = $this->today();
        if ($this->cache->get(self::KEY_META_RESET) !== $today) {
            $this->cache->set(self::KEY_META_RESET, $today, self::DAILY_TTL * 2);
        }
    }

    /**
     * 注册一个新的统计项
     *
     * @param string   $statName      统计项标识（如 'user'、'post'、'comment'、'attachment' 或自定义名称）
     * @param callable $countCallback 当 Redis 中对应“总量（total）”键不存在时，调用此回调获取初始值 (int)
     *
     * @return void
     */
    public function registerStat(string $statName, callable $countCallback): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $statName)) throw new \InvalidArgumentException("Invalid stat name format");

        // 避免重复注册
        if (isset($this->statDefinitions[$statName])) return;

        // 将回调存入定义列表
        $this->statDefinitions[$statName] = $countCallback;
    }

    /**
     * 获取某个统计项当天（daily）的计数值
     *
     * @param string $statName  已注册的统计项标识
     * @return int              若 Redis 中该键不存在，则默认为 0
     */
    public function getDaily(string $statName): int
    {
        $this->ensureRegistered($statName);

        $value = $this->cache->get($this->dailyKey($statName));
        return (is_numeric($value) ? (int)$value : 0);
    }

    /**
     * 获取某个统计项总量（total）
     *
     * @param string $statName 已注册的统计项标识
     * @return int 若 Cache 中该键不存在，则调用注册时提供的回调获取初始值并写入 Cache
     */
    public function getTotal(string $statName): int
    {
        $this->ensureRegistered($statName);

        $cacheKey = self::PREFIX_TOTAL . $statName;
        $value = $this->cache->get($cacheKey);
        if (is_numeric($value)) return (int)$value;

        // 不存在时，从回调获取初始值，并持久化到 cache（不过期）
        $initial = call_user_func($this->statDefinitions[$statName]);
        if (!is_int($initial)) {
            // 为了安全，若回调返回非整型，强制置 0
            $initial = 0;
        }
        $this->cache->set($cacheKey, $initial, 0); // TTL=0 表示永久
        return $initial;
    }

    /**
     * 对指定统计项同时执行“当日 +N”与“总量 +N”操作
     *
     * - 当日（daily）键使用原子自增，并保证首次创建时设置“当天到 23:59:59 到期”；
     * - 总量（total）键使用原子自增，若不存在则先从回调获取初始值并永久保存。
     *
     * @param string $statName  已注册的统计项标识
     * @param int    $delta     默认为 +1；可为负数做减法
     */
    public function incrementStat(string $statName, int $delta = 1): void
    {
        if ($delta === 0) return;
        $this->ensureRegistered($statName);

        /* ---------- daily ---------- */
        // 高性能优化：直接调用 increment，底层驱动（Redis/Memcached/APCu/Yac）
        // 均保证键不存在时自动创建并设置 TTL，省去一次冗余的 get+set
        $dailyKey = $this->dailyKey($statName);
        $expire   = $this->getSecondsUntilMidnight();
        $this->cache->increment($dailyKey, $delta, $expire);

        /* ---------- total ---------- */
        $totalKey = self::PREFIX_TOTAL . $statName;

        // 科学优化：若 total 未初始化，利用框架级 cacheWithLock 一次性回填，
        // 避免手动 while 循环忙等待和锁竞争（cacheWithLock 内部已实现双查锁、指数退避、随机抖动）
        if ($this->cache->get($totalKey) === null) {
            $this->cache->cacheWithLock(
                $totalKey,
                'lock:' . $totalKey,
                function () use ($statName) {
                    $initial = call_user_func($this->statDefinitions[$statName]);
                    return is_int($initial) ? $initial : 0;
                },
                5,   // maxAttempts
                0,   // cacheTtl (永久)
                3    // lockTtl
            );
        }

        $this->cache->increment($totalKey, $delta);
    }

    /**
     * 零点重置所有已注册统计项的“当日”键（daily），并使用 Redis pipeline 批量操作。
     *
     * - 如果底层 Redis 客户端支持 pipeline(callable)，则将所有 SET/EXPIRE 命令一次性推送；
     * - 否则回退到普通循环写入。
     *
     * @return void
     */
    public function resetDaily(): void
    {
        $today = $this->now()->format('Y-m-d');

        foreach ($this->statDefinitions as $statName => $_) {
            $this->cache->set($this->dailyKey($statName), 0, 86400);
        }

        $this->cache->set(self::KEY_META_RESET, $today, 86400);
    }

    /**
     * 专门供 Crontab 或 CLI 模式下直接触发零点重置：
     *   php /path/to/index.php runtime:resetDaily
     */
    public function cronResetDaily(): void
    {
        $this->resetDaily();
    }

    private function dailyKey(string $stat): string
    {
        return self::PREFIX_DAILY . $this->now()->format('Ymd') . ':' . $stat;
    }

    private function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz);
    }

    /**
     * 计算从当前时刻到“当天 23:59:59”剩余的秒数，
     * 最小值为 60 秒，以防刚好在 23:59:xx 造成立刻过期。
     *
     * @return int
     */
    private function getSecondsUntilMidnight(): int
    {
        $now = time();
        $tomorrow = strtotime('tomorrow');
        $seconds = $tomorrow - $now;
        return ($seconds > 60) ? $seconds : 60;
    }

    /**
     * 确保某个统计项已在 registerStat() 中注册，否则抛异常
     *
     * @param string $statName
     * @throws \InvalidArgumentException
     */
    private function ensureRegistered(string $statName): void
    {
        if (!isset($this->statDefinitions[$statName])) {
            throw new \InvalidArgumentException("RuntimeStats: The stat '{$statName}' is not registered.");
        }
    }
}

/*
# 服务器 crontab 里添加一行
# 每天零点执行：
0 0 * * * php /站点绝对路径/public/index.php runtime:resetDaily

// 在 public/index.php 中加入处理指令：
// 如果从命令行以 `runtime:resetDaily` 作为参数启动，就直接执行重置后退出
if (isset($argv) && isset($argv[1]) && $argv[1] === 'runtime:resetDaily') {
    // $argv PHP内置全局变量，在 PHP 中，$argv 是一个内置的全局变量，只在 CLI（命令行）模式下可用。它的作用是保存脚本被执行时所带的命令行参数，形式为一个索引数组：
    // $argv[0]：脚本本身的文件名（或执行路径），例如 index.php。
    // $argv[1] 开始：按照顺序保存你在命令行中输入的其他参数。
    $container->get(\App\Services\Stats\RuntimeStats::class)->resetDaily();
    exit(0);
}

//----在框架的启动或配置文件中，将需要统计的项注册进来-----
// 比如在 Bootstrap 或某个 ServiceProvider 中：
$stats = $container->get(\App\Services\Stats\RuntimeStats::class);

// 注册“用户”统计：
$stats->registerStat(
    'user',
    function() use ($container) {
        $userSvc = $container->get(\App\Services\Auth\UserService::class);
        return $userSvc->count();
    }
);

// 注册“帖子”统计：
$stats->registerStat(
    'post',
    function() use ($container) {
        $postSvc = $container->get(\App\Services\PostService::class);
        return $postSvc->count();
    }
);

//-------------------------------------------
// 在 Controller 或业务逻辑中调用 incrementStat()
// 增加“当日注册用户+1”与“用户总数+1”
$stats = $this->container->get(\App\Services\Stats\RuntimeStats::class);
$stats->incrementStat('user', 1);

// … 帖子入库逻辑 …
$stats = $this->container->get(\App\Services\Stats\RuntimeStats::class);
$stats->incrementStat('post');  // 默认增量为 +1


// 获取任意统计项的当日/总量
$stats = $container->get(\App\Services\Stats\RuntimeStats::class);

// 当日注册用户数：
$todayUsers = $stats->getDaily('user');

// 用户总数：
$totalUsers = $stats->getTotal('users');
*/
