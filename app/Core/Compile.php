<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Core;

if (!defined('IN_WELLCMS')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo 'Access Denied';
    return;
}

use Framework\Core\Container;

/**
 * Class Compile
 * 运行时代码/模板编译与插件钩子合并缓存
 * — 所有文件都编译并写入 tmp/
 * — 功能：模板包含、插件覆盖、钩子注入、压缩、DI容器预编译缓存
 * — 配置来自 config/App.php
 */
class Compile
{
    private const STATIC_RECYCLE_GRACE_SECONDS = 604800;
    private const TEMPLATE_MAX_BYTES = 8388608;

    // 配置数组
    protected
    /** @var array */
    static $config = [];
    // 钩子集合：钩子名 => [文件路径,...]
    protected
    /** @var array */
    static $hooks = [];
    // 已启用插件信息列表
    protected
    /** @var array */
    static $enabledPlugins = [];
    // 覆盖文件缓存：覆盖路径 => [path=>文件原始路径, rank=>优先级]
    protected
    /** @var array */
    static $overwriteCache = [];
    // 初始化标记
    protected
    /** @var bool */
    static $initialized = false;
    // 编译清单数据 (Manifest)
    protected
    /** @var array */
    static $manifest = [];
    // 清单对应的实际输入内容指纹 (仅后台巡检与重建时计算并落盘，请求路径不校验)
    protected
    /** @var string */
    static $manifestInputFingerprint = '';
    // 当前 manifest 所属的显式失效代际 (artifact_epoch 标记值)
    protected
    /** @var string */
    static $manifestArtifactEpoch = '';

    private static $assetManifestCache = null;
    private static $assetManifestCachePath = '';

    public static function init(?string $containerCache): void
    {
        // 确保全局只初始化一次
        if (self::$initialized && (!defined('DEBUG') || (int)\DEBUG === 0)) {
            return;
        }

        self::$assetManifestCache = null;
        self::$assetManifestCachePath = '';

        // 1. 加载基础配置 (极简加载)
        $cfgFile = APP_PATH . 'config/App.php';
        self::$config = file_exists($cfgFile) ? require $cfgFile : [
            'tmp_path' => './storage/tmp/',
            'compress' => 1,
            'disabled_plugin' => 0,
            'compile_fsync' => false,
        ];

        // 注意：此路径独立于 ConfigServiceProvider 处理
        // 原因：Compile::init() 在 ConfigServiceProvider::register() 之前执行（见 index.php:52-62）
        // 确保 tmp_path 为绝对目录
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        self::$config['tmp_path'] = $appPath . ltrim(self::$config['tmp_path'], './');

        // 2. 尝试从清单 (Manifest) 加载插件信息与钩子 (生产环境 O(1) 核心)
        $manifestFile = self::$config['tmp_path'] . 'configs/compile_manifest.php';
        $debug = defined('DEBUG') ? (int)constant('DEBUG') : 0;

        if ($debug === 0) {
            $loadedManifest = null;
            if (file_exists($manifestFile)) {
                try {
                    $candidate = require $manifestFile;
                    if (is_array($candidate)) {
                        $loadedManifest = $candidate;
                    }
                } catch (\Throwable $e) {
                    $loadedManifest = null;
                }
            }

            // 生产请求路径只比对显式失效标记 (artifact_epoch) 与发布根，
            // 不再计算插件树内容指纹：请求内不做全量文件哈希。
            $currentRoot = self::applicationRootIdentity();
            $currentEpoch = self::artifactEpoch();
            $recordedEpoch = is_array($loadedManifest)
                && isset($loadedManifest['artifact_epoch'])
                && is_string($loadedManifest['artifact_epoch'])
                ? $loadedManifest['artifact_epoch']
                : null;
            $manifestIsCurrent = is_array($loadedManifest)
                && isset($loadedManifest['app_root'])
                && $loadedManifest['app_root'] === $currentRoot
                && $recordedEpoch !== null
                && hash_equals($recordedEpoch, $currentEpoch);

            if ($manifestIsCurrent) {
                self::$manifest = $loadedManifest;
                self::$manifestArtifactEpoch = $currentEpoch;
                self::$manifestInputFingerprint = isset($loadedManifest['input_fingerprint'])
                    && is_string($loadedManifest['input_fingerprint'])
                    ? $loadedManifest['input_fingerprint']
                    : '';
                self::$hooks = self::$manifest['hooks'] ?? [];
                self::$overwriteCache = self::$manifest['overwriteCache'] ?? [];
                self::$enabledPlugins = self::$manifest['enabledPlugins'] ?? [];
            } else {
                // 清单缺失/损坏（冷启动）或显式换代：稳定重扫并重建清单。
                $coldStart = !is_array($loadedManifest);
                self::rebuildManifest($manifestFile);
                if ($coldStart) {
                    // 无代际信息可依，按冷启动重建固定产物 —— 此时缓存通常本就不存在。
                    // 显式换代（epoch 变更）的清理由发布标记的一方完成（flushCache / 后台任务），
                    // 请求路径不再清空编译缓存，避免单次请求被冷重建拖慢。
                    $invalidated = self::clearCompileCaches(true);
                    if ($invalidated['errors'] > 0) {
                        throw new \RuntimeException('Unable to invalidate stale compile cache files');
                    }
                }
            }
        } else {
            // DEBUG 1/2 快速路径：只做一次钩子重扫，跳过指纹校验与清单落盘。
            self::loadPluginHooks();
            self::buildOverwriteCache();
            self::$manifest = [
                'hooks' => self::$hooks,
                'overwriteCache' => self::$overwriteCache,
                'enabledPlugins' => self::$enabledPlugins,
            ];
            self::$manifestInputFingerprint = '';
        }

        // 结构化编译目录由 atomicWrite() 在发布产物时按需创建，请求路径不做预创建探测。

        // 静态资源聚合编译
        if (!file_exists(self::assetManifestPath()) || (defined('DEBUG') && \DEBUG > 1)) {
            self::compileAssets();
        }

        // FPM 与 Swoole 入口仅在生产模式加载这些定义；调试请求不生成或校验闲置产物。
        if ($containerCache && $debug === 0 && !file_exists($containerCache)) {
            self::compileContainer($containerCache);
        }

        self::$initialized = true;
    }

    /**
     * include 方法：获取编译后的缓存文件路径，必要时重新编译
     * @param string $srcFile 原始源文件路径
     * @return string 编译后缓存文件路径
     */
    public static function include(string $srcFile)
    {
        $debug = defined('DEBUG') ? (int)constant('DEBUG') : 0;
        // 编译器尚未初始化时先确认源文件存在，避免为一个不存在的文件触发清单重建
        // (正常入口在 include 前已 init，此分支只服务于越界调用)
        if (empty(self::$config) && !file_exists($srcFile)) {
            return $srcFile;
        }
        // 缓存优先：命中即返回，生产路径只做一次 stat
        // (open_basedir 下每次 stat 要逐分量校验路径，实测可省 1.3-2.3ms/请求)
        $cacheFile = (string)self::getCachePath($srcFile);
        $cacheExists = file_exists($cacheFile);
        if ($debug === 0) {
            if ($cacheExists) return $cacheFile;
            if (!file_exists($srcFile)) return $srcFile;
        } elseif (!file_exists($srcFile)) {
            return $srcFile;
        }

        if ($debug > 1 || !$cacheExists || filemtime($srcFile) > @filemtime($cacheFile)) {
            self::atomicWrite($cacheFile, (string)self::compile($srcFile));
        }

        return $cacheFile;
    }

    /**
     * 显式刷新一个已修改文件的编译产物，不清理其它文件或改变生产缓存策略。
     */
    public static function refreshFile(string $srcFile): void
    {
        if (!is_file($srcFile)) {
            throw new \RuntimeException("Unable to refresh missing source file: {$srcFile}");
        }
        $cacheFile = (string)self::getCachePath($srcFile);
        self::atomicWrite($cacheFile, (string)self::compile($srcFile));
        clearstatcache(true, $srcFile);
        clearstatcache(true, $cacheFile);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($srcFile, true);
            opcache_invalidate($cacheFile, true);
        }
    }

