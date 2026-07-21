<?php

declare(strict_types=1);

namespace Framework\Scheduler\Jobs;

use Framework\Scheduler\TaskManage;
use Framework\Scheduler\Interfaces\JobInterface;
use \Framework\Logger\LoggerInterface;

/**
 * 配置驱动任务巡检器
 *
 * 运行在调度器进程内，定期扫描已注册的"配置字段→Job 类"映射表，
 * 确保配置状态与任务活跃状态一致。
 *
 * ## 设计原则
 *
 * 1. 框架核心组件：不依赖任何业务代码，所有插件通过 register() 接入。
 * 2. 调度器自治：createTask 在调度器进程内执行，同一 Redis 连接，不需要健康检查。
 * 3. 自愈优先：异常不会中断自链（finally 保障）、崩溃后自动恢复（hasActiveTaskOfClass 兜底）。
 * 4. 错误隔离：单个 mapping 失败不影响其他 mapping。
 * 5. 维护边界：插件卸载时 deregister() 清理 mapping。
 *
 * ## 注册方式
 *
 * 在插件 ServiceProvider 或 bootstrap 钩子中调用：
 *
 *   ConfigDrivenJob::register($taskManage, [
 *       'configNamespace'  => 'well_forum',
 *       'configField'      => 'async_stats',
 *       'jobClass'         => \Plugins\well_forum\Jobs\ForumStatsSyncJob::class,
 *       'scheduleInterval' => 60,
 *       'dedupePrefix'     => 'well_forum:stats_sync',
 *       'initialDelay'     => 0,
 *       'args'             => [],
 *   ]);
 *
 * ## 自举方式
 *
 * 首次 register() 惰性自举：内部调用 bootstrap() → createTask(self::class)。
 * bootstrap() 使用 hasActiveTaskOfClass() + ignoreDedupe 双重保障，
 * 即使在调度器崩溃后 dedupeKey 残留的场景也能恢复。
 * 后续全由 handle() 自链维持，不需要任何外部触发。
 *
 * ## 响应延迟
 *
 * 配置保存到 ConfigDrivenJob 响应的最大间隔为 5 分钟（handle() 自链周期）。
 * 此为设计取舍——用启动延迟换取 FPM→Redis 健康检查耦合的消除。
 * 需要立即执行的场景通过 TaskController 手动 createTask 保留入口。
 */
