<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 异步图片清洗任务
 * 职责：读取文件 -> 清洗EXIF/重绘 -> 会导致文件尺寸和hash改为必须更新Hash和大小 -> 标记已清洗
 */
class ImageCleanupJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var object */
    protected $attachmentService;
    /** @var object */
    protected $fileStorageService;
    /** @var \Framework\Logger\LoggerInterface */
    protected $logger;

    // TaskExecutor 会通过 Container 自动注入这些依赖
    public function __construct(
        \App\Services\Storage\FileStorageService $fileStorageService,
        \Framework\Logger\LoggerInterface $logger
    ) {
        $this->fileStorageService = $fileStorageService;
        $this->logger = $logger;
    }

    /**
     * @param int $fileStorageId
     * @param string $path
     * @return array
     */
    public function handle(int $fileStorageId, string $path): array
    {
        if (!$fileStorageId || !$path) {
            throw new \InvalidArgumentException("Missing fileStorageId");
        }

        // 1. 获取附件信息
        $file = $this->fileStorageService->read(['id' => $fileStorageId]);
        if (!$file) {
            $this->logger->warning("ImageCleanupJob: File not found in DB", ['file_storage_id' => $fileStorageId]);
            return ['status' => 'skipped', 'reason' => 'db_not_found'];
        }

        $path = $file['path'];
        if (!file_exists($path)) {
            $this->logger->error("ImageCleanupJob: File not found on disk", ['path' => $path]);
            return ['status' => 'failed', 'reason' => 'file_missing'];
        }

        // 2. 执行清洗
        $mime = $file['mime'] ?? mime_content_type($path);

        // 性能熔断：大于 10MB 的图片暂不处理，避免 CLI 内存溢出
        if (filesize($path) > 10 * 1024 * 1024) {
            return ['status' => 'skipped', 'reason' => 'file_too_large'];
        }

        $cleaned = $this->cleanImageMetadata($path, $mime);

        if ($cleaned) {
            // 3. 更新文件元数据 (Hash 和 Size 可能变化)
            clearstatcache(true, $path);
            $newSize = filesize($path);
            $newHashRaw = \Framework\Utils\FileHasher::sha256($path, 'raw');

            // 更新数据库
            $updateData = [
                'filehash' => $newHashRaw,
                'filesize' => $newSize,
                'newhash' => $newHashRaw,
                'newsize' => $newSize,
                'exif_cleaned' => 1, // 已清理EXIF
            ];

            $this->fileStorageService->update($fileStorageId, $updateData);

            //$this->logger->info("ImageCleanupJob: Cleaned success", ['file_storage_id' => $fileStorageId, 'old_size' => $file['filesize'], 'new_size' => $newSize]);
            return ['status' => 'success'];
        } else {
            // 清洗失败或无需清洗（非图片）
            return ['status' => 'skipped', 'reason' => 'not_supported_or_failed'];
        }
    }

    /**
     * 核心清洗逻辑
     */
    private function cleanImageMetadata(string $path, string $mime): bool
    {
        if (!extension_loaded('gd')) return false;
        @ini_set('memory_limit', '256M'); // 临时提权

        try {
            $srcImage = null;
            switch ($mime) {
                case 'image/jpeg':
                    $srcImage = @imagecreatefromjpeg($path);
                    break;
                case 'image/png':
                    $srcImage = @imagecreatefrompng($path);
                    imagealphablending($srcImage, false);
                    imagesavealpha($srcImage, true);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $srcImage = @imagecreatefromwebp($path);
                        imagealphablending($srcImage, false);
                        imagesavealpha($srcImage, true);
                    }
                    break;
                case 'image/gif':
                    $srcImage = @imagecreatefromgif($path);
                    break;
            }

            if (!$srcImage) return false;

            $result = false;
            switch ($mime) {
                case 'image/jpeg':
                    $result = @imagejpeg($srcImage, $path, 85);
                    break;
                case 'image/png':
                    $result = @imagepng($srcImage, $path, 8);
                    break;
                case 'image/webp':
                    if (function_exists('imagewebp')) $result = @imagewebp($srcImage, $path, 85);
                    break;
                case 'image/gif':
                    $result = @imagegif($srcImage, $path);
                    break;
            }

            if (PHP_VERSION_ID < 80000) imagedestroy($srcImage);
            else unset($srcImage);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("ImageCleanupJob GD Error", ['msg' => $e->getMessage()]);
            return false;
        }
    }
}

// UploadController
// 关键：触发异步清洗任务，应该判断是否启用redis和Scheduler，如果未启用则跳过清洗
/* $redis = $this->cache->original('redis');
if ($redis && $isImage && !empty($this->cfg['clean_exif'])) {
    // Temp 阶段不清洗
    // 获取 TaskManage 实例（包含是否启用检查）
    $scheduler = $this->getTaskManage();
    if ($scheduler) {
        // 推送异步清洗任务
        try {
            $scheduler->createTask([
                'className' => 'App\\Jobs\\ImageCleanupJob',
                'methodName' => 'handle',
                'args' => [
                    'id' => $fileStorageId,
                    'path' => $finalPath
                ],
                'priority' => 5,
                'retryDelay' => 10,
                'maxRetries' => 3
            ]);
        } catch (\Throwable $e) {
            // 容错：推送任务失败不应阻断上传流程，改为降级处理或仅记录日志
            $this->container->get(LoggerInterface::class)->error('Failed to push cleanup task', ['error' => $e->getMessage()]);
        }
    }
} */
