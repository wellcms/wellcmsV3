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
    // 配置数组
    protected /** @var array */
    static $config = [];
    // 钩子集合：钩子名 => [文件路径,...]
    protected /** @var array */
    static $hooks = [];
    // 已启用插件信息列表
    protected /** @var array */
    static $enabledPlugins = [];
    // 覆盖文件缓存：覆盖路径 => [path=>文件原始路径, rank=>优先级]
    protected /** @var array */
    static $overwriteCache = [];
    // 初始化标记
    protected /** @var bool */
    static $initialized = false;
    // 编译清单数据 (Manifest)
    protected /** @var array */
    static $manifest = [];

    public static function init(?string $containerCache): void{
        // 确保全局只初始化一次
        if (self::$initialized && (!defined('DEBUG') || (int)\DEBUG === 0)) {
            return;
        }

        // 1. 加载基础配置 (极简加载)
        $cfgFile = APP_PATH . 'config/App.php';
        self::$config = file_exists($cfgFile) ? require $cfgFile : [
            'tmp_path' => './storage/tmp/',
            'compress' => 1,
            'disabled_plugin' => 0,
        ];

        // 注意：此路径独立于 ConfigServiceProvider 处理
        // 原因：Compile::init() 在 ConfigServiceProvider::register() 之前执行（见 index.php:52-62）
        // 确保 tmp_path 为绝对目录
        $appPath = defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/';
        self::$config['tmp_path'] = $appPath . ltrim(self::$config['tmp_path'], './');

        // 2. 尝试从清单 (Manifest) 加载插件信息与钩子 (生产环境 O(1) 核心)
        $manifestFile = self::$config['tmp_path'] . 'configs/compile_manifest.php';
        $debug = defined('DEBUG') ? (int)constant('DEBUG') : 0;

        if ($debug === 0 && file_exists($manifestFile)) {
            self::$manifest = require $manifestFile;
            self::$hooks = self::$manifest['hooks'] ?? [];
            self::$overwriteCache = self::$manifest['overwriteCache'] ?? [];
            self::$enabledPlugins = self::$manifest['enabledPlugins'] ?? [];
        } else {
            // 非生产或清单缺失：重扫并重建清单
            self::loadPluginHooks();
            self::buildOverwriteCache();
            self::saveManifest($manifestFile);
        }

        // 预创建结构化编译目录 (工业级规范)
        $subDirs = ['classes', 'views', 'langs', 'configs'];
        foreach ($subDirs as $dir) {
            $path = self::$config['tmp_path'] . $dir . '/';
            if (!is_dir($path)) @mkdir($path, 0755, true);
        }

        // 静态资源聚合编译
        if (!file_exists(self::$config['tmp_path'] . 'configs/asset_manifest.php') || (defined('DEBUG') && \DEBUG > 1)) {
            self::compileAssets();
        }

        // 容器预编译优化
        // 仅在非调试模式或缓存不存在时执行
        if ($containerCache && (!file_exists($containerCache) || (defined('DEBUG') && \DEBUG > 0))) {
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
        if (!file_exists($srcFile)) return $srcFile;

        // 生成缓存文件路径
        $cacheFile = (string)self::getCachePath($srcFile);

        // 生产环境 I/O 冻结 (I/O Freeze Mode)
        // 在生产环境（DEBUG === 0）且非强力刷新模式下，直接信任已存在的缓存，消除 stat 系统调用
        $debug = defined('DEBUG') ? (int)constant('DEBUG') : 0;

        if ($debug === 0 && file_exists($cacheFile)) {
            return $cacheFile;
        }

        if (!file_exists($cacheFile) || $debug > 1 || (filemtime($srcFile) > @filemtime($cacheFile))) {
            // 重新编译
            $content = (string)self::compile($srcFile);
            self::atomicWrite($cacheFile, $content);
        }

        return $cacheFile;
    }

    /**
     * includeLang 方法：高性能语言包加载入口
     * 将核心包、插件包、插件钩子三层资产压平为单体缓存
     */
    public static function includeLang(string $locale, string $type, array $themeFiles = [])
    {
        $langDir = rtrim(self::$config['tmp_path'], '/') . '/langs/';
        $cacheFile = $langDir . "{$locale}_{$type}.php";

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
    protected static function compileLanguage(string $locale, string $type, string $cacheFile, array $themeFiles = []): void{
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
    protected static function compileContainer(string $outputFile): void{
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
            // 如果有其他高频使用的 Service，可以加在这里
        ];

        $definitions = [];
        $container = new Container(); // 临时实例用于反射提取

        foreach ($classesToCompile as $class) {
            // 依赖 Container.php 中的 getReflectionDefinition 方法
            // 如果 Container.php 尚未更新该方法，此处需做兼容判断
            if (method_exists($container, 'getReflectionDefinition')) {
                $def = $container->getReflectionDefinition($class);
                if ($def) $definitions[$class] = $def;
            }
        }

        if (!empty($definitions)) {
            $content = "<?php return " . var_export($definitions, true) . ";";
            self::atomicWrite($outputFile, $content);
        }
    }

    /**
     * 获取编译缓存路径 (重构版：分层寻址)
     * @return array
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

        $cachePath = rtrim(self::$config['tmp_path'], '/') . '/' . $subDir . '/' . $relative;

        // 自动确保深层子目录存在 (ext4/xfs 友好)
        if (!is_dir(dirname($cachePath))) {
            @mkdir(dirname($cachePath), 0755, true);
        }

        return $cachePath;
    }

    /**
     * 细粒度缓存清理
     * @param string $type classes|views|langs|configs|all
     */
    public static function flushCache(string $type = 'all'): void
    {
        $tmpPath = rtrim(self::$config['tmp_path'], '/') . '/';
        $targets = ($type === 'all') ? ['classes', 'views', 'langs', 'configs'] : [$type];

        foreach ($targets as $sub) {
            $path = $tmpPath . $sub;
            if (is_dir($path)) self::recursiveRemove($path, false);
        }

        // 核心：强制清理清单缓存
        $manifestFile = self::$config['tmp_path'] . 'configs/compile_manifest.php';
        if (file_exists($manifestFile)) @unlink($manifestFile);

        // 清理静态变量
        if ($type === 'all') {
            self::$config = [];
            self::$hooks = [];
            self::$enabledPlugins = [];
            self::$overwriteCache = [];
            self::$manifest = [];
            self::$initialized = false;
        }
    }

    private static function recursiveRemove(string $dir, bool $removeSelf = true): void{
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::recursiveRemove("$dir/$file") : @unlink("$dir/$file");
        }
        if ($removeSelf) @rmdir($dir);
    }

    /**
     * compile 方法：执行插件覆盖、钩子注入、模板包含、压缩等流程
     * @return void
     */
    private static function compile(string $srcFile)
    {
        // 如果禁用插件，则跳过覆盖和钩子
        if (!empty(self::$config['disabled_plugin'])) {
            $content = file_get_contents($srcFile);
        } else {
            // 解析覆盖逻辑
            $useFile = self::resolveOverwrite($srcFile);
            $content = file_get_contents($useFile);

            // 钩子注入（-1 = 单次全替换，retry 仅作安全网）
            $compiledPattern = '#(?:<!--{hook\s+([^}]+?)}-->|//\s*hook\s+(\S+))#s';
            $retryCount = 3;
            do {
                // 快速筛查
                if (strpos($content, 'hook') === false) break;

                // 统一替换
                $newContent = preg_replace_callback(
                    $compiledPattern,
                    function ($matches) {
                        return self::injectHooks($matches[2] ?? $matches[1]);
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

        // 模板包含处理，多层嵌套
        $slotData = [];
        for ($i = 0; $i < 10; ++$i) {
            $new = self::processTemplateIncludes($content, $slotData);
            if ($new === $content) break;
            $content = $new;
        }

        // 压缩逻辑
        if ((!defined('DEBUG') || (int)\DEBUG === 0) && self::$config['compress'] > 0) {
            $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                // PHP文件压缩去空格
                $hash = md5($content);
                $tmpFile = sys_get_temp_dir() . "/php_{$hash}.tmp";
                // 只有当内容发生变化或临时文件不存在时才写入
                if (!file_exists($tmpFile)) {
                    self::atomicWrite($tmpFile, $content);
                }

                $content = php_strip_whitespace($tmpFile);
                if (file_exists($tmpFile)) unlink($tmpFile);
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
        $currentFiles = [];
        foreach ($groups as $name => $types) {
            // 安全增强：强制过滤分组名，只允许字母数字下划线，防止路径穿越
            $name = preg_replace('/[^a-z0-9_]/i', '', $name);
            if (empty($name)) continue;

            foreach ($types as $type => $content) {
                if (empty($content)) continue;

                // 进阶压缩逻辑 (Industrial Grade Regex)
                if ($type === 'css') {
                    // CSS 压缩：移除注释、多余空格、换行、分号等
                    $content = preg_replace([
                        '#/\*.*?\*/#s',            // 移除注释
                        '#\s*([\{\};,:\>\+\-])\s*#', // 移除符号两端空格
                        '#;\}#',                   // 移除最后一个分号
                        '#\s+#',                   // 压缩多余空格
                    ], ['', '$1', '}', ' '], $content);
                } else {
                    // JS 压缩：简易移除单行/多行注释和换行（不破坏字符串）
                    $content = preg_replace([
                        '#\s*//.*?\n#',           // 移除单行注释
                        '#/\*.*?\*/#s',           // 移除多行注释
                        '#\s*([;\{\}\=\+\-\*\/\!\<\>\&\|\?])\s*#', // 移除操作符两端空格
                        '#\s+#',                  // 压缩多余空格
                    ], ['', '', '$1', ' '], $content);
                }

                $hash = substr(md5($content), 0, 8);
                $fileName = "{$name}.{$hash}.{$type}";
                $absFile = $runtimePath . $fileName;
                $currentFiles[] = $fileName;

                // 只有变更时才写入文件 (原子写入确保高并发一致性)
                if (!file_exists($absFile)) {
                    self::atomicWrite($absFile, $content);
                }

                $manifest[$type][$name] = '/static/runtime/' . $fileName;
            }
        }

        // 3. 自动清理陈旧的指纹文件 (防止存储膨胀)
        $allRuntimeFiles = scandir($runtimePath);
        foreach ($allRuntimeFiles as $f) {
            if ($f === '.' || $f === '..') continue;
            // 如果文件不在当前生成的现存列表中，则视为陈旧文件，执行删除
            if (!in_array($f, $currentFiles)) {
                @unlink($runtimePath . $f);
            }
        }

        // 4. 写入资产映射表
        $manifestFile = self::$config['tmp_path'] . 'configs/asset_manifest.php';
        self::atomicWrite($manifestFile, "<?php return " . var_export($manifest, true) . ";");
    }

    /**
     * 处理模板中的资产占位符
     * 示例标签: <asset-css group="global" />
     */
    private static function processAssets(string $content): string
    {
        static $manifest;
        if ($manifest === null) {
            $file = self::$config['tmp_path'] . 'configs/asset_manifest.php';
            $manifest = file_exists($file) ? include $file : [];
        }

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
    private static function injectHooks(string $hookName)
    {
        $hookName = pathinfo($hookName, PATHINFO_FILENAME);
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

            $code = file_get_contents($file);
            if ($code === false) {
                error_log("Security: Failed to read hook file: {$file}");
                continue;
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

            $output .= $code;
        }
        return $output;
    }

    /**
     * 处理 <template include> 标签及 <slot>
     */
    private static function processTemplateIncludes(string $content, array &$slotData)
    {
        return preg_replace_callback(
            // 匹配 <template include="模板路径">...</template> 标签
            '#<template\s+include="([^"]+)">(.*?)<\/template>#is',
            function ($m) use (&$slotData) {
                $includePath = $m[1];

                // 白名单验证 - 只允许特定目录
                $allowedDirs = [
                    (defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/') . 'app/views/',
                    (defined('APP_PATH') ? \APP_PATH : dirname(__DIR__, 2) . '/') . 'themes/',
                ];

                // 解析绝对路径
                $realPath = realpath($includePath);
                if ($realPath === false) {
                    throw new \RuntimeException("Template file not found: {$includePath}");
                }

                // 验证路径是否在白名单目录内
                $isValid = false;
                foreach ($allowedDirs as $dir) {
                    $realDir = realpath($dir);
                    if ($realDir !== false && strpos($realPath, $realDir) === 0) {
                        $isValid = true;
                        break;
                    }
                }

                if (!$isValid) {
                    throw new \RuntimeException("Invalid include path: {$includePath}. Path must be within allowed directories.");
                }

                if (!file_exists($realPath)) {
                    throw new \RuntimeException("Template file not found: {$includePath}");
                }

                $tpl = file_get_contents($realPath);
                // 收集当前层的插槽
                preg_match_all('#<slot\s+name="([^\"]+)">(.*?)<\/slot>#is', $m[2], $slots);
                if (!empty($slots[1])) {
                    $slotData = array_merge($slotData, array_combine($slots[1], $slots[2]));
                }

                if (empty($slotData)) return $tpl;

                $replacements = [];
                foreach ($slotData as $name => $html) {
                    $replacements["<slot name=\"$name\" />"] = $html;
                }
                $tpl = strtr($tpl, $replacements);
                return $tpl;
            },
            $content
        );
    }

    /**
     * 原始文件路径解析为覆盖文件路径
     */
    private static function resolveOverwrite(string $srcFile)
    {
        return self::$overwriteCache[$srcFile]['path'] ?? $srcFile;
    }

    /**
     * 原子写入文件，支持重试和锁定
     */
    private static function atomicWrite(string $path, string $content): void{
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $retry = 3;
        while ($retry-- > 0) {
            $fp = fopen($path, 'cb'); // 以二进制追加模式打开文件
            if ($fp && flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, $content);
                flock($fp, LOCK_UN);
                fclose($fp);
                return;
            }
            if (\Framework\Utils\Runtime::inCoroutine()) {
                \Swoole\Coroutine\System::sleep(0.1);
            } else {
                usleep(100000);
            }
        }

        file_put_contents($path, $content);
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
            'hooks' => self::$hooks,
            'overwriteCache' => self::$overwriteCache,
            'enabledPlugins' => self::$enabledPlugins,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        // 清单文件采用原生 PHP 数组格式，利用 Opcache 缓存提升加载速度
        $content = "<?php\n/** WellCMS Compile Manifest - Production O(1) Speed **/\n";
        $content .= "return " . var_export($data, true) . ";";
        self::atomicWrite($path, $content);
    }

    /**
     * 构建覆盖缓存：根据插件的 overwrites_rank 决定原始文件的覆盖优先级
     */
    private static function buildOverwriteCache(): void{
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
            'eval', 'assert', 'exec', 'shell_exec', 'system', 'passthru',
            'proc_open', 'popen', 'pcntl_exec', 'create_function',
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