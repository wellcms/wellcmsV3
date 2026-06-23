<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Framework\Utils\SecurityHelper;

/**
 * ExtensionManager - 插件与主题的生命周期抽象管理
 */
class ExtensionManager
{
    /** @var \App\Services\System\KeyValueService */
    private $kv;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    private $language;
    /** @var \App\Services\Market\MarketClient */
    private $market;
    /** @var \Framework\Http\Routing\UrlGeneratorInterface */
    private $urlGenerator;
    /** @var \App\Services\Extension\ExtensionInstaller */
    private $installer;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var array */
    private $appConfig;
    /** @var string */
    private $pluginPath;
    /** @var string */
    private $themePath;
    /** @var int */
    private const SYNC_TTL = 3600;

    public function __construct(
        array $appConfig,
        array $pluginConfig,
        array $viewConfig,
        \App\Services\System\KeyValueService $kv,
        ?\App\Interfaces\LanguageLoaderInterface $language,
        \App\Services\Market\MarketClient $market,
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Extension\ExtensionInstaller $installer,
        ?\Framework\Core\Container $container = null
    ) {
        $this->container = $container;
        $this->kv = $kv;
        $this->language = $language;
        $this->market = $market;
        $this->urlGenerator = $urlGenerator;
        $this->installer = $installer;
        $this->appConfig = $appConfig;

        $this->pluginPath = APP_PATH . 'plugins/';
        $this->themePath = APP_PATH . 'themes/';

        // 如果配置中有更准确的物理路径，尝试使用它
        if (!empty($pluginConfig['plugins_path']) && strpos($pluginConfig['plugins_path'], APP_PATH) === 0) {
            $this->pluginPath = $pluginConfig['plugins_path'];
        }
        if (!empty($viewConfig['themes_path']) && strpos($viewConfig['themes_path'], APP_PATH) === 0) {
            $this->themePath = $viewConfig['themes_path'];
        }

        // 强制标准化末尾 "/"
        $this->pluginPath = rtrim($this->pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->themePath = rtrim($this->themePath, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function isLocal(string $dir, string $type): bool
    {
        return is_dir($this->getBasePath($type) . $dir);
    }

    public function getIconUrl(string $dir, string $type, string $remoteIcon = ''): string
    {
        // 1. 优先本地已安装图标
        $path = ($type === 'theme' ? 'themes/' : 'plugins/') . $dir . '/icon.png';
        if (file_exists(APP_PATH . $path)) {
            return $this->appConfig['path'] . $path;
        }

        // 2. 回退到服务端远程图标（未安装时）
        if (!empty($remoteIcon)) {
            // 相对路径兜底：拼接网站根路径，避免浏览器按当前 URL 解析
            if (strpos($remoteIcon, 'http') !== 0 && strpos($remoteIcon, '/') !== 0) {
                return rtrim($this->appConfig['path'], '/') . '/' . ltrim($remoteIcon, '/');
            }
            return $remoteIcon;
        }

        return '';
    }

    public function formatList(array $list): array
    {
        $newList = [];
        foreach ($list as $dir => $item) {
            // 通过 readByDir 合并本地状态（downloaded/installed/enable），确保按钮链接正确
            $type = (2 === (int)($item['type'] ?? 0)) ? 'theme' : 'plugin';
            $fullItem = $this->readByDir((string)$dir, $type, true);
            if (!empty($fullItem)) {
                // 保留官方数据中的 last_sync 等字段
                $fullItem = array_merge($item, $fullItem);
            } else {
                $fullItem = $item;
            }
            $fullItem['operation_links'] = $this->buildOperationLinks($fullItem);
            $fullItem['icon_url'] = $this->getIconUrl((string)$dir, $type, $item['icon'] ?? '');
            $newList[$dir] = $fullItem;
        }

        // 处理主题的父子继承关系 (Variant Themes)
        foreach ($newList as $dir => $item) {
            if (2 === (int)$item['type'] && !empty($item['dependencies_theme'])) {
                $parentDir = key($item['dependencies_theme']);
                if (isset($newList[$parentDir])) {
                    $newList[$parentDir]['children'][$dir] = $item;
                    unset($newList[$dir]);
                }
            }
        }

        return $newList;
    }

    public function buildOperationLinks(array $data): array
    {
        $type = (2 === (int)$data['type']) ? 'theme' : 'plugin';
        $dir = $data['dir'] ?? '';
        $links = ['detail' => '', 'download' => '', 'install' => '', 'uninstall' => '', 'setting' => '', 'enable' => '', 'disable' => '', 'upgrade' => ''];

        if (!$dir) return $links;

        $extra = ['dir' => $dir];
        if (!empty($data['storeid'])) {
            $extra['storeid'] = $data['storeid'];
        }
        if ($data['downloaded']) {
            $links['detail'] = [
                'label' => $this->language->get('detail'),
                'url' => $this->urlGenerator->url('admin/' . $type . '/detail', $extra)
            ];

            // 主题仅未安装时显示 install（已安装时只显示 uninstall）
            if ('theme' === $type) {
                if (0 === (int)$data['installed']) {
                    $links['install'] = ['label' => $this->language->get('install'), 'url' => $this->urlGenerator->url('admin/theme/postInstall', $extra)];
                }
            } else {
                $links['install'] = (0 === (int)$data['installed']) ? ['label' => $this->language->get('install'), 'url' => $this->urlGenerator->url('admin/plugin/postInstall', $extra)] : '';
            }

            if (1 === (int)$data['installed']) {
                $links['uninstall'] = ['label' => $this->language->get('uninstall'), 'url' => $this->urlGenerator->url('admin/' . $type . '/postUninstall', $extra)];

                $settingFile = $this->getBasePath($type) . $dir . '/setting.php';
                if (file_exists($settingFile)) {
                    $links['setting'] = ['label' => $this->language->get('setting'), 'url' => $this->urlGenerator->url('admin/' . $type . '/setting', $extra)];
                }
            }

            if ('plugin' === $type && 1 === (int)$data['installed']) {
                $links['enable'] = (0 === (int)$data['enable']) ? ['label' => $this->language->get('enable'), 'url' => $this->urlGenerator->url('admin/plugin/postEnable', $extra)] : '';

                $links['disable'] = (1 === (int)$data['enable']) ? ['label' => $this->language->get('disable'), 'url' => $this->urlGenerator->url('admin/plugin/postDisable', $extra)] : '';
            }

            // 主题无启用/禁用概念，通过 install 切换激活

            $links['upgrade'] = (1 === (int)$data['installed'] && ($data['has_upgrade'] ?? false)) ? ['label' => $this->language->get('upgrade'), 'url' => $this->urlGenerator->url('admin/store/upgrade', $extra)] : '';
        } else {
            // 本地未下载：显示商店详情 + 下载/购买
            $links['detail'] = ['label' => $this->language->get('detail'), 'url' => $this->urlGenerator->url('admin/store/detail', $extra)];

            if ((int)$data['price'] > 0) {
                // 付费插件：已购买显示下载，未购买显示购买
                // P1 FIX: 统一使用 payment_id 判断购买状态，与 StoreController::detail() 保持一致
                if (!empty($data['payment_id'])) {
                    $links['download'] = ['label' => $this->language->get('download'), 'url' => $this->urlGenerator->url('admin/store/download', $extra)];
                } else {
                    $links['download'] = ['label' => $this->language->get('buy') ?: $this->language->get('payment') ?: 'Buy', 'url' => $this->urlGenerator->url('admin/store/payment', $extra)];
                }
            } else {
                // 免费插件：直接下载
                $links['download'] = ['label' => $this->language->get('download'), 'url' => $this->urlGenerator->url('admin/store/download', $extra)];
            }
        }

        // 防御：确保所有链接数组包含 confirm 键，防止模板直接访问时 Undefined array key
        foreach ($links as &$link) {
            if (is_array($link) && !isset($link['confirm'])) {
                $link['confirm'] = '';
            }
        }
        unset($link);

        return $links;
    }

    public function buildSearchTypes(): array
    {
        return [
            ['field' => 'name', 'label' => $this->language->get('name')],
            ['field' => 'author', 'label' => $this->language->get('author')]
        ];
    }

    public function buildPagination(int $page, int $pageSize, int $total, array $extra = [], string $route = ''): array
    {
        $totalPages = (int)ceil($total / $pageSize);
        $prev = '';
        $next = '';

        if ($page > 1) {
            $extra['page'] = $page - 1;
            $prev = $this->urlGenerator->url($route, $extra);
        }
        if ($page < $totalPages) {
            $extra['page'] = $page + 1;
            $next = $this->urlGenerator->url($route, $extra);
        }

        return [
            'current' => $page,
            'total' => $totalPages,
            'previous_link' => $prev,
            'next_link' => $next,
        ];
    }

    /**
     * 支付成功后强制单条同步（绕过 TTL / 防抖）
     * 使用独立锁名 'market:payment:$dir'，避免与 syncMarketData 竞争
     */
    public function forceSyncSingle(string $dir): void
    {
        if (!$this->market->isLogged()) {
            return;
        }

        try {
            $this->withSyncLock('market:payment:' . $dir, function () use ($dir) {
                $officialData = $this->kv->settingGet('officialData') ?? [];
                $marketData = $this->market->queryExtensions([$dir]);

                if (!empty($marketData[$dir])) {
                    $data = $marketData[$dir];
                    // 服务端返回 name 为空表示已下架，支付场景下不应删除，仅跳过更新
                    if (!empty($data['name'])) {
                        $data['last_sync'] = time();
                        $officialData[$dir] = array_merge($officialData[$dir] ?? [], $data);
                        $this->kv->settingSet('officialData', $officialData);
                    }
                }
            });
        } catch (\Exception $e) {
            // 静默降级：记录日志，不阻塞支付流程
            if ($this->container) {
                try {
                    $this->container->get(\Framework\Logger\LoggerInterface::class)
                        ->warning('forceSyncSingle failed: ' . $e->getMessage(), ['dir' => $dir]);
                } catch (\Throwable $t) {
                    error_log('forceSyncSingle failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * 获取扩展的 storeid（用于构造下载 URL，避免 download 方法回退查询）
     */
    public function getStoreId(string $dir): int
    {
        $officialData = $this->kv->settingGet('officialData') ?? [];
        return (int)($officialData[$dir]['storeid'] ?? 0);
    }

    public function getBasePath(string $type): string
    {
        return $type === 'theme' ? $this->themePath : $this->pluginPath;
    }

    /**
     * 官方数据优先字段白名单
     * 这些字段代表商店权威数据，本地 config.json 无权覆盖
     */
    private const OFFICIAL_PRIORITY_FIELDS = [
        'storeid', 'price', 'payment_id', 'version', 'downloads',
        'rating', 'rating_count', 'is_official', 'has_upgrade',
        'cloud_version', 'last_update', 'screenshots', 'tags',
        'has_bought', 'is_paid', 'type', 'icon',
    ];

    public function readByDir(string $dir, string $type, bool $localFirst = true): array
    {
        $basePath = $this->getBasePath($type);
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';

        $local = [];
        if (file_exists($configFile)) {
            $json = (string)file_get_contents($configFile);
            $local = SecurityHelper::jsonDecode($json) ?? [];
        }

        $officialData = $this->kv->settingGet('officialData') ?? [];
        $official = $officialData[$dir] ?? [];

        if (empty($local) && empty($official)) return [];

        $default = [
            'name' => $dir,
            'price' => 0,
            'brief' => '',
            'version' => '1.0.0',
            'software_version' => '3.0',
            'installed' => 0,
            'enable' => 0,
            'hooks' => [],
            'dependencies' => [],
            'author' => 'Official',
            'type' => $type === 'theme' ? 2 : 1,
            'dir' => $dir,
            'downloads' => 0,
            'last_update' => '',
        ];

        $local = array_merge($default, $local);
        $official = array_merge(['storeid' => 0, 'upgrade' => 0], $official);

        // ─── 核心合并逻辑：官方白名单字段强制优先 ───
        // FIX: 在官方覆盖前保存本地版本号，用于后续 has_upgrade 计算
        $localVersion = (string)($local['version'] ?? '1.0.0');
        $extension = $local;

        // 官方优先字段：无论 localFirst 如何，始终由官方覆盖
        foreach (self::OFFICIAL_PRIORITY_FIELDS as $field) {
            if (array_key_exists($field, $official)) {
                $extension[$field] = $official[$field];
            }
        }

        // 当本地数据缺失时，完全以官方数据补充
        if (empty($local['name']) || $local['name'] === $dir) {
            $extension = array_merge($official, $extension);
        }

        // 当明确要求官方优先时（商店详情页），官方数据整体再覆盖一次
        if (!$localFirst) {
            $extension = array_merge($extension, $official);
        }

        // 主题启用状态特殊修正：以全局 config.theme 为准
        if ($type === 'theme') {
            $settingConfig = $this->kv->settingGet('config') ?? [];
            if (($settingConfig['theme'] ?? '') === $dir) {
                $extension['enable'] = 1;
            }
        }

        $extension['downloaded'] = file_exists($configFile);
        $extension['cloud_version'] = (string)($official['version'] ?? $extension['version']);
        // FIX: 使用本地原始版本号与云端版本比较，避免官方覆盖后 version == cloud_version 导致 has_upgrade 永远为 false
        $extension['has_upgrade'] = (int)($official['has_upgrade'] ?? (
            ($extension['installed'] && version_compare($localVersion, (string)$extension['cloud_version'], '<')) ? 1 : 0
        ));

        return $extension;
    }

    public function getLocalList(string $type): array
    {
        $basePath = $this->getBasePath($type);
        if (!is_dir($basePath)) return [];

        $dirs = scandir($basePath);
        $list = [];
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($basePath . $dir)) {
                $info = $this->readByDir($dir, $type, true);
                if ($info) $list[$dir] = $info;
            }
        }
        return $list;
    }

    public function updateStatus(string $dir, string $type, string $action): array
    {
        $basePath = $this->getBasePath($type);
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';
        if (!file_exists($configFile)) return ['status' => 'error', 'message' => 'config.json missing'];

        $json = (string)file_get_contents($configFile);
        $config = SecurityHelper::jsonDecode($json);

        switch ($action) {
            case 'enable':
                $config['enable'] = 1;
                break;
            case 'disable':
                $config['enable'] = 0;
                break;
            case 'install':
            case 'upgrade':
                $config['installed'] = 1;
                $config['enable'] = 1;
                break;
            case 'uninstall':
                $config['installed'] = 0;
                $config['enable'] = 0;
                break;
        }

        file_put_contents($configFile, SecurityHelper::jsonEncode($config, true));
        $this->runActionScript($dir, $type, $action);
        return ['status' => 'success'];
    }

    public function execute(string $dir, string $type, string $action): array
    {
        $localList = $this->getLocalList($type);
        if (!isset($localList[$dir])) return ['status' => 'error', 'message' => 'Extension not found'];

        $name = $localList[$dir]['name'];
        \Framework\Utils\FileHelper::lock($dir);
        try {
            $missing = $this->checkDependencies($dir, $type, $action);
            if ($missing) {
                $list = implode(', ', array_keys($missing));
                $msg = in_array($action, ['install', 'enable', 'upgrade'])
                    ? $this->language->get('extension_dependency_following', ['s' => $list])
                    : $this->language->get('extension_being_dependent_cant_delete', ['name' => $name, 's' => $list]);
                return ['status' => 'error', 'message' => $msg];
            }

            $res = $this->updateStatus($dir, $type, $action);
            if ($res['status'] === 'error') return $res;

            // 主题状态变更同步至 KV 层（须在编译缓存清理前完成）
            if ($type === 'theme') {
                $config = $this->kv->settingGet('config') ?? [];
                if ($action === 'install') {
                    // ★ 旧主题降权：切换时标记为未安装（直接写 config.json，不跑脚本）
                    $oldTheme = $config['theme'] ?? '';
                    if ($oldTheme !== '' && $oldTheme !== $dir) {
                        // 不卸载依赖关系：新主题依赖旧主题时（如子主题安装），保留父主题
                        $newDeps = $localList[$dir]['dependencies_theme'] ?? [];
                        $dependsOnOld = isset($newDeps[$oldTheme]) || in_array($oldTheme, $newDeps);
                        if (!$dependsOnOld) {
                            $this->markThemeUninstalled($oldTheme);
                        }
                    }
                    $config['theme'] = $dir;
                    $this->kv->settingSet('config', $config);
                } elseif ($action === 'uninstall') {
                    if (($config['theme'] ?? '') === $dir) {
                        // 构建安全回退候选池：已安装且独立（无父主题依赖）
                        $themeList = $this->getLocalList('theme');
                        $fallbackDir = '';
                        foreach ($themeList as $tDir => $tInfo) {
                            if (empty($tInfo['installed']) || !empty($tInfo['dependencies_theme'])) {
                                continue;
                            }
                            if ($tDir === 'well_demo') {
                                $fallbackDir = $tDir;
                                break;
                            }
                            if ($fallbackDir === '') {
                                $fallbackDir = $tDir;
                            }
                        }
                        $config['theme'] = $fallbackDir;
                        try {
                            $this->kv->settingSet('config', $config);
                        } catch (\Exception $e) {
                            if ($this->container) {
                                try {
                                    $this->container->get(\Framework\Logger\LoggerInterface::class)->error('Theme uninstall KV update failed: ' . $e->getMessage());
                                } catch (\Throwable $t) {
                                    error_log('Theme uninstall KV update failed: ' . $e->getMessage());
                                }
                            }
                        }
                        if ($fallbackDir === '') {
                            if ($this->container) {
                                try {
                                    $this->container->get(\Framework\Logger\LoggerInterface::class)->critical('Theme fallback failed: no available independent theme after uninstalling ' . $dir);
                                } catch (\Throwable $t) {
                                    error_log('[CRITICAL] Theme fallback failed: no available independent theme after uninstalling ' . $dir);
                                }
                            }
                        }
                    }
                }
            }

            // 插件/主题状态变更，强制清除全站编译缓存（重触发聚合、钩子注入和语言包编译）
            \App\Core\Compile::flushCache('all');

            \Framework\Utils\Runtime::reload();
            return ['status' => 'success', 'name' => $name];
        } finally {
            \Framework\Utils\FileHelper::unlock($dir);
        }
    }

    public function deploy(string $dir, string $type, int $storeId): array
    {
        $action = $this->isLocal($dir, $type) ? 'upgrade' : 'install';
        $res = $this->installer->execute($dir, $type, $storeId);
        if ($res['status'] !== 'success') return $res;

        $this->updateStatus($dir, $type, $action);
        $this->syncMarketData([$dir]);

        // 通知服务端下载完成，清理临时文件
        $credentials = $this->market->getCredentials();
        if (!empty($credentials) && !empty($credentials['user_id'])) {
            try {
                $this->market->notifyDownloadComplete((int)$credentials['user_id']);
            } catch (\Exception $e) {
                // 清理通知失败不影响主流程
            }
        }

        \Framework\Utils\Runtime::reload();
        return ['status' => 'success', 'dir' => $dir];
    }

    /**
     * 将主题标记为未安装（直接写 config.json，不执行 uninstall 脚本）
     * 同时清理依赖该主题的子主题
     */
    private function markThemeUninstalled(string $dir): void
    {
        $basePath = $this->getBasePath('theme');
        // 自身标记为未安装
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';
        if (file_exists($configFile)) {
            $json = (string)file_get_contents($configFile);
            $config = \Framework\Utils\SecurityHelper::jsonDecode($json);
            $config['installed'] = 0;
            $config['enable'] = 0;
            file_put_contents($configFile, \Framework\Utils\SecurityHelper::jsonEncode($config, true));
        }

        // 查找并清理依赖该主题的子主题，记录被清理的目录
        $uninstalledChildren = [$dir];
        $allThemes = $this->getLocalList('theme');
        foreach ($allThemes as $tDir => $tInfo) {
            if ($tDir === $dir) continue;
            $deps = $tInfo['dependencies_theme'] ?? [];
            if (isset($deps[$dir]) || in_array($dir, $deps)) {
                $childFile = $basePath . $tDir . DIRECTORY_SEPARATOR . 'config.json';
                if (file_exists($childFile)) {
                    $json = (string)file_get_contents($childFile);
                    $cfg = \Framework\Utils\SecurityHelper::jsonDecode($json);
                    $cfg['installed'] = 0;
                    $cfg['enable'] = 0;
                    file_put_contents($childFile, \Framework\Utils\SecurityHelper::jsonEncode($cfg, true));
                }
                $uninstalledChildren[] = $tDir;
            }
        }

        // 级联清理：该主题的父依赖若无其他已安装子主题引用，也标记为未安装
        $parentDeps = $allThemes[$dir]['dependencies_theme'] ?? [];
        $queue = [];
        if (is_array($parentDeps)) {
            foreach ($parentDeps as $k => $v) {
                // dependencies_theme 格式: ['parent_dir' => '1.0'] 或 ['parent_dir']
                $queue[] = is_string($k) && !is_numeric($k) ? $k : (is_string($v) ? $v : '');
            }
        }
        $visited = [];
        while (!empty($queue)) {
            $pDir = array_shift($queue);
            if (empty($pDir) || $pDir === $dir || in_array($pDir, $visited)) continue;
            $visited[] = $pDir;
            // 检查是否还有其他已安装主题依赖此父主题（排除本次已卸载的）
            $stillNeeded = false;
            foreach ($allThemes as $tDir2 => $tInfo2) {
                if ($tDir2 === $pDir || in_array($tDir2, $uninstalledChildren)) continue;
                $deps2 = $tInfo2['dependencies_theme'] ?? [];
                $hasInstalled = isset($tInfo2['installed']) && (int)$tInfo2['installed'] === 1;
                if (!$hasInstalled) continue;
                if (isset($deps2[$pDir]) || in_array($pDir, $deps2)) {
                    $stillNeeded = true;
                    break;
                }
            }
            if (!$stillNeeded) {
                $parentFile = $basePath . $pDir . DIRECTORY_SEPARATOR . 'config.json';
                if (file_exists($parentFile)) {
                    $json = (string)file_get_contents($parentFile);
                    $cfg = \Framework\Utils\SecurityHelper::jsonDecode($json);
                    $cfg['installed'] = 0;
                    $cfg['enable'] = 0;
                    file_put_contents($parentFile, \Framework\Utils\SecurityHelper::jsonEncode($cfg, true));
                }
                $uninstalledChildren[] = $pDir;
            }
        }
    }

    private function runActionScript(string $dir, string $type, string $action): void
    {
        $script = $this->getBasePath($type) . $dir . DIRECTORY_SEPARATOR . $action . '.php';
        if (file_exists($script)) include \App\Core\Compile::include($script);
    }

    public function checkDependencies(string $dir, string $type, string $action): array
    {
        $currentList = $this->getLocalList($type);
        $ext = $currentList[$dir] ?? null;
        if (!$ext) return [];

        $missing = [];
        if (in_array($action, ['install', 'enable', 'upgrade'])) {
            // 1. 检查通用依赖 (通常指向 plugins)
            $deps = $ext['dependencies'] ?? [];
            $pluginList = ($type === 'plugin') ? $currentList : $this->getLocalList('plugin');

            foreach ($deps as $depDir => $minVer) {
                if ($depDir === 'core') {
                    if (version_compare($this->appConfig['version'] ?? '3.0', (string)$minVer, '<')) {
                        $missing['core'] = $minVer;
                    }
                    continue;
                }

                $depExt = $pluginList[$depDir] ?? null;
                if (!$depExt || !$depExt['installed'] || !$depExt['enable'] || version_compare((string)$depExt['version'], (string)$minVer, '<')) {
                    $missing[$depDir] = $minVer;
                }
            }

            // 2. 检查主题专属依赖
            if ($type === 'theme' && !empty($ext['dependencies_theme'])) {
                $themeList = $currentList;
                foreach ($ext['dependencies_theme'] as $depDir => $minVer) {
                    $depExt = $themeList[$depDir] ?? null;
                    if (!$depExt || !$depExt['installed'] || !$depExt['enable'] || version_compare((string)$depExt['version'], (string)$minVer, '<')) {
                        $missing[$depDir] = $minVer;
                    }
                }
            }
        } else {
            // 卸载/禁用时的反向检查：是否有任何其他插件或主题依赖我？
            $allExtensions = array_merge($this->getLocalList('plugin'), $this->getLocalList('theme'));
            foreach ($allExtensions as $otherDir => $otherExt) {
                if ($otherDir === $dir) continue;
                $allDeps = array_merge($otherExt['dependencies'] ?? [], $otherExt['dependencies_theme'] ?? []);
                // 只要该扩展已安装且声明了依赖，就不允许卸载被依赖者
                if (isset($allDeps[$dir]) && $otherExt['installed']) {
                    $missing[$otherDir] = $otherExt['version'];
                }
            }
        }
        return $missing;
    }

    /**
     * 在分布式锁保护下执行同步逻辑
     * 使用 CacheManager::lock()（Redis SET NX），协程安全
     * 锁获取失败时静默跳过，避免阻塞用户
     */
    private function withSyncLock(string $name, callable $callback): void
    {
        if (!$this->container) return;
        $cache = $this->container->get(\Framework\Cache\Interfaces\CacheInterface::class);
        $lockKey = 'lock:sync:' . $name . ':' . md5($this->appConfig['path'] ?? '');
        // 锁 TTL 60 秒，匹配 MarketClient 超时（30s）+ 网络抖动 + 分页查询耗时
        $token = $cache->lock($lockKey, 60);
        if (!$token) {
            return;
        }
        try {
            $callback();
        } finally {
            $cache->unlock($lockKey, $token);
        }
    }

    public function syncMarketData(array $dirs, bool $force = false): void
    {
        if (empty($dirs)) return;
        if (!$this->market->isLogged() && !$force) return;

        // force=true 时（点击"重新获取数据"）增加 60 秒防抖
        if ($force) {
            $lastForceSync = (int)($this->kv->settingGet('storeForceSyncLastTime') ?? 0);
            if ((time() - $lastForceSync) < 60) {
                return;
            }
        }

        $this->withSyncLock('market', function () use ($dirs, $force) {
            // 双重检查
            if ($force) {
                $lastForceSync = (int)($this->kv->settingGet('storeForceSyncLastTime') ?? 0);
                if ((time() - $lastForceSync) < 60) {
                    return;
                }
            }

            $officialData = $this->kv->settingGet('officialData') ?? [];
            $now = time();
            $toSync = [];

            foreach ($dirs as $dir) {
                $lastSync = $officialData[$dir]['last_sync'] ?? 0;
                if ($force || ($now - $lastSync) > self::SYNC_TTL) {
                    $toSync[] = $dir;
                }
            }

            if (!empty($toSync)) {
                // 分批查询，每批不超过服务端 50 个上限
                $batchSize = 50;
                $dirBatches = array_chunk($toSync, $batchSize);
                foreach ($dirBatches as $batch) {
                    $marketData = $this->market->queryExtensions($batch);
                    if ($marketData) {
                        foreach ($marketData as $dir => $data) {
                            // 服务端未找到该扩展（已下架/删除），从本地缓存中移除
                            if (empty($data['name'])) {
                                unset($officialData[$dir]);
                                continue;
                            }
                            $data['last_sync'] = $now;
                            // P2 FIX: 若服务端响应未返回关键购买字段，显式重置默认值，防止旧缓存状态残留
                            if (!array_key_exists('payment_id', $data)) {
                                $data['payment_id'] = 0;
                            }
                            if (!array_key_exists('has_bought', $data)) {
                                $data['has_bought'] = false;
                            }
                            if (!array_key_exists('price', $data)) {
                                $data['price'] = 0;
                            }
                            $officialData[$dir] = array_merge($officialData[$dir] ?? [], $data);
                        }
                    }
                }
            }

            $this->kv->settingSet('officialData', $officialData);

            if ($force) {
                $this->kv->settingSet('storeForceSyncLastTime', $now);
            }
        });
    }

    /**
     * 全量同步官方商店目录
     * 调用服务端 sync.html 游标分页接口，获取全部上架扩展
     * 300 秒 TTL + 互斥锁 + DCL
     */
    public function syncOfficialCatalog(): void
    {
        if (!$this->market->isLogged()) {
            return;
        }

        // 外层 TTL 检查（避免不必要的锁竞争）
        $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
        $catalogSyncInterval = 300; // 5 分钟
        if ((time() - $lastCatalogSync) < $catalogSyncInterval) {
            return;
        }

        $this->withSyncLock('catalog', function () {
            // 双重检查锁（DCL）：锁内再检查一次 TTL
            $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
            if ((time() - $lastCatalogSync) < 300) {
                return;
            }

            $officialData = $this->kv->settingGet('officialData') ?? [];
            $now = time();
            $cursor = null;
            $hasMore = true;
            $maxPages = 10; // 安全上限，防止无限循环
            $page = 0;
            $syncedCount = 0;
            $requestSuccess = false;

            while ($hasMore && $page < $maxPages) {
                $page++;

                $params = [
                    'cursor' => $cursor,
                    'limit' => 100,
                    'last_sync' => 0,
                    'dir_flag' => 'next',
                ];

                $response = $this->market->request('sync.html', $params);
                $result = SecurityHelper::jsonDecode($response);

                if (empty($result) || ($result['status'] ?? '') !== 'success') {
                    break;
                }

                $requestSuccess = true;

                $items = $result['data']['items'] ?? [];
                foreach ($items as $item) {
                    $dir = $item['dir'] ?? '';
                    if (empty($dir)) continue;

                    $item['last_sync'] = $now;
                    $officialData[$dir] = array_merge($officialData[$dir] ?? [], $item);
                    $syncedCount++;
                }

                $hasMore = !empty($result['data']['has_more']);
                $cursor = $hasMore ? ($result['data']['cursor'] ?? null) : null;
            }

            // 使用 $requestSuccess 替代 $syncedCount > 0
            // 原因：请求成功但 items 为空时（所有扩展下架/服务端无数据），仍需清理旧数据
            if ($requestSuccess) {
                // 清理：1) 未被本轮同步覆盖的旧条目；2) 明确标记为非官方的条目
                // syncOfficialCatalog 是全量同步（last_sync=0），本轮所有已上架扩展都会被返回。
                // 已下架的扩展不会被覆盖 last_sync，应在本轮同步后立即清理。
                // $now 在函数开始时固定，所有分页批次共用同一标记，不存在多批时间差问题。
                if (!empty($officialData)) {
                    foreach ($officialData as $dir => $item) {
                        if (($item['last_sync'] ?? 0) < $now) {
                            unset($officialData[$dir]);
                            continue;
                        }
                        if (isset($item['is_official']) && empty($item['is_official'])) {
                            unset($officialData[$dir]);
                        }
                    }
                    $this->kv->settingSet('officialData', $officialData);
                }
                // 无论 officialData 是否为空，都更新 lastSync，避免下次又触发全量同步
                $this->kv->settingSet('officialCatalogLastSync', $now);
            }
        });
    }

    /**
     * 增量同步：下架清理 + 重新上架发现
     * 调用服务端 sync.html 增量模式，同时处理：
     * 1. removed_dirs：精准删除已下架扩展
     * 2. items：发现重新上架的扩展（updated_at > lastCatalogSync 且 status=1）
     * 独立 TTL = 60 秒，不依赖 syncOfficialCatalog 和 syncMarketData
     */
    public function syncRemovedDirs(): void
    {
        if (!$this->market->isLogged()) {
            return;
        }

        $lastRemovedSync = (int)($this->kv->settingGet('storeRemovedSyncLastTime') ?? 0);
        if ((time() - $lastRemovedSync) < 60) {
            return;
        }

        $this->withSyncLock('removed', function () {
            $lastRemovedSync = (int)($this->kv->settingGet('storeRemovedSyncLastTime') ?? 0);
            if ((time() - $lastRemovedSync) < 60) {
                return;
            }

            $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
            if ($lastCatalogSync <= 0) {
                return;
            }

            $officialData = $this->kv->settingGet('officialData') ?? [];

            // limit 仅影响增量上架 items，不影响 removed_dirs（独立查询，上限 1000）
            $response = $this->market->request('sync.html', [
                'last_sync' => $lastCatalogSync,
                'limit' => 100,
                'dir_flag' => 'next',
            ]);
            $result = SecurityHelper::jsonDecode($response);

            if (empty($result) || ($result['status'] ?? '') !== 'success') {
                return;
            }

            $changed = false;

            // 1) 下架清理
            $removedDirs = $result['data']['removed_dirs'] ?? [];
            foreach ($removedDirs as $dir) {
                if (isset($officialData[$dir])) {
                    unset($officialData[$dir]);
                    $changed = true;
                }
            }

            // 2) 重新上架发现：增量 items 包含 updated_at > lastCatalogSync 的已上架扩展
            $items = $result['data']['items'] ?? [];
            $now = time();
            foreach ($items as $item) {
                $dir = $item['dir'] ?? '';
                if (empty($dir)) continue;
                $item['last_sync'] = $now;
                $officialData[$dir] = array_merge($officialData[$dir] ?? [], $item);
                $changed = true;
            }

            if ($changed) {
                $this->kv->settingSet('officialData', $officialData);
            }
            $this->kv->settingSet('storeRemovedSyncLastTime', $now);
        });
    }
}
