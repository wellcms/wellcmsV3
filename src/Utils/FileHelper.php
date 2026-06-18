<?php

declare(strict_types=1);
/**
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

use Framework\Exception\Infra\PoolException;

/**
 * 文件处理相关
 */
class FileHelper
{
    private const LOCK_PREFIX = 'lockfile_';
    private const BACKUP_SUFFIX = '.backup.';

    private static function getLockFilePath(string $key): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::LOCK_PREFIX . md5($key);
    }

    /**
     * 基于 $key 创建独占文件锁（原子级逻辑锁）
     */
    public static function lock(string $key, int $expire = 30): bool
    {
        $filePath = self::getLockFilePath($key);
        // 使用 @ 忽略警告，通过返回值判断
        $fileHandle = @fopen($filePath, 'c');

        if (!is_resource($fileHandle)) {
            return false;
        }

        // 尝试获取文件锁
        if (flock($fileHandle, LOCK_EX | LOCK_NB)) {
            // 设置过期时间
            self::expireAt($filePath, $expire);
            // 注意：逻辑锁在持久化场景下通常依赖文件存在。
            // 这里我们保持句柄打开或通过文件时间戳管理。
            // 为了作为静态工具类的“状态占坑”，我们关闭句柄但保留文件。
            flock($fileHandle, LOCK_UN);
            fclose($fileHandle);
            return true;
        }

        fclose($fileHandle);
        return false;
    }

    // 释放与 $key 关联的锁文件，删除锁文件。删除成功返回 true，文件不存在或失败返回 false。
    public static function unlock(string $key): bool
    {
        $filePath = self::getLockFilePath($key);
        if (!file_exists($filePath)) {
            return false;
        }

        $fileHandle = fopen($filePath, 'w');
        if (is_resource($fileHandle)) {
            flock($fileHandle, LOCK_UN);
            fclose($fileHandle);
        }

        return unlink($filePath);
    }

    // 检查锁是否有效。若锁文件存在但过期，自动释放锁。锁有效返回 true，否则 false。
    public static function isLocked(string $key): bool
    {
        $filePath = self::getLockFilePath($key);
        if (!file_exists($filePath)) {
            return false;
        }

        $expiredFilePath = $filePath . '.expired';

        $expireTime = self::getExpireTime($filePath);
        if (time() > $expireTime) {
            // 原子操作：将锁文件重命名为过期文件（避免并发删除冲突）
            if (rename($filePath, $expiredFilePath)) {
                // 仅删除已标记为过期的文件
                self::unlock($expiredFilePath);
                return false;
            }
            return false;
        }

        return true;
    }

    // 设置过期时间
    private static function expireAt(string $filePath, int $expire): void
    {
        touch($filePath, time() + $expire);
    }

    // 过期时间
    private static function getExpireTime(string $filePath): int
    {
        return (int)filemtime($filePath);
    }

    /**
     * 通过临时锁文件执行回调函数，支持超时重试机制
     */
    public static function fileLock(callable $function, int $timeout = 10): void
    {
        $attempt = 0;
        $lockFile = tempnam(sys_get_temp_dir(), 'lockfile_');
        if ($lockFile === false) {
            throw new PoolException('Failed to create temporary lock file');
        }

        while ($attempt < $timeout) {
            $fp = @fopen($lockFile, 'w');
            if ($fp !== false && flock($fp, LOCK_EX | LOCK_NB)) {
                try {
                    $function();
                } finally {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    @unlink($lockFile);
                }
                return;
            } else {
                if ($fp !== false) fclose($fp);
                $attempt++;
                if ($attempt < $timeout) {
                    if (\Framework\Utils\Runtime::inCoroutine()) {
                        \Swoole\Coroutine\System::sleep(0.1);
                    } else {
                        usleep(100000); // 100ms
                    }
                }
            }
        }

        throw new PoolException("Failed to acquire file lock after {$timeout} attempts");
    }

    /**
     * 动态替换文件中的变量（支持 PHP 数组和 JSON/JS 对象）
     */
    public static function fileReplaceVar(string $filePath, array $replace = [], bool $pretty = false)
    {
        $ext = self::fileExt($filePath);
        if ('php' === $ext) {
            $arr = include $filePath;
            if (!is_array($arr)) $arr = [];
            $arr = array_merge($arr, $replace);

            $content = "<?php\nreturn " . var_export($arr, true) . ";\n";

            self::fileBackup($filePath);
            $written = self::filePutContentsTry($filePath, $content);
            if ($written !== strlen($content)) {
                self::fileBackupRestore($filePath);
                return false;
            }
            self::fileBackupUnlink($filePath);
            return $written;
        } elseif ('js' === $ext || 'json' === $ext) {
            $s = self::fileGetContentsTry($filePath);
            if ($s === false) return false;

            $arr = json_decode($s, true);
            if (!is_array($arr)) return false;

            $arr = array_merge($arr, $replace);
            $json = json_encode($arr, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE);

            self::fileBackup($filePath);
            $written = self::filePutContentsTry($filePath, $json);
            if ($written !== strlen($json)) {
                self::fileBackupRestore($filePath);
                return false;
            }
            self::fileBackupUnlink($filePath);
            return $written;
        }
        return false;
    }

    public static function fileBackname(string $FilePath)
    {
        $FilePre = self::fileName($FilePath);
        $FileExt = self::fileExt($FilePath);
        $s = $FilePre . self::BACKUP_SUFFIX . $FileExt;
        return $s;
    }

    /**
     * @return bool
     */
    public static function isBackfile(string $FilePath)
    {
        return false !== strpos($FilePath, self::BACKUP_SUFFIX);
    }

    // 创建文件的备份副本（文件名追加 .backup），确保备份文件与原文件大小一致。备份成功返回 true，备份已存在或失败返回 false。
    public static function fileBackup(string $FilePath)
    {
        $backFile = self::fileBackname($FilePath);
        if (is_file($backFile)) return true; // 备份已经存在
        $r = self::copy($FilePath, $backFile);
        clearstatcache();
        return $r && filesize($backFile) == filesize($FilePath);
    }

    // 用备份文件还原原文件，成功后删除备份。还原成功返回 true，否则 false。
    public static function fileBackupRestore(string $FilePath)
    {
        $backFile = self::fileBackname($FilePath);
        $r = self::copy($backFile, $FilePath);
        clearstatcache();
        $r && filesize($backFile) == filesize($FilePath) && self::unlink($backFile);
        return $r;
    }

    // 删除指定文件的备份。删除成功返回 true，否则 false。
    public static function fileBackupUnlink(string $FilePath)
    {
        $backFile = self::fileBackname($FilePath);
        $r = self::unlink($backFile);
        return $r;
    }

    /**
     * 多次尝试读取文件内容
     */
    public static function fileGetContentsTry(string $file, int $times = 3)
    {
        while ($times-- > 0) {
            $fp = @fopen($file, 'rb');
            if ($fp) {
                // 加共享锁读
                flock($fp, LOCK_SH);
                $size = (int)filesize($file);
                if ($size === 0) {
                    fclose($fp);
                    return '';
                }
                $content = fread($fp, $size);
                flock($fp, LOCK_UN);
                fclose($fp);
                return $content;
            }
            if (\Framework\Utils\Runtime::inCoroutine()) {
                \Swoole\Coroutine\System::sleep(0.2);
            } else {
                usleep(200000); // 200ms
            }
        }
        return false;
    }

    /**
     * 多次尝试写入文件
     */
    public static function filePutContentsTry(string $file, string $content, int $times = 3)
    {
        while ($times-- > 0) {
            $fp = @fopen($file, 'wb');
            if ($fp && flock($fp, LOCK_EX)) {
                $written = fwrite($fp, $content);
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
                clearstatcache(true, $file);
                return $written;
            }
            if ($fp) fclose($fp);
            if (\Framework\Utils\Runtime::inCoroutine()) {
                \Swoole\Coroutine\System::sleep(0.2);
            } else {
                usleep(200000); // 200ms
            }
        }
        return false;
    }

    // 安全获取文件扩展名（小写、截断、合法性校验）。非法扩展名返回 attach。使用 pathinfo + URL编码截断 + 正则校验。
    /**
     * @param string $Filename
     */
    public static function fileExt($Filename, int $max = 16)
    {
        // 获取文件扩展名并转换为小写
        $ext = strtolower(pathinfo($Filename, PATHINFO_EXTENSION));

        // 如果扩展名为空，直接返回默认值
        if (empty($ext)) {
            return 'attach';
        }

        // 如果编码后长度超限，直接截断
        $encodedExt = SecurityHelper::urlencode($ext);
        $encodedExt = substr($encodedExt, 0, $max);

        // 校验合法性，不合法则返回默认值
        return preg_match('#^\w+$#', $encodedExt) ? $encodedExt : 'attach';
    }

    // 获取无扩展名的文件名（基于 pathinfo）。
    /**
     * @param string $path
     */
    public static function fileName($path)
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    // 封装 copy 函数，检查源文件是否存在
    /**
     * @return string
     */
    public static function copy(string $src, string $dest = '')
    {
        return is_file($src) ? \copy($src, $dest) : false;
    }

    // 封装 unlink 函数，检查文件是否存在
    public static function unlink(string $File)
    {
        $r = is_file($File) ? \unlink($File) : false;
        return $r;
    }

    // 获取文件的修改时间，文件不存在返回 0
    public static function fileMtime(string $File)
    {
        return is_file($File) ? \filemtime($File) : 0;
    }
}
