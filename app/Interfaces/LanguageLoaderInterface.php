<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Interfaces;

/**
 * 语言包加载器接口
 */
interface LanguageLoaderInterface
{
    /**
     * 加载安装语言包
     *
     * @param string $locale 语言标识，如 'en'、'zh'、'tw'、'fr'
     * @return array 返回语言项键值对
     */
    public function loadInstall(string $locale): array;

    /**
     * 加载后台语言包
     *
     * @param string $locale
     * @return array
     */
    public function loadAdmin(string $locale): array;

    /**
     * 加载前台语言包
     *
     * @param string $locale
     * @return array
     */
    public function loadLanguage(string $locale): array;

    /**
     * 获取语言包中的翻译项
     *
     * @param string $key 语言键名
     * @param array $replacements 替换参数 [placeholder => replacement]
     * @return mixed
     */
    public function get(string $key = '', array $replacements = []);
}
