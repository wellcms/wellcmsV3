<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\I18n;

class LanguageManager implements \App\Interfaces\LanguageLoaderInterface
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var string */
    protected $appPath;
    /** @var string */
    protected $pluginPath;
    /** @var string */
    protected $themePath;
    /** @var array */
    protected $I18nConfig;
    /** @var \App\Services\System\KeyValueService */
    protected $keyValueService;

    public function __construct(array $i18nConfig, \App\Services\System\KeyValueService $keyValueService)
    {
        $this->I18nConfig = $i18nConfig;
        $this->keyValueService = $keyValueService;

        $this->appPath     = rtrim($i18nConfig['paths']['app'] ?? '', '/') . '/';
        $this->pluginPath  = rtrim($i18nConfig['paths']['plugins'] ?? '', '/') . '/';
        $this->themePath   = rtrim($i18nConfig['paths']['themes'] ?? '', '/') . '/';
    }

    public function loadInstall(string $locale): array
    {
        return $this->load($locale, 'install');
    }

    public function loadAdmin(string $locale): array
    {
        return $this->load($locale, 'admin');
    }

    public function loadLanguage(string $locale): array
    {
        return $this->load($locale, 'language');
    }

    /**
     * 根据类型加载合并所有语言包
     * 支持 fallback_locale 降级逻辑
     *
     * @param string $locale 语言包名
     * @param string $type 文件名（不含 .php）
     * @return array
     */
    protected function load(string $locale, string $type): array
    {
        $fallback = $this->I18nConfig['fallback_locale'] ?? '';
        $locales = array_unique(array_filter([$fallback, $locale]));

        $finalItems = [];

        foreach ($locales as $l) {
            // 收集当前语种的主题语言包路径 (Parent -> Child)
            [$parent, $children] = $this->getThemeHierarchy();
            $themeFiles = [];

            if ($parent) {
                $themeFiles[] = $this->themePath . $parent . '/Language/' . $l . '/' . $type . '.php';
            }

            // 按时间排序子主题并收集路径
            usort($children, function ($a, $b) {
                return (int)($a['date'] ?? 0) - (int)($b['date'] ?? 0);
            });
            foreach ($children as $child) {
                $themeFiles[] = $this->themePath . $child['dir'] . '/Language/' . $l . '/' . $type . '.php';
            }

            // 调用扁平化编译引擎获取加载路径
            // 该方法内部已自动处理核心包、插件包、主题包及碎片钩子的物理合并
            $compiledFile = \App\Core\Compile::includeLang($l, $type, $themeFiles);

            // 运行时直接 include 编译结果，O(1) 性能
            $items = include $compiledFile;

            if (is_array($items)) {
                $finalItems = array_replace_recursive($finalItems, $items);
            }
        }

        $this->setState('languageItem', $finalItems);

        return $finalItems;
    }

    /**
     * @return array
     */
    public function get(string $key = '', array $replacements = [])
    {
        $language = $this->getState('languageItem', []);
        if (empty($key)) return $language;

        if (!isset($language[$key])) {
            return "language pack does not exist {{$key}}";
        }

        $value = $language[$key];
        foreach ($replacements as $placeholder => $replacement) {
            $value = strtr($value, ['{' . $placeholder . '}' => $replacement]); //str_replace('{' . $placeholder . '}', $replacement, $value);
        }

        return $value;
    }

    /**
     * 获取当前主题及子主题信息
     * @return array [ parentThemeDir|null, [['dir'=>子主题目录,'date'=>安装时间], ...] ]
     */
    protected function getThemeHierarchy(): array
    {
        $cfg = $this->keyValueService->settingGet('themes') ?: [];
        return [$cfg['theme'] ?? null, $cfg['children'] ?? []];
    }
}

/*
use App\I18n\LanguageManager;
use App\Services\System\KeyValueService;
use Framework\Core\Container;

$container = new Container();
$i18nConfig = [];
$kv = $container->get(KeyValueService::class);
$lang = new LanguageManager($i18nConfig, $kv);

// 安装阶段
$installTexts = $lang->loadInstall('zh');
// 后台页面
$adminTexts   = $lang->loadAdmin('en');
// 前台页面（根据用户选择或默认）
$frontendTexts = $lang->loadLanguage('fr');
*/
