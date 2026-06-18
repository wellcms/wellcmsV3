<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Drivers;

class LocalStorage implements \App\Services\Storage\Interfaces\StorageInterface
{
    /** @var string */
    private $root;

    public function __construct(array $config)
    {
        // 确保存储根目录存在
        $this->root = rtrim($config['root'] ?? '', '/\\');
    }

    /**
     * @param string $path
     */
    private function applyPathPrefix($path): string
    {
        if ($this->root === '') return $path;

        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $root = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $this->root), DIRECTORY_SEPARATOR);

        // A. 如果路径已经是系统绝对路径（如 /home/...）且它已经包含了 root，说明已经适配好。
        if (strpos($path, $root . DIRECTORY_SEPARATOR) === 0 || $path === $root) {
            return $path;
        }

        // B. 关键点：检查路径是否是属于本项目的绝对路径
        $appRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (defined('APP_PATH') ? APP_PATH : '')), DIRECTORY_SEPARATOR);
        if ($appRoot !== '' && strpos($path, $appRoot . DIRECTORY_SEPARATOR) === 0) {
            // 这是一条项目内的绝对路径。即使它没在当前 root 下，我们也不应该粗暴地给它加个 root 前缀。
            // 例如：root 是 /.../storage/upload，而 path 是 /.../storage/tmp
            return $path;
        }

        // C. 只有真正的相对路径才进行拼接
        return $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '\\');
    }

    private function ensureDirectory(string $path): void{
        $dir = dirname($path);
        if (!is_dir($dir)) {
            // 递归创建目录 0755
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Directory creation failed: " . $dir);
            }
        }
    }

    public function writeStream(string $path, $resource): bool
    {
        $fullPath = $this->applyPathPrefix($path);
        $this->ensureDirectory($fullPath);

        $dest = fopen($fullPath, 'w+b');
        if ($dest === false) return false;

        // 流对流复制，内存高效
        $copied = stream_copy_to_stream($resource, $dest);
        fclose($dest);

        return $copied !== false;
    }

    /**
     * @return array
     */
    public function readStream(string $path)
    {
        $fullPath = $this->applyPathPrefix($path);
        if (!file_exists($fullPath)) return false;
        return fopen($fullPath, 'rb');
    }

    /**
     * @param null $hashCtx
     */
    public function appendStream(string $path, $resource, &$hashCtx = null): bool
    {
        $fullPath = $this->applyPathPrefix($path);
        $this->ensureDirectory($fullPath);

        // 使用 'ab' 模式追加
        $dest = fopen($fullPath, 'ab');
        if ($dest === false) return false;

        // 如果不需要计算 Hash，使用高性能的 stream_copy_to_stream
        if ($hashCtx === null) {
            $result = stream_copy_to_stream($resource, $dest);
            fclose($dest);
            return $result !== false;
        }

        // 如果需要计算 Hash，使用循环读写 (你的方案)
        $bufferSize = 2 * 1024 * 1024; // 2MB Buffer
        $success = true;

        while (!feof($resource)) {
            $buf = fread($resource, $bufferSize);
            if ($buf === false) {
                $success = false;
                break;
            }
            if ($buf === '') break;

            // 1. 写入文件
            if (fwrite($dest, $buf) === false) {
                $success = false;
                break;
            }

            // 2. 更新 Hash
            hash_update($hashCtx, $buf);
        }

        fclose($dest);
        return $success;
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->applyPathPrefix($path);
        if (!file_exists($fullPath)) return true;
        return @unlink($fullPath);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->applyPathPrefix($path));
    }

    public function size(string $path): int
    {
        $fullPath = $this->applyPathPrefix($path);
        return file_exists($fullPath) ? (int)filesize($fullPath) : 0;
    }

    public function mimeType(string $path): string
    {
        $fullPath = $this->applyPathPrefix($path);
        if (!file_exists($fullPath)) return '';

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            return $finfo->file($fullPath) ?: 'application/octet-stream';
        }

        if (function_exists('mime_content_type')) {
            return mime_content_type($fullPath) ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    public function deleteDir(string $path): bool
    {
        $fullPath = $this->applyPathPrefix($path);
        if (!is_dir($fullPath)) return true;

        // 简单的递归删除
        $files = array_diff(scandir($fullPath), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$fullPath/$file")) ? $this->deleteDir("$path/$file") : unlink("$fullPath/$file");
        }
        return @rmdir($fullPath);
    }
}