/**
* includeLang 方法：高性能语言包加载入口
* 将核心包、插件包、插件钩子三层资产压平为单体缓存
*/
public static function includeLang(string $locale, string $type, array $themeFiles = [])
{
if (empty(self::$config)) {
self::init(null);
}
$langDir = rtrim(self::$config['tmp_path'], '/') . '/langs/';
$themeIdentity = [];
foreach ($themeFiles as $themeFile) {
$themeIdentity[] = str_replace('\\', '/', (string)$themeFile);
}
// 固定路径 + 原子覆盖；多主题隔离通过主题键后缀实现（与代际无关）
$cacheFile = $langDir . "{$locale}_{$type}.php";
if ($themeFiles) {
$themeKey = hash('sha256', implode("\0", $themeIdentity));
$cacheFile = $langDir . "{$locale}_{$type}.{$themeKey}.php";
}

$debug = defined('DEBUG') ? (int)constant('DEBUG') : 0;

// 如果在生产环境（DEBUG === 0）且缓存存在，直接返回，实现 O(1) 访问
if ($debug === 0 && file_exists($cacheFile)) {
return $cacheFile;
}

if (!file_exists($cacheFile) || $debug > 1) {
self::compileLanguage($locale, $type, $cacheFile, $themeFiles);
}

return $cacheFile;
}

