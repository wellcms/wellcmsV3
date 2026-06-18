<?php
// 日志系统配置
$basePath = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2) . '/';
return [
    // 日志通道：file 或 syslog
    'channel' => 'file',

    // 日志级别：debug, info, notice, warning, error…
    'level' => 'error',

    // 文件日志配置
    'file' => [
        'path' => $basePath . 'storage/logs/' . date('Ym') . '/app.log',
        'max_files' => 6,  // 保留最近6个月日志
    ],

    // Syslog 配置
    'syslog' => [
        'ident' => 'wellcms',
        'facility' => LOG_USER,
        'option' => LOG_PID, // 记录PID
        // 其他可根据需要设置：LOG_CONS、LOG_NDELAY 等
    ],
];