class ConfigDrivenJob implements JobInterface
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var \Framework\Logger\LoggerInterface|null */
    private $logger = null;

    /** @var array[] 已注册的配置映射表 */
    private static $mappings = [];

    /** @var bool 是否已尝试自举（防止重复尝试） */
    private static $bootstrapped = false;

    /**
     * 注册一个"配置字段→Job 类"映射
     *
     * @param TaskManage $tm       TaskManage 实例
     * @param array $mapping        映射定义：
     *   - configNamespace  (string) KeyValueService 键名
     *   - configField      (string) 配置数组中的字段名
     *   - jobClass         (string) Job 完全限定类名
     *   - scheduleInterval (int)    调度间隔（秒），用于计算 dedupeKey 时间窗口
     *   - dedupePrefix     (string) dedupeKey 前缀
     *   - initialDelay     (int)    首次调度延迟（秒），默认 0
     *   - args             (array)  Job handle() 参数，默认 []
     *
     * @throws \InvalidArgumentException 缺少必填字段时
     */
    public static function register(TaskManage $tm, array $mapping): void
    {
        $required = ['configNamespace', 'configField', 'jobClass',
                     'scheduleInterval', 'dedupePrefix'];
        foreach ($required as $field) {
            if (empty($mapping[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('ConfigDrivenJob::register() missing required: %s', $field)
                );
            }
        }

        // 设置默认值
        $mapping['initialDelay'] = $mapping['initialDelay'] ?? 0;
        $mapping['args']         = $mapping['args'] ?? [];

        self::$mappings[] = $mapping;

        // 首次注册时自举：启动 ConfigDrivenJob 巡检链路
        if (!self::$bootstrapped) {
            self::$bootstrapped = true;
            self::bootstrap($tm);
        }
    }

    /**
     * 注销一个配置映射（插件卸载时调用）
     */
    public static function deregister(string $configNamespace, string $configField): void
    {
        $before = count(self::$mappings);
        self::$mappings = array_values(array_filter(
            self::$mappings,
            function (array $m) use ($configNamespace, $configField): bool {
                return !($m['configNamespace'] === $configNamespace
                      && $m['configField'] === $configField);
            }
        ));
        $removed = $before - count(self::$mappings);
        if ($removed > 0) {
            error_log(sprintf(
                '[ConfigDrivenJob] deregistered %s/%s (%d mapping(s) removed)',
                $configNamespace, $configField, $removed
            ));
        }
    }

    /**
     * 确保 ConfigDrivenJob 已启动。
     * 用于 scheduler 模式：recoverAll() 之后重新触发，让 bootstrap() 检测到已恢复的旧 CDJ 并跳过。
     * FPM 模式下 register() 自动调用 bootstrap()，无需此方法。
     *
     * @param TaskManage $tm
     */
    public static function ensureBootstrapped(TaskManage $tm): void
    {
        self::bootstrap($tm);
    }

    /**
     * 自举：将 ConfigDrivenJob 自身推入调度队列
     *
     * 双重保障：
     * 1. hasActiveTaskOfClass() 检查是否已有活跃实例
     * 2. ignoreDedupe=true 绕过残留 dedupeKey 的阻塞
     *
     * 这样即使调度器崩溃后重新启动，也能在下一个 FPM 请求的自举中恢复巡检。
     */
    private static function bootstrap(TaskManage $tm): void
    {
        if ($tm->hasActiveTaskOfClass(self::class)) {
            return; // 已有活跃实例，不需要自举
        }

        $now = time();
        $dedupeKey = 'sys:config_driven_job:' . gmdate('YmdH', $now);

        $result = $tm->createTask([
            'className'   => self::class,
            'methodName'  => 'handle',
            'args'        => [],
            'priority'    => 1,
            'scheduledAt' => $now + 60,           // 60 秒后首次巡检
            'dedupeKey'   => $dedupeKey,
        ], true); // ignoreDedupe = true

        if ($result['status'] !== 'success') {
            error_log(sprintf(
                '[ConfigDrivenJob] bootstrap failed: %s',
                $result['msg'] ?? 'unknown'
            ));
        }
    }

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    /**
     * 从容器解析日志器并记录错误
     *
     * 使用容器获取 LoggerInterface（而非构造函数注入），
     * 避免增加 ConfigDrivenJob 的构造依赖。
     * 日志器不可用时静默降级。
     */
    private function logError(string $msg): void
    {
        try {
            $this->container->get(LoggerInterface::class)->error($msg);
        } catch (\Throwable $e) {
            // 日志器不可用时静默降级（不影响主流程）
        }
    }

    /**
     * 执行一次配置巡检
     *
     * 遍历所有注册映射，维护各 Job 的活跃状态：
     * - 配置开启 & 无活跃任务 → createTask（种子任务）
     * - 配置关闭 & 有活跃任务 → let expire（不自链，自然终止）
     * - 配置与任务状态一致 → 不操作
     *
     * 自愈特性：
     * - 自链在 finally 中执行：任何异常都不会中断巡检续命
     * - 单 mapping try-catch：一个配置映射的失败不影响其他映射
     * - 每轮巡检结束后始终自链下一轮（5 分钟后）
     */
    public function handle(): array
    {
        // $tm/$kv 必须在 try 块之前初始化，
        // 因为 finally 块中需要调用 $tm->createTask() 自链。
        // 如果容器解析失败，异常在此处抛出，不进入 finally，
        // 由调度器的 TaskExecutor 捕获并重试。
        $kv      = $this->container->get(\App\Services\System\KeyValueService::class);
        $tm      = $this->container->get(TaskManage::class);
        $nextRun = time() + 300;
        $results = [];

        try {
            foreach (self::$mappings as $map) {
                try {
                    $results[] = $this->inspectMapping($kv, $tm, $map);
                } catch (\Throwable $e) {
                    $results[] = [
                        'job'    => $map['jobClass'] ?? 'unknown',
                        'action' => 'error',
                        'error'  => $e->getMessage(),
                    ];
                    $this->logError(sprintf(
                        '[ConfigDrivenJob] mapping %s/%s error: %s',
                        $map['configNamespace'] ?? '?',
                        $map['configField'] ?? '?',
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Throwable $e) {
            $this->logError(sprintf(
                '[ConfigDrivenJob] handle fatal: %s',
                $e->getMessage()
            ));
        } finally {
            // 🛡️ 自链下一轮巡检：放在 finally 中确保即使 handle() 中途异常仍能续命
            //    使用 5 分钟级 dedupeKey 匹配自链周期（300s），避免同一时钟小时内多轮自链冲突
            $dedupeKey = 'sys:config_driven_job:' . gmdate('YmdHi', (int)($nextRun / 300) * 300);
            $result = $tm->createTask([
                'className'   => self::class,
                'methodName'  => 'handle',
                'args'        => [],
                'priority'    => 1,
                'scheduledAt' => $nextRun,
                'dedupeKey'   => $dedupeKey,
            ], true);
            if ($result['status'] !== 'success') {
                $this->logError(sprintf(
                    '[ConfigDrivenJob] self-chain failed: %s',
                    $result['msg'] ?? 'unknown'
                ));
            }
        }

        return [
            'status'  => empty(array_filter($results, function ($r) { return ($r['action'] ?? '') === 'error'; })) ? 'success' : 'partial',
            'results' => $results,
        ];
    }

    /**
     * 检查单个配置映射
     */
    private function inspectMapping(
        \App\Services\System\KeyValueService $kv,
        TaskManage $tm,
        array $map
    ): array {
        $config    = $kv->settingGet($map['configNamespace']) ?: [];
        $shouldRun = !empty($config[$map['configField']]);
        $hasActive = $tm->hasActiveTaskOfClass($map['jobClass']);
        $now       = time();

        // v3.4: 配置关闭时清理 MySQL 中同类 pending/retrying 孤儿任务
        // 此路径非热路径（每 5 分钟/配置关闭时），不做复合索引，
        // 游标分页扫几千行可接受。
        if (!$shouldRun && $tm->isV2DualWriteActive()) {
            try {
                $cancelled = $tm->cancelTasksByClass($map['jobClass']);
                if ($cancelled > 0) {
                    $this->logError(sprintf(
                        '[ConfigDrivenJob] cancelled %d orphan %s tasks (config turned off)',
                        $cancelled, $map['jobClass']
                    ));
                }
            } catch (\Throwable $e) {
                $this->logError(sprintf(
                    '[ConfigDrivenJob] orphan cleanup failed for %s: %s',
                    $map['jobClass'], $e->getMessage()
                ));
            }
        }

        if ($shouldRun && !$hasActive) {
            // 配置开启但无活跃任务 → 创建种子任务
            $intervalSec = max(1, (int)$map['scheduleInterval']);
            $scheduledAt = $now + (int)$map['initialDelay'];
            $windowKey   = gmdate('YmdHi', (int)($scheduledAt / $intervalSec) * $intervalSec);
            $dedupeKey   = $map['dedupePrefix'] . ':' . $windowKey;

            // 使用 `:seed` 前缀的 dedupeKey，避免 v2 PersistenceQueue（MySQL 唯一索引）因空串冲突
            // 同时与 Job 自链的 dedupeKey 区隔（自链无 `:seed` 后缀），不阻塞自链也不阻塞手动作业
            $result = $tm->createTask([
                'className'   => $map['jobClass'],
                'methodName'  => 'handle',
                'args'        => $map['args'],
                'priority'    => 5,
                'scheduledAt' => $scheduledAt,
                'dedupeKey'   => $map['dedupePrefix'] . ':seed:' . $windowKey,
            ]);

            $status = $result['status'] ?? 'unknown';
            if ($status !== 'success') {
                $this->logError(sprintf(
                    '[ConfigDrivenJob] createTask %s failed: %s',
                    $map['jobClass'], $result['msg'] ?? ''
                ));
            }

            return [
                'job'    => $map['jobClass'],
                'action' => 'created',
                'status' => $status,
            ];
        }

        if (!$shouldRun && $hasActive) {
            // 配置关闭但有活跃任务 → 让自链自然终止（不自链新任务）
            // 无需强制取消，任务执行完当前代后不再续链
            return [
                'job'    => $map['jobClass'],
                'action' => 'will_expire',
            ];
        }

        return [
            'job'    => $map['jobClass'],
            'action' => 'consistent',
        ];
    }
}