/**
* 语言包核心编译压平逻辑
* 按照 核心 -> 插件包 -> 主题包 -> 插件钩子 顺序进行物理合并
*/
protected static function compileLanguage(string $locale, string $type, string $cacheFile, array $themeFiles = []): void
{
$appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
$allLang = [];

// 1. Layer 1: 加载核心包 (Base)
$coreFile = $appPath . "app/Language/{$locale}/{$type}.php";
if (file_exists($coreFile)) {
$allLang = include $coreFile;
}

// 2. Layer 2: 加载已启用插件的完整语言包
$enabled = self::getEnabledPlugins();
foreach ($enabled as $plugin) {
$pluginFile = $plugin['path'] . "/Language/{$locale}/{$type}.php";
if (file_exists($pluginFile)) {
$pLang = include $pluginFile;
if (is_array($pLang)) {
$allLang = array_replace_recursive($allLang, $pLang);
}
}
}

// 3. Layer 3: 加载主题语言包 (更高优先级)
foreach ($themeFiles as $tFile) {
if (file_exists($tFile)) {
$tLang = include $tFile;
if (is_array($tLang)) {
$allLang = array_replace_recursive($allLang, $tLang);
}
}
}

// 3. Layer 3: 提取并聚合插件钩子片段 (Snippet)
$hookName = "language_{$locale}_{$type}";
$hookCode = '';
if (isset(self::$hooks[$hookName])) {
foreach (self::$hooks[$hookName] as $hookFile) {
if (self::isAllowedHookFile($hookFile)) {
$code = file_get_contents($hookFile);
$code = self::sanitizeHookCode($code);
if ($code === null) {
error_log("Security: Malformed hook file skipped: {$hookFile}");
continue;
}
// Token 级 AST 安全校验（深度防御：防止正则绕过）
if (!self::validateHookPhpCode($code, $hookFile)) {
continue;
}
$hookCode .= "\n// Hook from: " . basename(dirname(dirname($hookFile))) . "\n" . trim($code) . "\n";
}
}
}

// 构造物理产物内容
$content = "<?php\n/** WellCMS Compiled Language Cache - O(1) Performance **/\n";
        $content .= "if (!defined('IN_WELLCMS')) { if (PHP_SAPI !== 'cli') { http_response_code(403); } echo 'Access Denied'; return; }\n\n";
        $content .= "\$lang = " . var_export($allLang, true) . ";\n\n";

        if ($hookCode) {
            $content .= "/** --- Plugin Hooks Snippets --- **/\n";
            $content .= "\$lang = array_replace_recursive(\$lang, [\n";
            $content .= $hookCode;
            $content .= "]);\n";
        }

        $content .= "\nreturn \$lang;";

        self::atomicWrite($cacheFile, $content);
    }

    /**
     * 编译 DI 容器定义
     * 提取核心服务的反射信息并缓存，减少运行时的反射开销
     */
    protected static function compileContainer(string $outputFile): void
    {
        // 确保目录存在
        $dir = dirname($outputFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // 定义需要预编译的高频核心服务
        // 这些类在每次请求中几乎都会用到，预编译收益最大
        $classesToCompile = [
            // 核心 Service 层
            \App\Services\Auth\UserService::class,
            \App\Services\Auth\GroupService::class,
            \App\Services\System\LogService::class,
            \App\Services\Auth\SessionService::class,
            \App\Services\System\CacheService::class,
            \Framework\Cache\CacheManager::class,

            // 核心 Job (任务队列)
            \App\Jobs\ImageCleanupJob::class,
            \App\Jobs\UploadToCloudJob::class,
            \App\Jobs\VerifyIntegrityJob::class,
            \Framework\Scheduler\Jobs\GenerateClassmapJob::class,
            \Framework\Scheduler\Jobs\CallbackJob::class,

            // 调度器核心
            \Framework\Scheduler\TaskExecutor::class,
            \Framework\Scheduler\Task::class,
            \Framework\Scheduler\TaskManage::class,
            \Framework\Scheduler\RedisTaskQueue::class,
            \Framework\Scheduler\HttpResultCallback::class,

            // HTTP & PSR-7 基础设施
            \Framework\Http\Psr7\Factories\ServerRequestFactory::class,
            \Framework\Http\Psr7\Factories\ResponseFactory::class,
            \Framework\Http\Psr7\Factories\StreamFactory::class,
            \Framework\Http\Psr7\Factories\UploadedFileFactory::class,
            \Framework\Http\Psr7\Factories\UriFactory::class,
            \Framework\Http\Psr7\ResponseSender::class,

            // 核心中间件 (app/Middleware)
            \App\Middleware\RuntimeMiddleware::class,
            \App\Middleware\LanguageMiddleware::class,
            \App\Middleware\SessionMiddleware::class,
            \App\Middleware\RouterMiddleware::class,
            \App\Middleware\MetaDispatcherMiddleware::class,
            \App\Middleware\ErrorHandlerMiddleware::class,
            \App\Middleware\XssFilterMiddleware::class,
            \App\Middleware\AuthMiddleware::class,
            \App\Middleware\AdminSignInMiddleware::class,
            \App\Middleware\CsrfMiddleware::class,
            \App\Middleware\ThrottleMiddleware::class,
            \App\Middleware\TokenMiddleware::class,
            \App\Middleware\UserPermMiddleware::class,

            // 框架级中间件组件 (src/Http/Middleware)
            \Framework\Http\Middleware\MiddlewareFactory::class,
            \Framework\Http\Middleware\Pipeline::class,
            \Framework\Http\Middleware\RequestProcessorMiddleware::class,

            // 语言与国际化管理 (app/I18n)
            \App\I18n\LanguageManager::class,
            \App\I18n\LocaleMapper::class,

            // 系统工具与路径管理 (app/Utils)
            \Framework\Utils\Validator::class,
            \App\Utils\I18nDateFormatter::class,

            // Session 业务实现 (app/Session)
            \App\Session\Service\SessionManager::class,
            \App\Session\Handler\DatabaseSessionHandler::class,

            // 核心引擎驱动与路由 (src/Core, src/Database...)
            \Framework\Core\Config::class,
            \Framework\Database\Driver\PdoDriver::class,
            \Framework\Database\ProxyDriver::class,
            \Framework\Http\Router\Router::class,
            \Framework\Http\Router\CompiledRouter::class,
            \Framework\Session\Session::class,
            \App\Factory\ControllerFactory::class,
            \App\Meta\MetaRegistry::class,
            // 列表、详情页中实际使用的模型与基础服务
            \App\Models\CacheModel::class,
            \App\Models\GroupModel::class,
            \App\Models\IpListModel::class,
            \App\Models\KeyValueModel::class,
            \App\Models\NavigationModel::class,
            \App\Models\SessionDataModel::class,
            \App\Models\SessionModel::class,
            \App\Models\TempContentModel::class,
            \App\Models\UserModel::class,
            \App\Core\Kernel::class,
            \App\Middleware\RequestIdMiddleware::class,
            \App\Services\Auth\SessionDataService::class,
            \App\Services\Auth\TokenService::class,
            \App\Services\Content\NavigationService::class,
            \App\Services\Content\TempContentService::class,
            \App\Services\Storage\StorageManager::class,
            \App\Services\System\IpListService::class,
            \App\Services\System\KeyValueService::class,
            \App\Services\System\MenuService::class,

        ];

        // 插件自行声明构造定义，仅在重建容器缓存时执行现有钩子。
        if (empty(self::$config['disabled_plugin'])) {
            $hookCode = self::injectHooks('app_Core_Compile_compileContainer_classes.php');
            if ($hookCode !== '') {
                $hookCacheFile = rtrim(self::$config['tmp_path'], '/') . '/configs/container_classes.php';
                // 沿用物理编译产物；Hook 仅取得类名单，不共享编译器的局部变量。
                self::atomicWrite($hookCacheFile, "<?php\n"
                    . "if (!defined('IN_WELLCMS')) { if (PHP_SAPI !== 'cli') { http_response_code(403); } echo 'Access Denied'; return; }\n"
                    . "return static function (array \$classesToCompile): array {\n"
                    . $hookCode . "\nreturn \$classesToCompile;\n};\n");
                // 升级后立即加载新声明，不等待 OPcache 的时间戳检查窗口。
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($hookCacheFile, true);
                }
                $extendClasses = require $hookCacheFile;
                $classesToCompile = $extendClasses($classesToCompile);
            }
        }

        $definitions = [];
        $container = new Container(); // 临时实例用于反射提取

        foreach ($classesToCompile as $class) {
            $def = $container->getReflectionDefinition($class);
            if ($def) $definitions[$class] = $def;
        }

        if (!empty($definitions)) {
            $content = "<?php return " . var_export($definitions, true) . ";";
            self::atomicWrite($outputFile, $content);
        }
    }

    /**
     * 获取编译缓存路径 (重构版：分层寻址)
     * @return string
     */
    private static function getCachePath(string $srcFile)
    {
        if (empty(self::$config)) {
            self::init(null);
        }
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));

        // 1. 确定子目录类型
        $subDir = 'classes'; // 默认进入类定义
        if (in_array($ext, ['htm', 'html', 'tpl'])) {
            $subDir = 'views';
        }

        // 2. 生成相对路径作为文件名（保持可读性，但使用分层目录）
        // 例如：app/Controllers/IndexController.php -> tmp/classes/app/Controllers/IndexController.php
        $relative = ltrim(substr($srcFile, strlen($appPath)), '/\\');
        $relative = strtr($relative, "/\\", DIRECTORY_SEPARATOR);

        // 固定路径 + rename 原子覆盖：require 要么读到旧完整文件要么新完整文件，
        // 编译产物永远只有一份，不存在代际孤儿
        $cachePath = rtrim(self::$config['tmp_path'], '/') . '/' . $subDir . '/' . $relative;

        // 实际发布时由 atomicWrite 创建目录；缓存加载不重复探测父目录。
        return $cachePath;
    }

    /**
     * 资产清单路径（固定路径，原子覆盖）。
     */
    private static function assetManifestPath(): string
    {
        return rtrim(self::$config['tmp_path'], '/') . '/configs/asset_manifest.php';
    }

    /**
     * 发布新的不可变生成代，清空所有缓存状态 (Manifest、钩子、覆盖、插件列表)
     */
    public static function flushCache(): void
    {
        if (empty(self::$config)) {
            self::init(null);
        }

        $recycled = self::recycleArtifacts(true);
        if ($recycled['errors'] > 0) {
            throw new \RuntimeException('Unable to clear all application compile cache files: '
                . implode('; ', $recycled['error_messages']));
        }

        // 固定缓存清理成功后再完整发布 epoch，避免失败清理被报告为成功换代。
        self::atomicWrite(self::artifactEpochPath(), bin2hex(random_bytes(32)));

        // 调用方随后按原固定路径重建启动级 manifest 与编译产物。
        self::$config = [];
        self::$hooks = [];
        self::$enabledPlugins = [];
        self::$overwriteCache = [];
        self::$manifest = [];
        self::$manifestInputFingerprint = '';
        self::$manifestArtifactEpoch = '';
        self::$initialized = false;
        self::$assetManifestCache = null;
        self::$assetManifestCachePath = '';
    }

    /**
     * 显式失效巡检：仅在后台计算插件树内容指纹，生产请求路径不再做全量哈希。
     *
     * 与 manifest 记录的输入指纹比对：
     *   - 一致：漂移探测器静默，不做任何写入
     *   - 不一致：按不可交换的顺序收敛 ——
     *       ① flushCache()  清空固定编译缓存并发布新 epoch（64 位十六进制标记）
     *       ② init($containerCache) 在原固定路径重建 manifest / container.php / asset manifest
     *     顺序不可交换：先清空再发布标记，任何并发请求都不会观察到“已换代但产物未清”的中间态。
     *
     * @param string|null $containerCache 需要一并重建的容器定义缓存路径；沙箱调用传 null
     * @return array{stale:bool,fingerprint:string,recorded:string,epoch:string}
     */
    public static function publishInvalidationIfStale(?string $containerCache = null): array
    {
        if (empty(self::$config)) {
            self::init(null);
        }

        $manifestFile = self::$config['tmp_path'] . 'configs/compile_manifest.php';
        $recorded = '';
        if (is_file($manifestFile)) {
            try {
                $loaded = require $manifestFile;
                if (is_array($loaded)
                    && isset($loaded['input_fingerprint'])
                    && is_string($loaded['input_fingerprint'])) {
                    $recorded = $loaded['input_fingerprint'];
                }
            } catch (\Throwable $e) {
                $recorded = '';
            }
        }

        $current = self::calculateManifestInputFingerprint();
        if ($recorded !== '' && hash_equals($recorded, $current)) {
            return [
                'stale' => false,
                'fingerprint' => $current,
                'recorded' => $recorded,
                'epoch' => self::artifactEpoch(),
            ];
        }

        self::flushCache();
        self::init($containerCache);

        return [
            'stale' => true,
            'fingerprint' => $current,
            'recorded' => $recorded,
            'epoch' => self::artifactEpoch(),
        ];
    }

    /**
     * 编译缓存根目录 (含尾部斜杠)。供回收任务定位资产清单。
     * @return string
     */
    public static function getTmpPath(): string
    {
        if (empty(self::$config)) {
            self::init(null);
        }
        return rtrim(self::$config['tmp_path'], '/') . '/';
    }

    /**
     * 立即回收已失效的编译与静态产物。
     *
     * 后台手工清理会清空固定路径编译缓存并立即删除未引用的指纹静态资源；
     * Scheduler 只回收超过宽限期的静态资源，不会使当前固定缓存失效。
     *
     * @param bool $clearCompileCache 是否清空 storage/tmp 固定路径编译缓存
     * @return array{files:int,bytes:int,errors:int,error_messages:array,execution_ms:float}
     */
    public static function recycleArtifacts(bool $clearCompileCache = false): array
    {
        if (empty(self::$config) || self::$manifest === []) {
            self::init(null);
        }

        $start = microtime(true);
        $stats = [
            'files' => 0,
            'bytes' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];

        $manifest = [];
        $manifestFile = self::assetManifestPath();
        if (is_file($manifestFile)) {
            try {
                $candidate = require $manifestFile;
                $manifest = is_array($candidate) ? $candidate : [];
            } catch (\Throwable $e) {
                $stats['errors']++;
                $stats['error_messages'][] = "Unable to read active asset manifest: {$manifestFile}";
            }
        }

        $activeAssets = [];
        $collect = function ($value) use (&$collect, &$activeAssets): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $collect($item);
                }
                return;
            }
            if (is_string($value) && $value !== '') {
                $activeAssets[basename($value)] = true;
            }
        };
        $collect($manifest);

        if ($clearCompileCache) {
            $compileStats = self::clearCompileCaches(false);
            foreach (['files', 'bytes', 'errors'] as $key) {
                $stats[$key] += $compileStats[$key];
            }
            $stats['error_messages'] = array_merge(
                $stats['error_messages'],
                $compileStats['error_messages']
            );
        }

        self::recycleStaticDirectory(
            (defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR)
                . 'public/static/runtime',
            $activeAssets,
            time() - ($clearCompileCache ? 0 : self::STATIC_RECYCLE_GRACE_SECONDS),
            $stats
        );

        $stats['execution_ms'] = (microtime(true) - $start) * 1000;
        return $stats;
    }

    /**
     * 清空 class/view/lang/configs 固定路径缓存，保留目录与版本控制占位文件。
     */
    private static function clearCompileCaches(bool $preserveManifest): array
    {
        $stats = [
            'files' => 0,
            'bytes' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];
        $tmpPath = rtrim(self::$config['tmp_path'], '/\\') . DIRECTORY_SEPARATOR;
        foreach (['classes', 'views', 'langs', 'configs'] as $directory) {
            self::clearCompileDirectory(
                $tmpPath . $directory,
                $preserveManifest,
                $stats
            );
        }
        self::clearFixedRuntimeCaches($tmpPath, $stats);
        return $stats;
    }

    /**
     * 清空单个编译缓存目录。
     */
    private static function clearCompileDirectory(
        string $directory,
        bool $preserveManifest,
        array &$stats
    ): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException $e) {
            $stats['errors']++;
            $stats['error_messages'][] = "Unable to scan compile cache directory: {$directory}";
            return;
        }

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }
            if (!$item->isFile() || $item->getFilename()[0] === '.') {
                continue;
            }
            if ($preserveManifest && $item->getFilename() === 'compile_manifest.php') {
                continue;
            }

            self::unlinkRecycledArtifact($path, $item->getSize(), $stats);
        }
    }

    /**
     * 清理 tmp 根目录中明确属于运行缓存的固定文件，保护 PID 与 epoch。
     */
    private static function clearFixedRuntimeCaches(string $tmpPath, array &$stats): void
    {
        $targets = [
            $tmpPath . 'classmap.php',
            $tmpPath . 'container.php',
            $tmpPath . 'template_map.php',
        ];
        $versionedTemplateMaps = glob($tmpPath . 'template_map.' . str_repeat('[a-f0-9]', 64) . '.php');
        if (is_array($versionedTemplateMaps)) {
            $targets = array_merge($targets, $versionedTemplateMaps);
        }

        foreach ($targets as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (!is_file($path)) {
                $stats['errors']++;
                $stats['error_messages'][] = "Invalid fixed runtime cache target: {$path}";
                continue;
            }
            self::unlinkRecycledArtifact($path, (int)filesize($path), $stats);
        }
    }

    /**
     * 回收当前清单未引用且超过浏览器/CDN宽限期的指纹静态资源。
     */
    private static function recycleStaticDirectory(
        string $directory,
        array $activeAssets,
        int $deadline,
        array &$stats
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException $e) {
            $stats['errors']++;
            $stats['error_messages'][] = "Unable to scan static cache directory: {$directory}";
            return;
        }

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }

            $name = $item->getFilename();
            if (!$item->isFile()
                || preg_match('/\.[a-f0-9]{8}\.[^.]+$/Di', $name) !== 1
                || isset($activeAssets[$name])
                || $item->getMTime() > $deadline) {
                continue;
            }

            self::unlinkRecycledArtifact($path, $item->getSize(), $stats);
        }
    }

    /**
     * 删除单个已确认失效的生成物并累计结果。
     */
    private static function unlinkRecycledArtifact(string $path, int $size, array &$stats): void
    {
        if (@unlink($path)) {
            $stats['files']++;
            $stats['bytes'] += $size;
            return;
        }

        $stats['errors']++;
        $stats['error_messages'][] = "Unable to recycle cache artifact: {$path}";
    }

    /**
     * compile 方法：执行插件覆盖、钩子注入、模板包含、压缩等流程
     * @return void
     */
    private static function compile(string $srcFile)
    {
        $hookCache = [];
        $isTemplate = in_array(strtolower(pathinfo($srcFile, PATHINFO_EXTENSION)), ['htm', 'html', 'tpl'], true);
        $content = self::readCompileSource($srcFile, $hookCache, $isTemplate);
        if ($isTemplate && stripos($content, '<template') !== false) {
            if (!class_exists(TemplateCompiler::class, false)) {
                require __DIR__ . '/TemplateCompiler.php';
            }
            $compiler = new TemplateCompiler(
                defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/',
                self::getEnabledPlugins(),
                function (string $file) use (&$hookCache): string {
                    return self::readCompileSource($file, $hookCache, true);
                },
                function (string $file): string {
                    return empty(self::$config['disabled_plugin']) ? self::resolveOverwrite($file) : $file;
                }
            );
            $content = $compiler->compile($srcFile, $content);
        }

        // 压缩逻辑
        if ((!defined('DEBUG') || (int)\DEBUG === 0) && self::$config['compress'] > 0) {
            $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                // PHP文件压缩去空格
                $tmpDir = sys_get_temp_dir();
                try {
                    $tmpFile = tempnam($tmpDir, 'wellcms-php-');
                } catch (\ErrorException $e) {
                    throw new \ErrorException(
                        $e->getMessage() . " (PHP compile source: {$srcFile}; temporary directory: {$tmpDir})",
                        $e->getCode(), $e->getSeverity(), $e->getFile(), $e->getLine(), $e
                    );
                }
                if ($tmpFile === false || realpath(dirname($tmpFile)) !== realpath($tmpDir)) {
                    if (is_string($tmpFile) && is_file($tmpFile)) {
                        @unlink($tmpFile);
                    }
                    throw new \RuntimeException("Unable to create PHP compile temporary file: {$srcFile}");
                }

                $cleanupFailed = false;
                try {
                    self::atomicWrite($tmpFile, $content);
                    $compressed = php_strip_whitespace($tmpFile);
                    if (!is_string($compressed)) {
                        throw new \RuntimeException("Unable to compress PHP compile source: {$srcFile}");
                    }
                    $content = $compressed;
                } finally {
                    $cleanupFailed = is_file($tmpFile) && !unlink($tmpFile);
                }
                if ($cleanupFailed) {
                    throw new \RuntimeException("Unable to remove PHP compile temporary file: {$tmpFile}");
                }
            } else {
                // 模板文件压缩
                $content = preg_replace([
                    '#<!--.*?-->#s',
                    '#[\r\n\t]+#',
                    '#>\s+<#'
                ], ['', ' ', '><'], $content);
                if ((int)self::$config['compress'] === 2) {
                    $content = preg_replace('/\s+/', ' ', $content);
                }
            }
        }

        // 模板资产占位符物理替换 (工业级资产聚合点)
        $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
        if (in_array($ext, ['htm', 'html', 'tpl'])) {
            $content = self::processAssets($content);
        }

        return $content;
    }

    /** 原模板与展开的子模板使用同一套覆盖及钩子规则。 */
    private static function readCompileSource(string $srcFile, ?array &$hookCache = null, bool $bounded = false): string
    {
        // 如果禁用插件，则跳过覆盖和钩子
        if (!empty(self::$config['disabled_plugin'])) {
            $useFile = $srcFile;
            $content = self::readCompileFile($useFile, $bounded);
        } else {
            // 解析覆盖逻辑
            $useFile = self::resolveOverwrite($srcFile);
            $content = self::readCompileFile($useFile, $bounded);

            // 钩子注入（-1 = 单次全替换，retry 仅作安全网）
            $compiledPattern = '#(?:<!--{hook\s+([^}]+?)}-->|//\s*hook\s+(\S+))#s';
            $retryCount = 3;
            do {
                // 快速筛查
                if (strpos($content, 'hook') === false) break;

                // 统一替换
                $expandedBytes = strlen($content);
                $newContent = preg_replace_callback(
                    $compiledPattern,
                    function ($matches) use (&$expandedBytes, &$hookCache, $bounded) {
                        $code = self::injectHooks($matches[2] ?? $matches[1], $hookCache, $bounded);
                        $expandedBytes += strlen($code) - strlen($matches[0]);
                        if ($bounded && $expandedBytes > self::TEMPLATE_MAX_BYTES) {
                            throw new \RuntimeException('Template hook expansion size limit exceeded');
                        }
                        return $code;
                    },
                    $content,
                    -1, // 每轮全部替换
                    $count
                );

                // 错误处理
                if ($newContent === null || preg_last_error() !== PREG_NO_ERROR) {
                    $errorMsg = function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'Regex error: ' . preg_last_error();
                    throw new \RuntimeException($errorMsg);
                }

                $content = $newContent;
            } while ($count > 0 && --$retryCount > 0);
        }

        return $content;
    }

    private static function readCompileFile(string $file, bool $bounded = false): string
    {
        $content = $bounded
            ? file_get_contents($file, false, null, 0, self::TEMPLATE_MAX_BYTES + 1)
            : file_get_contents($file);
        if (!is_string($content)) {
            throw new \RuntimeException('Unable to read compile source: ' . $file);
        }
        if ($bounded && strlen($content) > self::TEMPLATE_MAX_BYTES) {
            throw new \RuntimeException('Template source size limit exceeded: ' . $file);
        }
        return $content;
    }

