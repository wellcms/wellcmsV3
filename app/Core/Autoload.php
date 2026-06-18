<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

use App\Core\Compile;

if (!defined('IN_WELLCMS')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo 'Access Denied';
    return;
}

/**
 * 核心自动加载器
 * 使用前缀映射方案支持 src/ app/ core/ 路径与插件、主题类的自动加载
 * 配合 Classmap 生成脚本（bin/generate_classmap.php）日 PV 千万级的生产系统无压力
 * 测试环境：PHP 7.2 + Opcache enabled, 2.5 GHz Intel Core i7, Ubuntu
 */
spl_autoload_register(function ($className) {
    if (class_exists($className, false)) return;

    static $classmap;

    // 惰性加载 Classmap
    if ($classmap === null) {
        $classmapFile = APP_PATH . 'storage/tmp/classmap.php';
        $classmap = file_exists($classmapFile) ? require $classmapFile : [];
    }

    // Classmap 优先
    if (isset($classmap[$className])) {
        require Compile::include($classmap[$className]);
        return;
    }

    // 动态加载逻辑（协程安全设计）
    $modules = [
        ['prefix' => 'App\\', 'baseDir' => 'app/'],
        ['prefix' => 'Framework\\', 'baseDir' => 'src/'],
        ['prefix' => 'Plugins\\', 'baseDir' => 'plugins/'],
        ['prefix' => 'Themes\\', 'baseDir' => 'themes/']
    ];

    foreach ($modules as $module) {
        $prefix = $module['prefix'];
        $prefixLen = strlen($prefix);
        if (strncmp($className, $prefix, $prefixLen) !== 0) continue;

        $relativeClass = substr($className, $prefixLen);
        $file = APP_PATH . $module['baseDir'] . strtr($relativeClass, '\\', '/') . '.php';
        if (!file_exists($file)) continue;

        require Compile::include($file);
        return;
    }
}, true);
