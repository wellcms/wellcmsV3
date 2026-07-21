<?php
// 视图/模板配置
return [
    // 支持的设备前缀
    'device_prefixes' => [
        'mobile' => 'm.',
        'tablet' => 'pad.',
        'desktop' => '',
    ],

    'themes_path' => '/themes/', // 主题路径

    // 目录结构：view、js、images、language 子目录
    //'dirs' => ['view', 'js', 'images', 'language'],

    // 如何从 KV 表获取并排序主题：
    //  1. 获取 themes KV 值：[{name,dir,parent,installed_at},...]
    //  2. 筛选出 父主题 与 其 子主题（parent 字段匹配）
    //  3. 按 installed_at 倒序排列子主题列表
    'theme_loader' => function (array $kvThemes) {
        // $kvThemes: all theme records from KV
        $siteTheme = [];  // 单一 site-config KV["theme"] 
        foreach ($kvThemes as $t) {
            if (empty($t['parent'])) {
                $siteTheme = $t;
                break;
            }
        }

        // 筛选子主题
        $children = array_filter(
            $kvThemes,
            function ($t) use ($siteTheme) {
                return $t['parent'] === $siteTheme['name'];
            }
        );
        usort($children, function ($a, $b) {
            return $b['installed_at'] <=> $a['installed_at'];
        });
        return array_merge([$siteTheme], $children);
    },
];
