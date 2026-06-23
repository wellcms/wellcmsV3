<?php
declare(strict_types=1);

namespace App\Utils;

class PathHelper
{
    /**
     * 将绝对路径转换为相对于 APP_PATH 的路径。
     * 若路径是 Compile 生成的缓存路径（storage/tmp/classes/ 或 storage/tmp/views/），
     * 先还原为原始源码路径，再相对化。
     * 若路径不在 APP_PATH 下，则原样返回。
     */
    public static function relative(string $absolutePath): string
    {
        if (!defined('APP_PATH') || APP_PATH === '') {
            return $absolutePath;
        }

        $base = rtrim(str_replace('\\', '/', APP_PATH), '/');
        $path = str_replace('\\', '/', $absolutePath);

        // 将编译缓存路径还原为源码路径
        $path = self::normalizeCompiledPath($path, $base);

        if (stripos($path, $base . '/') === 0) {
            return substr($path, strlen($base) + 1);
        }

        return $absolutePath;
    }

    /**
     * 将 Throwable 的 trace 数组中所有 file 字段相对化。
     * 注意：不修改原始 trace，返回新数组。
     */
    public static function relativeTrace(array $trace): array
    {
        $result = [];
        foreach ($trace as $frame) {
            if (isset($frame['file'])) {
                $frame['file'] = self::relative($frame['file']);
            }
            $result[] = $frame;
        }
        return $result;
    }

    /**
     * 将 Throwable::getTraceAsString() 输出中的 APP_PATH 前缀去除，
     * 同时将 Compile 缓存路径还原为原始源码路径。
     */
    public static function relativeTraceAsString(\Throwable $e): string
    {
        $appPath = defined('APP_PATH') ? rtrim(str_replace('\\', '/', APP_PATH), '/') : '';
        if ($appPath === '') {
            return $e->getTraceAsString();
        }
        $trace = str_replace('\\', '/', $e->getTraceAsString());
        $trace = self::normalizeCompiledPath($trace, $appPath);
        return str_ireplace($appPath . '/', '', $trace);
    }

    /**
     * 将 Compile 生成的缓存路径还原为原始源码路径。
     * 支持 storage/tmp/classes/ 与 storage/tmp/views/。
     */
    private static function normalizeCompiledPath(string $path, string $base): string
    {
        $replacements = [
            $base . '/storage/tmp/classes/' => $base . '/',
            $base . '/storage/tmp/views/'   => $base . '/',
        ];
        foreach ($replacements as $search => $replace) {
            $path = str_ireplace($search, $replace, $path);
        }
        return $path;
    }
}
