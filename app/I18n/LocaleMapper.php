<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\I18n;

class LocaleMapper
{
    /**
     * 外部语言代码 → ICU 语言代码 映射
     * 外部传入：zh, tw, en
     * 内部使用：zh-CN, zh-TW, en-US
     */
    public static function map()
    {
        // hook app_I18n_LocaleMapper_map_start.php
        $map = [
            'zh'  => 'zh-CN', // 简体中文
            'tw'  => 'zh-TW', // 繁体中文
            'en'  => 'en-US', // 英文（美国）
            'es'  => 'es-ES', // 西班牙语（西班牙）
            'fr'  => 'fr-FR', // 法语（法国）
            'de'  => 'de-DE', // 德语（德国）
            'ru'  => 'ru-RU', // 俄语（俄罗斯）
            'ja'  => 'ja-JP', // 日语（日本）
            'ko'  => 'ko-KR', // 韩语（韩国）
            'pt'  => 'pt-PT', // 葡萄牙语（葡萄牙）
            'ar'  => 'ar-SA', // 阿拉伯语（沙特）
            'hi'  => 'hi-IN', // 印地语（印度）
            'it'  => 'it-IT', // 意大利语（意大利）
            'tr'  => 'tr-TR', // 土耳其语（土耳其）
            'vi'  => 'vi-VN', // 越南语（越南）
            'id'  => 'id-ID', // 印度尼西亚语（印尼）
            'fa'  => 'fa-IR', // 波斯语（伊朗）
            'th'  => 'th-TH', // 泰语（泰国）
            'bn'  => 'bn-BD', // 孟加拉语（孟加拉国）
            'ur'  => 'ur-PK', // 乌尔都语（巴基斯坦）
            'nl'  => 'nl-NL', // 荷兰语（荷兰）
            'sv'  => 'sv-SE', // 瑞典语（瑞典）
            'pl'  => 'pl-PL', // 波兰语（波兰）
            'cs'  => 'cs-CZ', // 捷克语（捷克）
            'ro'  => 'ro-RO', // 罗马尼亚语（罗马尼亚）
            'el'  => 'el-GR', // 希腊语（希腊）
            'he'  => 'he-IL', // 希伯来语（以色列）
            'hu'  => 'hu-HU', // 匈牙利语（匈牙利）
            'fi'  => 'fi-FI', // 芬兰语（芬兰）
            'da'  => 'da-DK', // 丹麦语（丹麦）
            'no'  => 'no-NO', // 挪威语（挪威）
            'uk'  => 'uk-UA', // 乌克兰语（乌克兰）
            'ms'  => 'ms-MY', // 马来语（马来西亚）
            'sr'  => 'sr-RS', // 塞尔维亚语（塞尔维亚）
            'sk'  => 'sk-SK', // 斯洛伐克语（斯洛伐克）
            'hr'  => 'hr-HR', // 克罗地亚语（克罗地亚）
            'bg'  => 'bg-BG', // 保加利亚语（保加利亚）
            'sl'  => 'sl-SI', // 斯洛文尼亚语（斯洛文尼亚）
            'et'  => 'et-EE', // 爱沙尼亚语（爱沙尼亚）
            'lv'  => 'lv-LV', // 拉脱维亚语（拉脱维亚）
            'lt'  => 'lt-LT', // 立陶宛语（立陶宛）
            'ca'  => 'ca-ES', // 加泰罗尼亚语（西班牙）
            'af'  => 'af-ZA', // 南非荷兰语（南非）
            'fil' => 'fil-PH', // 菲律宾语（菲律宾）
        ];
        // hook app_I18n_LocaleMapper_map_end.php
        return $map;
    }

    /**
     * 将外部语言代码转换为 ICU 风格
     */
    public static function toICU(string $lang): string
    {
        // hook app_I18n_LocaleMapper_toICU_start.php
        $lang = strtolower(trim($lang));
        $map = self::map();
        // hook app_I18n_LocaleMapper_toICU_end.php
        return $map[$lang] ?? null;
    }
}