/**
* 前端资源聚合编译引擎 (Industrial Assets Aggregator)
*/
public static function compileAssets(): void
{
$appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
$runtimePath = $appPath . 'public/static/runtime/';
if (!is_dir($runtimePath)) @mkdir($runtimePath, 0755, true);

$enabled = self::getEnabledPlugins();
$manifest = ['css' => [], 'js' => []];
$groups = [];

// 1. 扫描插件中的资产声明
foreach ($enabled as $plugin) {
$assetsNodes = $plugin['config']['assets'] ?? [];
if (empty($assetsNodes)) continue;

// 支持两种格式：
// 1. 扁平格式: "assets": {"css": [], "js": []} -> 自动归类为 global
// 2. 分组格式: "assets": {"admin": {"css": []}, "global": {"js": []}}

// 判定是否为分组格式
$isGrouped = false;
foreach ($assetsNodes as $key => $val) {
if (is_array($val) && !in_array($key, ['css', 'js'])) {
$isGrouped = true;
break;
}
}

$currentNodes = $isGrouped ? $assetsNodes : ['global' => $assetsNodes];

foreach ($currentNodes as $groupName => $types) {
if (!isset($groups[$groupName])) {
$groups[$groupName] = ['css' => '', 'js' => ''];
}
if (!isset($manifest['external'][$groupName])) {
$manifest['external'][$groupName] = ['css' => [], 'js' => []];
}

foreach (['css', 'js'] as $type) {
foreach ($types[$type] ?? [] as $file) {
// 1. 识别外部链接 (CDN)
if (preg_match('#^https?://|^//#i', $file)) {
$manifest['external'][$groupName][$type][] = $file;
continue;
}

// 2. 本地文件物理处理
if (strpos($file, '@core/') === 0) {
// @core/ 前缀 → 引用 app/views/js/core/ 下的核心模块
// basename() 确保只取文件名，杜绝路径穿越
$coreFile = basename(substr($file, 6));
// 防止 @core/ 后无文件名导致 file_get_contents 对目录调用
if ($coreFile === '') continue;
$absPath = $appPath . 'app/views/js/core/' . $coreFile;
$label = 'core/' . $coreFile;
} else {
$absPath = $plugin['path'] . '/' . ltrim($file, '/\\');
$label = $plugin['name'] . '/' . $file;
}
if (file_exists($absPath)) {
$code = file_get_contents($absPath);
$groups[$groupName][$type] .= "\n/* Source: {$label} */\n" . $code . "\n";
}
}
}
}
}

// 2. 物理聚合与指纹生成
foreach ($groups as $name => $types) {
// 安全增强：强制过滤分组名，只允许字母数字下划线，防止路径穿越
$name = preg_replace('/[^a-z0-9_]/i', '', $name);
if (empty($name)) continue;

foreach ($types as $type => $content) {
if (empty($content)) continue;

// 资产仅聚合并生成内容指纹。正则无法识别 JS/CSS 语法，必须原样保留源内容；
// compress 配置继续用于 PHP/HTML 编译，不在这里用不安全的压缩改写资产。

    $hash = substr(md5($content), 0, 8);
    $fileName = "{$name}.{$hash}.{$type}";
    $absFile = $runtimePath . $fileName;

    // 只有变更时才写入文件 (原子写入确保高并发一致性)
    if (!file_exists($absFile)) {
    self::atomicWrite($absFile, $content);
    }

    $manifest[$type][$name] = '/static/runtime/' . $fileName;
    }
    }

    // 3. 写入本代资产映射表。旧内容指纹文件由延迟回收处理，不能在新 Worker
    // 发布时立即删除，否则仍在途的旧页面与 Worker 会出现静态资源 404。
    $manifestFile = self::assetManifestPath();
    self::atomicWrite($manifestFile, "<?php return " . var_export($manifest, true) . ";");
    self::$assetManifestCache = $manifest;
    self::$assetManifestCachePath = $manifestFile;
    }

    /**
     * 处理模板中的资产占位符
     * 示例标签: <asset-css group="global" />
     */
    private static function processAssets(string $content): string
    {
        if (stripos($content, '<asset-css') === false && stripos($content, '<asset-js') === false) {
            return $content;
        }
        // 初始化/重建时更新清单；多个模板复用同一份内存映射。
        $file = self::assetManifestPath();
        if (self::$assetManifestCache === null || self::$assetManifestCachePath !== $file) {
            self::$assetManifestCachePath = $file;
            self::$assetManifestCache = file_exists($file) ? include $file : [];
        }
        $manifest = self::$assetManifestCache;

        // 处理 CSS
        $content = preg_replace_callback('/<asset-css\s+group="([^"]+)"\s*\/>/i', function ($m) use ($manifest) {
            $group = $m[1];
            $html = '';

            // 1. 先输出外部直链 (CDN)
            if (!empty($manifest['external'][$group]['css'])) {
                foreach ($manifest['external'][$group]['css'] as $url) {
                    $html .= '<link rel="stylesheet" href="' . $url . '">';
                }
            }

            // 2. 输出本地聚合包
            $url = $manifest['css'][$group] ?? '';
            if ($url) $html .= '<link rel="stylesheet" href="' . $url . '">';

            return $html;
        }, $content);

        // 处理 JS
        $content = preg_replace_callback('/<asset-js\s+group="([^"]+)"\s*\/>/i', function ($m) use ($manifest) {
            $group = $m[1];
            $html = '';

            // 1. 先输出外部直链 (CDN)
            if (!empty($manifest['external'][$group]['js'])) {
                foreach ($manifest['external'][$group]['js'] as $url) {
                    $html .= '<script src="' . $url . '"></script>';
                }
            }

            // 2. 输出本地聚合包
            $url = $manifest['js'][$group] ?? '';
            if ($url) $html .= '<script src="' . $url . '"></script>';

            return $html;
        }, $content);

        return $content;
    }

    /**
     * 钩子文件白名单校验
     */
    private static function isAllowedHookFile(string $file): bool
    {
        $realFile = realpath($file);
        if ($realFile === false) return false; // 文件不存在

        foreach (self::$enabledPlugins as $plugin) {
            $pluginDir = realpath($plugin['path']);
            if ($pluginDir) {
                // 确保文件在插件目录内且是Hooks子目录 (健壮性：统一 Windows 分隔符并忽略大小写比较)
                $realFileCompare = str_replace('\\', '/', $realFile);
                $pluginDirCompare = str_replace('\\', '/', $pluginDir);

                if (
                    stripos($realFileCompare, $pluginDirCompare) === 0 &&
                    stripos($realFileCompare, $pluginDirCompare . '/Hooks/') === 0
                ) {
                    // 额外验证：文件扩展名必须是允许的类型
                    $ext = pathinfo($realFile, PATHINFO_EXTENSION);
                    $allowedExts = ['php', 'js', 'css', 'html', 'htm', 'txt', 'json', 'xml'];
                    if (in_array($ext, $allowedExts, true)) return true;
                }
            }
        }
        return false;
    }

    /**
     * 注入钩子内容
     */
    private static function injectHooks(string $hookName, ?array &$hookCache = null, bool $bounded = false)
    {
        $hookName = pathinfo($hookName, PATHINFO_FILENAME);
        if ($hookCache !== null && isset($hookCache[$hookName])) {
            return $hookCache[$hookName];
        }
        if (!isset(self::$hooks[$hookName])) return '';
        $output = '';
        $processedFiles = [];

        // 遍历当前钩子对应的所有注册文件路径
        foreach (self::$hooks[$hookName] ?? [] as $file) {
            // 防止重复处理同一个文件
            if (in_array($file, $processedFiles, true)) continue;

            $processedFiles[] = $file;

            // 二次路径校验（关键安全增强）
            if (!is_string($file) || !self::isAllowedHookFile($file)) {
                error_log("Security: Attempted to load unauthorized or invalid hook file: " . (is_string($file) ? $file : gettype($file)));
                continue;
            }

            $code = $bounded
                ? file_get_contents($file, false, null, 0, self::TEMPLATE_MAX_BYTES + 1)
                : file_get_contents($file);
            if ($code === false) {
                error_log("Security: Failed to read hook file: {$file}");
                continue;
            }

            if ($bounded && strlen($code) > self::TEMPLATE_MAX_BYTES) {
                throw new \RuntimeException('Template hook source size limit exceeded');
            }
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            if ($ext === 'php') {
                $code = self::sanitizeHookCode($code);
                if ($code === null) {
                    error_log("Security: Malformed hook file skipped: {$file}");
                    continue;
                }

                // Token 级 AST 安全校验（深度防御：取代容易被绕过的正则黑名单）
                if (!self::validateHookPhpCode($code, $file)) {
                    continue;
                }

                // 检查文件大小限制（防止大文件攻击）
                if (strlen($code) > 1024 * 200) { // 200KB限制
                    error_log("Security: Hook file too large: {$file}");
                    continue;
                }
            } elseif (!in_array($ext, ['js', 'css', 'html', 'htm', 'txt', 'json', 'xml'], true)) {
                // 不允许的文件类型
                error_log("Security: Invalid file extension in hook: {$file}");
                continue;
            }

            if ($bounded && strlen($output) + strlen($code) > self::TEMPLATE_MAX_BYTES) {
                throw new \RuntimeException('Template hook expansion size limit exceeded');
            }
            $output .= $code;
        }
        if ($hookCache !== null) {
            $hookCache[$hookName] = $output;
        }
        return $output;
    }

    /**
     * 原始文件路径解析为覆盖文件路径
     */
    private static function resolveOverwrite(string $srcFile)
    {
        return self::$overwriteCache[$srcFile]['path'] ?? $srcFile;
    }

    /**
     * 原子写入文件：先在同目录完整写入临时文件，再以 rename 一次发布。
     */
    private static function atomicWrite(string $path, string $content): void
    {
        // 实际需要发布的产物若字节相同则保留原文件，资源清单等也避免重复写入。
        $unchanged = defined('DEBUG') && (int)\DEBUG > 1 && is_file($path)
            && file_get_contents($path, false, null, 0, strlen($content) + 1) === $content;
        if ($unchanged) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create compile cache directory: {$dir}");
        }

        try {
            $temporary = tempnam($dir, '.wellcms-compile-');
        } catch (\ErrorException $e) {
            throw new \ErrorException(
                $e->getMessage() . " (compile target: {$path}; temporary directory: {$dir})",
                $e->getCode(), $e->getSeverity(), $e->getFile(), $e->getLine(), $e
            );
        }
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($dir)) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException("Unable to create compile cache temporary file: {$path}");
        }

        $fp = null;
        try {
            $fp = fopen($temporary, 'wb');
            if ($fp === false) {
                throw new \RuntimeException("Unable to open compile cache temporary file: {$temporary}");
            }

            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($fp, substr($content, $offset, 1048576));
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException("Unable to write complete compile cache: {$path}");
                }
                $offset += $written;
            }

            if (!fflush($fp)) {
                throw new \RuntimeException("Unable to flush compile cache temporary file: {$path}");
            }
            // 物理落盘持久化默认关闭：编译产物可由源码重现，丢失后重新编译即可，
            // 而 fsync 在同盘实测为 1.374ms/产物（相对不落盘的 0.011ms 慢 125 倍）。
            // 需要强持久化的部署可在 config/App.php 开启 'compile_fsync' => true。
            if (!empty(self::$config['compile_fsync']) && function_exists('fsync') && !fsync($fp)) {
                throw new \RuntimeException("Unable to synchronize compile cache temporary file: {$path}");
            }

            $permissions = is_file($path) ? fileperms($path) : false;
            $mode = is_int($permissions) ? ($permissions & 0777) : (0666 & ~umask());
            if (!chmod($temporary, $mode)) {
                throw new \RuntimeException("Unable to set compile cache permissions: {$path}");
            }

            fclose($fp);
            $fp = null;
            if (!rename($temporary, $path)) {
                throw new \RuntimeException("Unable to atomically publish compile cache: {$path}");
            }
            $temporary = '';
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
            throw $e;
        }
    }

    /**
     * 加载已启用插件的钩子并按 rank 排序
     * @return void
     */
    protected static function loadPluginHooks()
    {
        $enabled = self::getEnabledPlugins();
        if (empty($enabled)) {
            self::$hooks = [];
            return;
        }

        $hookList = [];
        foreach ($enabled as $plugin) {
            // 优化：仅对 Hooks 目录存在时执行 glob
            if (!is_dir($plugin['path'] . '/Hooks')) continue;

            $files = glob($plugin['path'] . '/Hooks/*.*');
            if (!is_array($files)) continue;

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $rank = $plugin['config']['hooks_rank'][$name] ?? 0;

                $hookList[$name][] = ['rank' => $rank, 'file' => $file];
            }
        }

        if (empty($hookList)) {
            self::$hooks = [];
            return;
        }

        // 排序并提取文件路径
        foreach ($hookList as $name => $hooks) {
            if (count($hooks) > 1) {
                usort($hooks, function ($a, $b) {
                    return $b['rank'] <=> $a['rank'];
                });
            }
            $hookList[$name] = array_column($hooks, 'file');
        }

        self::$hooks = $hookList;
    }

    /**
     * 保存编译清单 (Manifest) 到磁盘
     */
    protected static function saveManifest(string $path): void
    {
        $data = [
            'app_root' => self::applicationRootIdentity(),
            'input_fingerprint' => self::$manifestInputFingerprint,
            'artifact_epoch' => self::$manifestArtifactEpoch,
            'hooks' => self::$hooks,
            'overwriteCache' => self::$overwriteCache,
            'enabledPlugins' => self::$enabledPlugins,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        // 清单文件采用原生 PHP 数组格式，利用 Opcache 缓存提升加载速度
        $content = "<?php\n/** WellCMS Compile Manifest - Production O(1) Speed **/\n";
        $content .= "return " . var_export($data, true) . ";";
        self::atomicWrite($path, $content);
        self::$manifest = $data;
    }

    /**
     * 以真实路径绑定 manifest，禁止工作区绝对路径清单被其他 release 直接信任。
     */
    private static function applicationRootIdentity(): string
    {
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        $realPath = realpath($appPath);
        $identity = $realPath !== false ? $realPath : $appPath;
        return rtrim(str_replace('\\', '/', $identity), '/') . '/';
    }

    /**
     * 返回项目内持久化生成代 epoch 的路径。
     */
    private static function artifactEpochPath(): string
    {
        return rtrim(self::$config['tmp_path'], '/') . '/artifact_epoch';
    }

    /**
     * 旧安装没有 epoch 时返回空身份以沿用原 generation 公式；一旦存在则只接受完整值。
     */
    private static function artifactEpoch(): string
    {
        $path = self::artifactEpochPath();
        if (!is_file($path)) {
            return '';
        }

        $epoch = file_get_contents($path);
        if (!is_string($epoch) || preg_match('/^[a-f0-9]{64}$/D', $epoch) !== 1) {
            throw new \RuntimeException("Invalid compile artifact epoch: {$path}");
        }
        return $epoch;
    }

    /**
     * 计算影响插件发现、Hook 与 Overwrite 的稳定内容指纹。
     */
    private static function calculateManifestInputFingerprint(): string
    {
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        $normalizedRoot = rtrim(str_replace('\\', '/', $appPath), '/') . '/';
        $inputs = [];

        $appendFile = function (string $file, ?string $content = null) use (&$inputs, $normalizedRoot): void {
            if ($content === null && !is_file($file)) {
                return;
            }
            $normalizedFile = str_replace('\\', '/', $file);
            $relative = strpos($normalizedFile, $normalizedRoot) === 0
                ? substr($normalizedFile, strlen($normalizedRoot))
                : $normalizedFile;
            $hash = $content === null ? hash_file('sha256', $file) : hash('sha256', $content);
            if (!is_string($hash)) {
                throw new \RuntimeException("Unable to fingerprint compile manifest input: {$file}");
            }
            $inputs[$relative] = $hash;
        };

        $appendFile($appPath . 'config/App.php');
        $pluginDirs = glob($appPath . 'plugins/*', GLOB_ONLYDIR);
        if (!is_array($pluginDirs)) {
            $pluginDirs = [];
        }
        sort($pluginDirs, SORT_STRING);

        foreach ($pluginDirs as $pluginDir) {
            $configFile = $pluginDir . '/config.json';
            if (!is_file($configFile)) {
                continue;
            }

            // 指纹与启用状态必须来自同一次读取，避免重复 I/O 及同步期间混用两个版本。
            $content = file_get_contents($configFile);
            if (!is_string($content)) {
                throw new \RuntimeException("Unable to fingerprint compile manifest input: {$configFile}");
            }
            $appendFile($configFile, $content);
            $json = ltrim($content, "\xEF\xBB\xBF");
            $config = json_decode($json, true);
            if (!is_array($config) || empty($config['enable']) || empty($config['installed'])) {
                continue;
            }

            $hookFiles = glob($pluginDir . '/Hooks/*.*');
            if (is_array($hookFiles)) {
                sort($hookFiles, SORT_STRING);
                foreach ($hookFiles as $hookFile) {
                    $appendFile($hookFile);
                }
            }

            $overwriteDir = $pluginDir . '/Overwrite';
            if (!is_dir($overwriteDir)) {
                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $overwriteDir,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (\UnexpectedValueException $e) {
                continue;
            }
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $appendFile($file->getPathname());
                }
            }
        }

        ksort($inputs, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($inputs as $relative => $hash) {
            hash_update($context, $relative . "\0" . $hash . "\n");
        }
        return hash_final($context);
    }

    /**
     * 只有扫描前后且发布后输入都一致时，才承认新 manifest。
     */
    private static function rebuildManifest(string $path): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            self::$hooks = [];
            self::$overwriteCache = [];
            self::$enabledPlugins = [];
            self::$manifest = [];

            $before = self::calculateManifestInputFingerprint();
            self::loadPluginHooks();
            self::buildOverwriteCache();
            $after = self::calculateManifestInputFingerprint();
            if (!hash_equals($before, $after)) {
                continue;
            }

            self::$manifestInputFingerprint = $after;
            // 清单绑定发布时的标记值：请求路径仅凭它判定是否需要换代。
            $epochAtPublish = self::artifactEpoch();
            self::$manifestArtifactEpoch = $epochAtPublish;
            self::saveManifest($path);
            // 发布期间输入或代际任一变化都重试，避免落盘一份已过期的清单。
            $published = self::calculateManifestInputFingerprint();
            if (
                hash_equals($after, $published)
                && hash_equals($epochAtPublish, self::artifactEpoch())
            ) {
                return;
            }
        }

        self::$hooks = [];
        self::$overwriteCache = [];
        self::$enabledPlugins = [];
        self::$manifest = [];
        self::$manifestInputFingerprint = '';
        self::$manifestArtifactEpoch = '';
        throw new \RuntimeException('Compile manifest inputs changed repeatedly during publication');
    }

    /**
     * 构建覆盖缓存：根据插件的 overwrites_rank 决定原始文件的覆盖优先级
     */
    private static function buildOverwriteCache(): void
    {
        $enabled = self::getEnabledPlugins();
        if (empty($enabled)) {
            self::$overwriteCache = [];
            return;
        }

        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        $overwriteCache = [];

        foreach ($enabled as $plugin) {
            $overwriteDir = $plugin['path'] . '/Overwrite';
            if (!is_dir($overwriteDir)) continue;

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $overwriteDir,
                        \FilesystemIterator::SKIP_DOTS |
                            \FilesystemIterator::UNIX_PATHS
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (\UnexpectedValueException $e) {
                continue;
            }

            foreach ($iterator as $file) {
                if ($file->isDir()) continue;

                $relativePath = substr($file->getPathname(), strlen($overwriteDir) + 1);
                $relativePath = strtr($relativePath, '\\', '/');

                // 生成原始路径并校验
                $original = self::securePath($appPath . $relativePath);
                if (empty($original)) continue;

                // /src/index.php
                /*
                plugins/
                └── pluginA/
                    └── Overwrite/
                        ├── views/ // 站点根目录下的一级子目录
                        │   ├── home/
                        │   │   └── index.htm
                        │   └── header.htm
                        └── config/
                            └── settings.xml

                // config.json
                "overwrites_rank": {
                    "/views/home/index.htm": 1
                }
                */
                // 更新覆盖缓存
                $currentRank = $overwriteCache[$original]['rank'] ?? -1;
                $rank = $plugin['config']['overwrites_rank']['/' . trim($relativePath, '/')] ?? 0;
                if ($rank > $currentRank) {
                    $overwriteCache[$original] = [
                        'path' => $file->getPathname(),
                        'rank' => $rank,
                    ];
                }
                /* // APP_PATH 根目录为绝对路径，生成的覆盖缓存包含：
                self::$overwriteCache = [
                    APP_PATH . 'views/home/index.htm' => [
                        'path' => APP_PATH . 'plugins/pluginA/Overwrite/views/home/index.htm',
                        'rank' => 1
                    ],
                    APP_PATH . 'views/header.htm' => [
                        'path' => APP_PATH . 'plugins/pluginA/Overwrite/views/header.htm',
                        'rank' => 0
                    ],
                    APP_PATH . 'config/settings.xml' => [
                        'path' => APP_PATH . 'plugins/pluginA/Overwrite/config/settings.xml',
                        'rank' => 0
                    ]
                ]
                */
            }
        }

        self::$overwriteCache = $overwriteCache;
    }

    private static function securePath(string $path): string
    {
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';

        // 统一分隔符并解析符号链接
        $path = strtr($path, '\\', '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if (!empty($parts)) {
                    array_pop($parts);
                }
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $cleanPath = implode('/', $parts);

        // 最终绝对路径校验
        $realPath = realpath('/' . $cleanPath);
        return ($realPath && self::strStartsWith($realPath, $appPath)) ? $realPath : '';
    }

    private static function strStartsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * 清理 Hook 文件内容：移除 PHP 开始标签和开头的 exit/die
     * 若检测到非法嵌套标签则返回 null（拒绝加载）
     */
    private static function sanitizeHookCode(string $code): ?string
    {
        // 移除 BOM
        $code = ltrim($code, "\xEF\xBB\xBF");

        // 移除开头的 <?php 及其后可能紧跟的 exit/die
        $code = preg_replace('#^\s*<\?php(\s*(exit|die)\s*;)?#is', '', $code);

        // 移除开头的 <?= 短标签（防止代码执行注入）
        $code = preg_replace('#^\s*<\?=\s*#is', '', $code);

        // 移除开头的 <? 短标签（兼容 short_open_tag=On 的环境）
        $code = preg_replace('#^\s*<\?(?!php)\s*#is', '', $code);

        // 防御性检测：清理后若仍存在任何 PHP 开始标签，视为异常
        if (preg_match('#<\?(php|=|\s)#i', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * 使用 PHP Token 级 AST 分析校验 Hook 代码安全性
     * 精确识别危险结构，避免正则绕过的风险
     */
    private static function validateHookPhpCode(string $code, string $file): bool
    {
        // 危险全局函数调用列表
        $dangerousFunctions = [
            'eval',
            'assert',
            'exec',
            'shell_exec',
            'system',
            'passthru',
            'proc_open',
            'popen',
            'pcntl_exec',
            'create_function',
        ];

        // 禁止的 Token 类型
        $forbiddenTokenIds = [
            T_EVAL,
            T_INCLUDE,
            T_REQUIRE,
            T_INCLUDE_ONCE,
            T_REQUIRE_ONCE,
        ];

        // PHP 8.0+ 引入的限定名 Token（用于检测 \eval()、Namespace\eval() 等）
        $qualifiedTokenIds = [];
        if (defined('T_NAME_FULLY_QUALIFIED')) {
            $qualifiedTokenIds[] = \T_NAME_FULLY_QUALIFIED;
        }
        if (defined('T_NAME_QUALIFIED')) {
            $qualifiedTokenIds[] = \T_NAME_QUALIFIED;
        }
        if (defined('T_NAME_RELATIVE')) {
            $qualifiedTokenIds[] = \T_NAME_RELATIVE;
        }

        // 为代码添加 PHP 标签以便 token_get_all 正确解析
        $tokens = token_get_all('<?php ' . $code);

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // 单字符 token（如反引号 `、括号等）
            if (!is_array($token)) {
                if ($token === '`') {
                    error_log("Security: Backtick shell execution detected in hook file: {$file}");
                    return false;
                }
                continue;
            }

            [$id, $text, $line] = $token;

            // 1. 检测禁止的 Token 类型
            if (in_array($id, $forbiddenTokenIds, true)) {
                error_log("Security: Forbidden token " . token_name($id) . " detected in hook file: {$file}, line {$line}");
                return false;
            }

            // 2. 检测危险全局函数调用（T_STRING 形式，排除类方法调用和 new 表达式）
            if ($id === T_STRING && in_array(strtolower($text), $dangerousFunctions, true)) {
                // 向前回溯，检查是否是 ->method()、::method() 或 new Class()
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW])) {
                    // 属于类成员访问或类实例化，跳过
                    continue;
                }

                // 向后查找，确认下一个非空白 token 是左括号
                $j = $i + 1;
                while ($j < count($tokens) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }
                if ($j < count($tokens) && $tokens[$j] === '(') {
                    error_log("Security: Dangerous function call '{$text}' detected in hook file: {$file}, line {$line}");
                    return false;
                }
            }

            // 3. 检测危险完全限定名/限定名函数调用（PHP 8.0+ 的 T_NAME_FULLY_QUALIFIED 等）
            if (in_array($id, $qualifiedTokenIds, true)) {
                // 提取最后一段名称（如 \Namespace\eval -> eval）
                $parts = explode('\\', $text);
                $funcName = strtolower(end($parts));
                if (in_array($funcName, $dangerousFunctions, true)) {
                    // 向前检查是否是 new \Class()（限定名实例化）
                    $prev = $i - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $prev--;
                    }
                    if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW) {
                        continue;
                    }

                    // 向后查找左括号
                    $j = $i + 1;
                    while ($j < count($tokens) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $j++;
                    }
                    if ($j < count($tokens) && $tokens[$j] === '(') {
                        error_log("Security: Dangerous function call '{$text}' detected in hook file: {$file}, line {$line}");
                        return false;
                    }
                }
            }

            // 4. 检测变量函数调用 $func() 与 $func[...]()（排除 new $class() 及对象方法调用）
            if ($id === T_VARIABLE) {
                $j = $i + 1;
                while ($j < count($tokens)) {
                    $t = $tokens[$j];
                    if (!is_array($t)) {
                        if ($t === '[') {
                            // 跳过数组下标 [...]
                            $depth = 1;
                            $j++;
                            while ($j < count($tokens) && $depth > 0) {
                                $inner = $tokens[$j];
                                if (!is_array($inner)) {
                                    if ($inner === '[') $depth++;
                                    elseif ($inner === ']') $depth--;
                                }
                                $j++;
                            }
                            continue;
                        } elseif ($t === '(') {
                            // 向前检查是否是 new $var() 或 new $var[...]()
                            $prev = $i - 1;
                            while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                $prev--;
                            }
                            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW) {
                                break; // 动态类实例化，允许
                            }
                            error_log("Security: Dynamic function call detected in hook file: {$file}, line {$line}");
                            return false;
                        } elseif ($t === ';' || $t === '}' || $t === ')' || $t === ',') {
                            break; // 语句结束，无函数调用
                        }
                        // 其他单字符运算符，停止扫描
                        break;
                    } else {
                        if (in_array($t[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                            break; // $var->... 或 $var::... 属于对象/类调用，安全
                        } elseif (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                            break; // 遇到其他 token，停止扫描
                        }
                    }
                    $j++;
                }
            }
        }

        return true;
    }

    /**
     * 获取所有启用插件（跳过未安装/未启用）的配置列表
     * @return array
     */
    private static function getEnabledPlugins()
    {
        if (!empty(self::$config['disabled_plugin'])) {
            self::$enabledPlugins = [];
            return [];
        }
        if (!empty(self::$enabledPlugins)) return self::$enabledPlugins;

        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        $dirs = glob($appPath . 'plugins/*', GLOB_ONLYDIR);
        $enabled = [];

        foreach ($dirs as $path) {
            $cfg = $path . '/config.json';
            if (!file_exists($cfg)) continue;
            $json = ltrim(file_get_contents($cfg), "\xEF\xBB\xBF");
            $conf = json_decode($json, true);
            if (json_last_error() || empty($conf['enable']) || empty($conf['installed'])) continue;

            $dir = basename($path);
            $enabled[$dir] = [
                'name' => $dir,
                'path' => $path,
                'config' => $conf,
                'rank' => (int)($conf['rank'] ?? 100)
            ];
        }

        // 按 Rank 升序排列 (Rank 越小优先级越低，即基础包排在前面被后面的覆盖)
        // 在 WellCMS 习惯中，Rank 越小越基础，Rank 越大越靠后（作为覆盖者）
        uasort($enabled, function ($a, $b) {
            return $a['rank'] <=> $b['rank'];
        });

        self::$enabledPlugins = $enabled;
        return $enabled;
    }
}
