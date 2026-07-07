<?php

declare(strict_types=1);

namespace Framework\Utils;

/**
 * WellCMS 3.0 运行时环境管理工具
 */
class Runtime
{
    /** @var string PID 文件路径 */
    private static $pidFile = '';

    /**
     * 获取 PID 文件路径
     */
    public static function getPidFile(): string
    {
        if (empty(self::$pidFile)) {
            $path = \defined('APP_PATH') ? \APP_PATH : \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
            self::$pidFile = $path . 'storage/tmp/wellcms.pid';
        }
        return self::$pidFile;
    }

    /**
     * 设置 PID 文件路径
     */
    public static function setPidFile(string $path): void
    {
        self::$pidFile = $path;
    }

    /**
     * 触发热重载信号 (SIGUSR1)
     * 用于在安装插件、切换主题或清空缓存后同步更新 Swoole Worker 内存。
     * 支持从 PHP-FPM 或 CLI 模式触发。
     * 
     * @return bool 是否成功发送信号
     */
    public static function reload(): bool
    {
        // 1. 检查 posix 扩展支持
        if (!function_exists('posix_kill')) {
            return false;
        }

        // 2. 检查 PID 文件是否存在
        $pidFile = self::getPidFile();
        if (!file_exists($pidFile) || !is_readable($pidFile)) {
            return false;
        }

        $pid = (int)@file_get_contents($pidFile);
        if ($pid > 0) {
            // SIGUSR1 (10) 是 Swoole 约定的热重载所有 Worker 的信号
            $signal = \defined('SIGUSR1') ? SIGUSR1 : 10;
            // 尝试发送信号。如果 FPM 用户无权操作 Swoole 进程，这里会返回 false
            return @posix_kill($pid, $signal);
        }

        return false;
    }

    /**
     * 获取当前是否处于 Swoole 环境 (包含协程与非协程入口)
     */
    public static function isSwoole(): bool
    {
        return \extension_loaded('swoole') && \defined('SWOOLE_VERSION');
    }

    /**
     * 获取当前是否处于协程内
     */
    public static function inCoroutine(): bool
    {
        if (!self::isSwoole()) {
            return false;
        }
        $coroClass = "\\Swoole\\Coroutine";
        if (class_exists($coroClass)) {
            // getCid > -1 表示处于协程环境
            return (int)call_user_func([$coroClass, 'getCid']) > -1;
        }
        return false;
    }
}