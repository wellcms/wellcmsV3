<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

/**
 * public/index.php
 * WellCMS 3.0 首页入口
 * - PHP 7.2+
 * - 无 Composer 依赖，使用 core/Autoload.php + core/Compile.php
 * - 加载顺序：环境 -> Autoload -> Compile -> Bootstrap -> 输出 Response
 */

// 应用根目录与合法入口标识
define('APP_PATH', str_replace('\\', DIRECTORY_SEPARATOR, dirname(__DIR__)) . DIRECTORY_SEPARATOR);
define('IN_WELLCMS', true);
/* 在其他 PHP 文件中，尤其是插件、模块或模板文件中，可以通过检查该常量是否定义，来防止直接访问这些文件，从而增强安全性。这种做法可以防止用户直接通过 URL 访问非入口文件，确保所有请求都经过框架的统一处理流程。例如：
if (!defined('IN_WELLCMS')) {
    exit('Access Denied');
} */

// DEBUG 模式:环境变量 DEBUG=0|1|2 (0=线上，1=调试，2=开发)
define('DEBUG', 0);
// 错误显示
if (DEBUG >= 2) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} elseif (1 === DEBUG) {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// 错误日志是否同时记录原始绝对路径（用于审计）。
// false：日志与调试输出中文件路径相对 APP_PATH，便于本地排查；
// true ：额外保留 absolute_file 字段，记录服务器绝对路径。
define('LOG_ABSOLUTE_PATH', false);

// 核心加载：Compile -> Autoload
require APP_PATH . 'app/Core/Compile.php';
// 注册自动加载
require APP_PATH . 'app/Core/Autoload.php';

try {
    $containerCache = APP_PATH . 'storage/tmp/container.php';

    // 初始化 Compile：收集插件钩子
    \App\Core\Compile::init($containerCache ?? null);

    // 创建容器并优先加载编译定义，让 preResolve() 使用 buildFromDefinition()
    $container = new \Framework\Core\Container();
    if (file_exists($containerCache) && (!defined('DEBUG') || DEBUG == 0)) {
        $defs = require $containerCache;
        $container->loadDefinitions($defs);
    }

    // 引导（Bootstrap）并传入已有容器
    \App\Bootstrap::init($container);

    // 如果是调度器模式，跳过Web处理
    if (defined('SCHEDULER_MODE') && SCHEDULER_MODE) return;

    // 构造 PSR-7 ServerRequestFactory
    $request = \Framework\Http\Psr7\Factories\ServerRequestFactory::getInstance()->createFromGlobals();

    // 调用核心调度（Kernel）处理请求
    \App\Core\Kernel::run($container, $request);
} catch (\Throwable $e) {
    // 统一异常处理 (Standardization)
    // 此时 container 可能未初始化成功，需做容错
    $handler = new \App\Core\ExceptionHandler(
        isset($container) ? $container : null,
        (bool)\DEBUG,
        (isset($container) && $container->has('appConfig'))
            ? ($container->get('appConfig')['error_handling'] ?? [])
            : []
    );
    $response = $handler->handle($e, isset($request) ? $request : null);

    // 由调用方根据环境决定如何发射（FPM 下使用原生 PHP 函数，Swoole 下在 SwooleServer 中独立处理）
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code($response['statusCode']);
            foreach ($response['headers'] as $headerName => $headerValue) {
                header("{$headerName}: {$headerValue}");
            }
        }
    }
    echo $response['body'];
}


/* $strfile = APP_PATH . 'log.txt';
$s = \Framework\Utils\FileHelper::fileGetContentsTry($strfile);
\Framework\Utils\FileHelper::filePutContentsTry($strfile, $s . "\r\complete:\r\n" . var_export($filename, true)); */