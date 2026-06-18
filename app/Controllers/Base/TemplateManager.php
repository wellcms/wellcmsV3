<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Controllers\Base;

use Framework\Http\Interfaces\ServerRequestInterface;

class TemplateManager
{
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var string */
    protected $themesPath;
    /** @var string */
    protected $pluginsPath;
    /** @var array */
    protected $devicePrefixes;
    /** @var array|null */
    protected $templateMap = null;
    /** @var string */
    protected $mapPath;

    public function __construct(\Framework\Cache\Interfaces\CacheInterface $cache, string $themesPath = '', string $pluginsPath = '', array $devicePrefixes = [])
    {
        $this->cache = $cache;
        $this->themesPath = $themesPath;
        $this->pluginsPath = $pluginsPath;
        $this->devicePrefixes = $devicePrefixes;
        $this->mapPath = APP_PATH . 'storage/tmp/template_map.php';
    }

    /**
     * 获取模板文件绝对路径
     *
     * @param bool|string $templateDir false:前台; true:后台; string:插件名
     * @param string $fileName 模板名
     * @param string $id 绑定对象ID
     * @param ServerRequestInterface|null $request 当前请求对象，用于协程安全缓存
     * @return string
     */
    public function template($templateDir= false, string $fileName = '', ?string $id = '', ?ServerRequestInterface $request = null): string
    {
        // 1. 尝试 L1 缓存：请求级内存缓存 (PSR-7 Attribute)
        if ($request) {
            $requestCache = $request->getAttribute('_tpl_path_cache', []);
            $cacheKey = (string)$templateDir . $fileName . $id;
            if (isset($requestCache[$cacheKey])) return $requestCache[$cacheKey];
        }

        // 2. 尝试 L2 缓存：持久化路径映射 (template_map.php)
        if (null === $this->templateMap) {
            $this->templateMap = (file_exists($this->mapPath) && (!defined('DEBUG') || DEBUG === 0))
                ? include $this->mapPath
                : [];
        }

        $mapInnerKey = (string)$templateDir . ':' . $fileName . ':' . $id . ':' . $this->getDevicePrefix($request);
        if (isset($this->templateMap[$mapInnerKey]) && file_exists($this->templateMap[$mapInnerKey])) {
            return $this->templateMap[$mapInnerKey];
        }

        // 3. 执行物理探测逻辑
        $filePath = $this->resolvePhysicalPath($templateDir, $fileName, $id, $request);

        // 4. 更新缓存
        if (!empty($filePath)) {
            $this->templateMap[$mapInnerKey] = $filePath;
            // 如果处于非调试模式或特定条件下，持久化缓存
            if (!defined('DEBUG') || DEBUG === 0) {
                $this->persistMap();
            }
        }

        return $filePath;
    }

    /**
     * @param bool|string $templateDir false:前台; true:后台; string:插件名
     * @param string $fileName 模板名
     * @param string|null $id 绑定对象ID（可选）
     */
    protected function resolvePhysicalPath($templateDir, string $fileName, ?string $id, ServerRequestInterface $request): string
    {
        $config = $this->cache->get('setting') ?? [];
        $theme = isset($config['themes']['theme']) ? $config['themes']['theme'] : '';
        $prefix = $this->getDevicePrefix($request);
        $templateFile = $prefix . $fileName . '.htm';

        if ($theme) {
            $themeChildren = !empty($config['theme_children']) ? $config['theme_children'] : [];
            $paths = $this->generatePaths($templateFile, $id, $theme, $themeChildren);
            foreach ($paths as $path) {
                if (file_exists($path)) return $path;
            }
        }

        // 插件路径检测
        if (is_string($templateDir)) {
            $path = $this->pluginsPath . $templateDir . '/views/htm/' . $fileName . '.htm';
            if (file_exists($path)) return $path;
        }

        // 核心默认路径
        return true === $templateDir
            ? APP_PATH . 'app/views/admin/' . $fileName . '.htm'
            : APP_PATH . 'app/views/htm/' . $fileName . '.htm';
    }

    protected function getDevicePrefix(?ServerRequestInterface $request = null): string
    {
        $ua = $request
            ? ($request->getServerParams()['HTTP_USER_AGENT'] ?? '')
            : '';

        $uaLower = strtolower($ua);
        if (strpos($uaLower, 'phone') !== false || strpos($uaLower, 'mobile') !== false || strpos($uaLower, 'ipod') !== false) {
            return $this->devicePrefixes['mobile'] ?? '';
        }
        if (strpos($uaLower, 'pad') !== false || strpos($uaLower, 'tablet') !== false) {
            return $this->devicePrefixes['tablet'] ?? '';
        }
        return '';
    }

    /**
     * 生成可能的模板路径列表，优先级从高到低
     * @param string $templateFile 模板文件名（含设备前缀）
     * @param string|null $id 绑定对象ID（可选）
     * @param string $theme 当前主题
     * @param array $themeChildren 主题子项列表，包含子主题信息和日期（用于排序）
     */
    protected function generatePaths(string $templateFile, ?string $id, string $theme, array $themeChildren): array
    {
        $paths = [];
        if (!empty($themeChildren)) {
            uasort($themeChildren, function ($a, $b) {
                return ($b['date'] ?? 0) - ($a['date'] ?? 0);
            });
            foreach ($themeChildren as $child) {
                if (!empty($child['theme'])) {
                    $dir = $this->themesPath . $child['theme'] . '/htm/';
                    if ($id) $paths[] = $dir . $id . '_' . $templateFile;
                    $paths[] = $dir . $templateFile;
                }
            }
        }

        if ($theme) {
            $dir = $this->themesPath . $theme . '/htm/';
            if ($id) $paths[] = $dir . $id . '_' . $templateFile;
            $paths[] = $dir . $templateFile;
        }

        return $paths;
    }

    protected function persistMap(): void
    {
        $content = "<?php\nreturn " . var_export($this->templateMap, true) . ";\n";
        @file_put_contents($this->mapPath, $content, LOCK_EX);
    }
}
