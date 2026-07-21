<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Support;

/** -----------------------------
 * 抽象：文件系统工具
 * - 分片目录、最终目录
 * - URL 生成
 * - 安全检查/MIME
 * ----------------------------- */
class FileSystemHelper
{
    /** @var array */
    private $cfg;
    /** @var array */
    private $allowedExt;

    public function __construct(array $cfg, array $allowedExt = [])
    {
        $this->cfg = $cfg;
        // 预创建根目录与临时目录，确保环境可用
        $root = $this->normalizePath($cfg['disks']['local']['root'] ?? 'storage/upload/');
        $temp = $this->normalizePath($cfg['upload_temp'] ?? 'storage/tmp/');

        $this->ensureDir($root);
        $this->ensureDir($temp);
        $this->allowedExt = $allowedExt;
    }

    /**
     * @param string $path
     * @return string
     */
    public function normalizePath($path): string
    {
        if (empty($path)) return '';
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $appPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, APP_PATH), DIRECTORY_SEPARATOR);

        if ($path === $appPath) return $appPath . DIRECTORY_SEPARATOR;
        if (strpos($path, $appPath . DIRECTORY_SEPARATOR) === 0) {
            return $path;
        }
        return $appPath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '\\');
    }




    public function chunkDir(string $uploadId)
    {
        // 路径遍历防护 - 确保uploadId不包含路径分隔符
        if (strpos($uploadId, '/') !== false || strpos($uploadId, '\\') !== false || strpos($uploadId, '..') !== false) {
            throw new \InvalidArgumentException('上传ID包含非法路径字符');
        }

        $sanitized = (string)$this->sanitize($uploadId);
        // 双重验证
        if (strlen($sanitized) !== strlen($uploadId)) {
            throw new \InvalidArgumentException('上传ID包含非法字符');
        }

        $dir = rtrim($this->normalizePath($this->cfg['upload_temp']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ul_' . $sanitized . DIRECTORY_SEPARATOR;

        $this->ensureDir($dir);

        // 确保目录在tempDir范围内
        $realDir = realpath($dir);
        $realTemp = realpath($this->normalizePath($this->cfg['upload_temp']));

        if ($realDir !== false && $realTemp !== false) {
            if (strpos($realDir, $realTemp) !== 0) {
                throw new \RuntimeException('上传目录超出临时目录范围');
            }
        }

        return $dir;
    }

    /**
     * @param string $dir
     */
    public function ensureDir($dir): void{
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create directory:' . $dir);
            }
        }
    }

    public function dailyFinalDir()
    {
        $d = $this->normalizePath($this->cfg['disks']['local']['root']) . date($this->cfg['attach_dir_save_rule'] ?? 'Ym') . DIRECTORY_SEPARATOR;
        $this->ensureDir($d);
        return $d;
    }

    public function dailyFinalTmpDir(?string $namespace = null)
    {
        if ($namespace !== null && $namespace !== '') {
            $d = $this->normalizePath($this->cfg['upload_temp'])
                . $namespace . DIRECTORY_SEPARATOR
                . date($this->cfg['attach_dir_save_rule'] ?? 'Ym')
                . DIRECTORY_SEPARATOR;
        } else {
            $d = $this->normalizePath($this->cfg['upload_temp'])
                . date($this->cfg['attach_dir_save_rule'] ?? 'Ym')
                . DIRECTORY_SEPARATOR;
        }
        $this->ensureDir($d);
        return $d;
    }


    /**
     * 根据 uploadDir 根生成可访问 URL。
     * 1) 若配置了 baseUrl，则把 $path 相对化到 uploadDir 根再拼接到 baseUrl
     * 2) 若未配置 baseUrl，则返回文件系统路径（避免拼接出错误的绝对 URL）
     * @param string $path
     */
    public function fileUrl($path): array
    {
        // [修改] 使用 uploadDir 的绝对路径作为根，避免将绝对路径 ltrim 成假相对路径导致 URL 错误泄露
        // /www/wwwroot/wellcms/storage/upload
        $absUploadRoot = realpath($this->normalizePath($this->cfg['disks']['local']['root']));

        // /www/wwwroot/wellcms/storage/upload/tmp/READ.md
        $path = $this->normalizePath($path);
        $absPath = realpath($path);

        $basePath = $this->cfg['disks']['local']['root']; // /storage/upload/
        $baseUrl = $this->cfg['disks']['local']['url']; // /upload/

        if ($baseUrl && $absUploadRoot && $absPath) {
            $root = rtrim(str_replace('\\', '/', $absUploadRoot), '/');
            $abs  = str_replace('\\', '/', $absPath);

            if (strpos($abs, $root) === 0) {
                $rel = ltrim(substr($abs, strlen($root)), '/');
                $urlPath = str_replace('%2F', '/', rawurlencode($rel));
                // /storage/upload/tmp/READ.md and /upload/tmp/READ.md
                return [rtrim($basePath, '/') . '/' . $urlPath, rtrim($baseUrl, '/') . '/' . $urlPath];
            }

            // 不在上传根目录下，仅返回文件名，避免泄露绝对路径 /upload/tmp/READ.md
            return [rtrim($basePath, '/') . '/' . rawurlencode(basename($path)), rtrim($baseUrl, '/') . '/' . rawurlencode(basename($path))];
        }

        // 未配置 baseUrl：返回原始路径（由上层决定如何对外暴露）
        return [str_replace('\\', '/', (string)$path), str_replace('\\', '/', (string)$path)];
    }

    /**
     * @param string $path
     */
    public function detectMime($path)
    {
        $path = $this->normalizePath((string)$path);
        if (!file_exists($path)) return false;
        if (!function_exists('finfo_open')) return mime_content_type($path) ?: false;

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);
        return $mimeType ?: false;
    }

    public function blockDangerous(string $mime): bool{
        $m = mb_strtolower($mime);
        $dangerous = ['php', 'x-php', 'javascript', 'x-javascript', 'html', 'xhtml', 'xml'];
        foreach ($dangerous as $d) {
            if (strpos($m, $d) !== false) return true;
        }
        return false;
    }

    /**
     * @param string $path
     * @param null $expectedMime
     */
    public function validateMimeFromContent(string $path, ?string $expectedMime = null)
    {
        $realMime = $this->detectMime($path);
        if ($realMime === false) {
            throw new \RuntimeException('无法检测文件MIME类型');
        }

        // 阻止危险的MIME类型
        if ($this->blockDangerous($realMime)) {
            throw new \RuntimeException('不允许的文件类型');
        }

        // 如果提供了期望的MIME类型，验证是否匹配
        if ($expectedMime !== null && $realMime !== $expectedMime) {
            throw new \RuntimeException("MIME类型不匹配: 期望 $expectedMime, 实际 $realMime");
        }

        return $realMime;
    }

    /**
     * @param array $s
     */
    public function sanitize($s)
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $s);
    }

    /**
     * @param string $dir
     */
    public function cleanupDir($dir): void{
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir . $f);
        }
        @rmdir($dir);
    }

    /**
     * @param string $filename
     */
    public function safeFilename($filename)
    {
        // 路径遍历防护
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new \InvalidArgumentException('文件名包含非法路径字符');
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        /* $name = pathinfo($filename, PATHINFO_FILENAME);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$name);
        $safe = substr($safe, 0, 100);
        $timestamp = time();
        $extPart = $ext !== '' ? '.' . strtolower($ext) : '';
        return $safe . '_' . $timestamp . $extPart; */
        $extPart = $ext !== '' ? '.' . strtolower($ext) : '_';
        return uniqid() . $extPart;
    }

    /**
     * @param string $filename
     */
    public function allowedExt($filename, array $specificList = [])
    {
        $allowed = !empty($specificList) ? $specificList : (!empty($this->allowedExt) ? $this->allowedExt : (isset($this->cfg['allowed_ext']) ? $this->cfg['allowed_ext'] : ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'json', 'html', 'css', 'js']));
        $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    }

    public function isImage(string $path, array $allowedTypes = []): bool
    {
        $path = $this->normalizePath($path);
        // 1. 基础验证
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        // 2. 验证文件大小（防止超大文件攻击）
        $maxSize = 10 * 1024 * 1024; // 10MB
        if (filesize($path) > $maxSize) {
            return false;
        }

        // 3. 使用 finfo 检测真实的 MIME 类型
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($path);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($path);
        } else {
            return false; // 无法检测
        }

        // 4. 验证是否为图片类型
        if (strpos($mimeType, 'image/') !== 0) {
            return false;
        }

        // 5. 验证是否在允许的类型列表中（可选）
        if (!empty($allowedTypes)) {
            $mimeLower = strtolower($mimeType);
            $imageExt = str_replace('image/', '', $mimeLower);

            foreach ($allowedTypes as $key => $val) {
                // 如果是映射格式: 'image/jpeg' => ['jpg', 'jpeg'] 或 'image/gif' => ['gif']
                if (is_string($key) && strtolower($key) === $mimeLower) {
                    return true;
                }

                if (is_array($val)) {
                    // 检查扩展名是否在数组中
                    foreach ($val as $ext) {
                        if (is_string($ext) && strtolower($ext) === $imageExt) {
                            return true;
                        }
                    }
                } elseif (is_string($val)) {
                    // 平铺格式: ['jpeg', 'png'] 或单个字符串
                    $valLower = strtolower($val);
                    if ($valLower === $imageExt || $valLower === $mimeLower) {
                        return true;
                    }
                }
            }
            return false;
        }

        return true;
    }

    /**
     * 将文件从临时目录“转正”到正式存储目录
     *
     * @param string $tempPath 临时文件路径
     * @return array [newPath, newUrl]
     */
    public function promoteFile(string $tempPath): array
    {
        $tempPath = $this->normalizePath($tempPath);
        if (!file_exists($tempPath)) {
            throw new \RuntimeException('Temporary file does not exist: ' . $tempPath);
        }

        // 1. 生成正式目录 Ym 格式
        $finalDir = $this->dailyFinalDir();
        $filename = basename($tempPath);
        $newPath = $finalDir . $filename;

        // 2. 物理移动
        if (!rename($tempPath, $newPath)) {
            throw new \RuntimeException('Failed to move file from temp to formal storage (from ' . $tempPath . ' to ' . $newPath . ')');
        }

        // 3. 生成正式 URL
        return $this->fileUrl($newPath);
    }
}
