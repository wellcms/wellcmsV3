<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

class StorageManager
{
    /** @var array 存储配置 */
    protected $config;
    /** @var array 应用配置 */
    protected $appConfig;

    public function __construct(array $uploadConfig, array $appConfig)
    {
        $this->config = $uploadConfig;
        $this->appConfig = $appConfig;
    }

    /**
     * 获取视图路径 (Assets/Themes)
     * @return string
     */
    public function getViewPath(): string
    {
        $urlRewriteOn = $this->appConfig['url_rewrite_on'] ?? 0;
        $path = $this->appConfig['path'] ?? './';
        $viewUrl = $this->appConfig['view_url'] ?? '/views/';

        // 判断是否开启了 URL 重写功能
        return $urlRewriteOn > 1 ? $path . trim($viewUrl, './') . '/' : $viewUrl;
    }

    /**
     * 加锁驱动实例 (便捷方法)
     */
    public function disk(?string $name = null): \App\Services\Storage\Interfaces\StorageInterface
    {
        $name = strtolower($name ?: $this->config['default'] ?? 'local');

        $cfg = $this->config['disks'][$name] ?? [];

        // hook app_Http_Storage_StorageManager_disk_start.php

        switch ($name) {
            case 'local':
                return new \App\Services\Storage\Drivers\LocalStorage($cfg);
                /* case 'aws':
                return new \App\Services\Storage\Drivers\S3Storage($cfg); */
                // hook app_Http_Storage_StorageManager_disk_before.php
            default:
                return new \App\Services\Storage\Drivers\LocalStorage($cfg);
        }

        // hook app_Http_Storage_StorageManager_disk_end.php
    }

    /**
     * 路由并生成头像预览 URL (无状态、高性能)
     * @param int $timestamp 头像上传时间戳 (0 = 默认头像)
     * @param int $status 存储状态 (0: 本地 / 1: 云端)
     * @return string
     */
    public function getAvatarUrl(int $timestamp, int $status): string
    {
        if (0 === $timestamp) return '/views/image/avatar.png';

        $localUrl = $this->config['disks']['local']['url'] ?? '/upload/';
        $ym = date('Ym', $timestamp);
        $path = 'avatar/' . $ym . '/' . $timestamp . '.png';

        // 云端状态且当前开启了云存储驱动
        if (1 === $status && ($this->config['default'] ?? 'local') !== 'local') {
            $cloudBaseUrl = $this->config['disks'][$this->config['default']]['url'] ?? '';
            return rtrim($cloudBaseUrl, '/') . '/' . ltrim($localUrl, '/') . $path;
        }

        // 默认本地
        return $localUrl . $path;
    }

    /**
     * 获取头像本地物理全路径
     * @param int $timestamp
     * @return string
     */
    public function getAvatarPath(int $timestamp): string
    {
        if (0 === $timestamp) return '';

        $localRoot = $this->config['disks']['local']['root'] ?? '';
        $ym = date('Ym', $timestamp);

        return $localRoot . 'avatar' . DIRECTORY_SEPARATOR . $ym . DIRECTORY_SEPARATOR . $timestamp . '.png';
    }

    /**
     * 删除物理文件 (支持本地和同步删除云端)
     * @param string $relativeKey 相对路径 (相对于 upload 根目录)
     * @param int $status 存储状态 (0: 本地 / 1: 云端)
     * @return bool
     */
    public function deleteFile(string $relativeKey, int $status): bool
    {
        if (empty($relativeKey)) return false;

        // 1. 统一路径标准化：提取相对于 upload 根目录的路径
        // 去除可能的开头斜杠
        $cleanPath = ltrim($relativeKey, '/\\');

        // 如果是以 storage/upload/ 开头 (数据库 path 字段常见格式)
        if (strpos($cleanPath, 'storage' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR) === 0) {
            $cleanPath = substr($cleanPath, strlen('storage' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR));
        } elseif (strpos($cleanPath, 'storage/upload/') === 0) {
            $cleanPath = substr($cleanPath, strlen('storage/upload/'));
        }

        // 如果是以 upload/ 开头 (数据库 url 字段常见格式)
        $localUrl = ltrim($this->config['disks']['local']['url'] ?? 'upload/', '/\\');
        if (strpos($cleanPath, $localUrl) === 0) {
            $cleanPath = substr($cleanPath, strlen($localUrl));
        }

        // 确保 cleanPath 不带前导斜杠
        $cleanPath = ltrim($cleanPath, '/\\');

        $result = true;

        // 2. 如果在云端 (status=1)，调用默认云驱动删除
        if (1 === $status && ($this->config['default'] ?? 'local') !== 'local') {
            try {
                // 云端 Key 通常需要包含 upload/ 前缀以匹配 UploadToCloudJob 的逻辑
                $cloudKey = ltrim($localUrl, '/\\') . $cleanPath;
                $result = $this->disk($this->config['default'])->delete($cloudKey);
            } catch (\Exception $e) {
                $result = false;
            }
        }

        // 3. 始终尝试从本地清理
        try {
            // 本地磁盘驱动的 root 通常已设为 storage/upload/，所以传 cleanPath 即可
            $localResult = $this->disk('local')->delete($cleanPath);
            $result = $result && $localResult;
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * 删除头像
     */
    public function deleteAvatar(int $timestamp, int $status): bool
    {
        if (0 === $timestamp) return true;
        $ym = date('Ym', $timestamp);
        $relativeKey = 'avatar/' . $ym . '/' . $timestamp . '.png';
        return $this->deleteFile($relativeKey, $status);
    }

    /**
     * 删除合集封面
     */
    public function deleteCollectionCover(string $relativeKey, int $status): bool
    {
        return $this->deleteFile($relativeKey, $status);
    }

    /**
     * 动态代理方法调用到默认驱动 (可选，语法糖)
     * 允许直接调用 $manager->writeStream() 而不用 $manager->disk()->writeStream()
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        return $this->disk()->$method(...$parameters);
    }
}
