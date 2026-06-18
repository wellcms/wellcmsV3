<?php
// 国际化与 URL 语言包配置
return [
    // 时区与语言
    'timezone' => 'Asia/Shanghai', // UTC

    // 默认与后备语言
    'locale' => 'zh', // 主程序语言
    'fallback_locale' => 'en', // 后备语言

    // 支持的语言列表
    'supported' => ['zh', 'tw', 'en', 'de', 'fr', 'ja', 'nl', 'ko', 'es', 'pt', 'it', 'ru', 'ar', 'tr'],

    // 语言包存放路径（相对 APP_PATH）
    'paths' => [
        'app' => '/app/Language/',    // 主程序语言包
        'plugins' => '/plugins/',     // 插件语言包基目录，实际合并时按 PluginName/Language/{lang}/...
        'themes' => '/themes/',       // 主题语言包基目录，实际覆盖时按 ThemeName/Language/{lang}/...
    ],
];
