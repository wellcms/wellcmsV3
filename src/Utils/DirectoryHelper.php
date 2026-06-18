<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

class DirectoryHelper
{

    // 获取指定路径下的1级目录
    public static function getOneLevel(string $path): array
    {
        if (!is_dir($path)) return [];

        $items = scandir($path);
        if ($items === false) return [];

        $directories = array_filter($items, function ($item) use ($path) {
            return $item !== '.' && $item !== '..' && is_dir($path . DIRECTORY_SEPARATOR . $item);
        });

        return array_values($directories);
    }

    // 递归遍历目录
    //$files = DirectoryHelper::globRecursive("/path/to/directory/*.txt");
    //print_r($files);
    public static function globRecursive($pattern, int $flags = 0)
    {
        $Files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $Files = array_merge($Files, self::globRecursive($dir . '/' . basename($pattern), $flags));
        }
        return $Files;
    }

    // 创建目录
    public static function mkdir(string $dir, int $mod = 0755, bool $recusive = true): bool
    {
        return is_dir($dir) || \mkdir($dir, $mod, $recusive);
    }

    // 删除目录
    public static function rmdir(string $dir): bool
    {
        return is_dir($dir) && \rmdir($dir);
    }

    // DirectoryHelper::rmdirRecursive("/path/to/directory");
    // $deleteDir = true 删除当前目录，false 只删除当前目录里的子目录和文件
    public static function rmdirRecursive(string $path, bool $deleteDir = false): bool
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) return false;

        // 极度危险：禁止删除系统根目录或当前工作目录的顶层
        $protectedDirs = [DIRECTORY_SEPARATOR, realpath('./'), realpath('../')];
        if (in_array($realPath, $protectedDirs, true)) {
            return false;
        }

        // 确保路径以分隔符结尾
        $realPath = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                $itemPath = $fileInfo->getRealPath();
                if ($fileInfo->isDir()) {
                    if (!@rmdir($itemPath)) return false;
                } else {
                    if (!@unlink($itemPath)) return false;
                }
            }

            if ($deleteDir) {
                if (!@rmdir($realPath)) return false;
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /*
        根据 ID 设置目录结构 实例：
        $dirPath = DirectoryHelper::setDir(123, "/path/to/upload");
        echo "Directory path: $dirPath\n";
        000/000/1.jpg
        000/000/100.jpg
        000/000/100.jpg
        000/000/999.jpg
        000/001/1000.jpg
        000/001/001.jpg
        000/002/001.jpg
    */
    public static function setDir(int $id, string $dir = './'): string
    {
        $idStr = sprintf("%09d", $id);
        $s1 = substr($idStr, 0, 3);
        $s2 = substr($idStr, 3, 3);

        $relativeDir = $s1 . DIRECTORY_SEPARATOR . $s2;
        $fullPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;

        if (!is_dir($fullPath)) {
            \mkdir($fullPath, 0755, true);
        }

        return $s1 . '/' . $s2;
    }

    // 根据 ID 获取目录结构
    //$dirPath = DirectoryHelper::getDir(123); 
    //echo "Directory path: $dirPath\n"; // 取得路径：001/123
    public static function getDir(int $id): string
    {
        $idStr = sprintf('%09d', $id);
        $s1 = substr($idStr, 0, 3);
        $s2 = substr($idStr, 3, 3);
        return $s1 . '/' . $s2;
    }

    // 递归复制目录
    //DirectoryHelper::copyRecursive("/path/to/source_directory", "/path/to/destination_directory");
    public static function copyRecursive(string $src, string $dst): bool
    {
        if (!is_dir($src)) return false;

        $src = rtrim($src, DIRECTORY_SEPARATOR);
        $dst = rtrim($dst, DIRECTORY_SEPARATOR);

        if (!is_dir($dst)) {
            if (!\mkdir($dst, 0755, true)) return false;
        }

        $dir = opendir($src);
        if (!$dir) return false;

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') continue;

            $srcFile = $src . DIRECTORY_SEPARATOR . $file;
            $dstFile = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcFile)) {
                if (!self::copyRecursive($srcFile, $dstFile)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($srcFile, $dstFile)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        closedir($dir);
        return true;
    }

    // 检测文件是否可写，兼容 windows
    //$isWritable = DirectoryHelper::isWritable("/path/to/file.txt");
    //echo $isWritable ? "File is writable.\n" : "File is not writable.\n";
    public static function isWritable(string $file): bool
    {
        if (PHP_OS !== 'WINNT') {
            return is_writable($file);
        }

        if (is_file($file)) {
            $fp = @fopen($file, 'a+');
            if (!$fp) return false;
            fclose($fp);
            return true;
        } elseif (is_dir($file)) {
            $tmpfile = rtrim($file, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid() . '.tmp';
            if (@touch($tmpfile)) {
                @unlink($tmpfile);
                return true;
            }
            return false;
        }

        return false;
    }
}
