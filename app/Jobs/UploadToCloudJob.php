<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 异步上传云储存
 */
class UploadToCloudJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Services\Storage\FileStorageService */
    protected $fileStorageService;
    /** @var \Framework\Logger\LoggerInterface */
    protected $logger;
    /** @var \App\Services\Storage\StorageManager */
    protected $storageManager;

    /** @var int 最大重试次数 */
    private $maxRetries = 3;

    // TaskExecutor 会通过 Container 自动注入这些依赖
    public function __construct(
        \App\Services\Storage\FileStorageService $fileStorageService,
        \Framework\Logger\LoggerInterface $logger,
        \App\Services\Storage\StorageManager $StorageManager
    ) {
        $this->fileStorageService = $fileStorageService;
        $this->logger = $logger;
        $this->storageManager = $StorageManager;
    }

    public function handle(int $fileStorageId, array $cfg): array
    {
        if (!$fileStorageId || empty($cfg)) {
            throw new \InvalidArgumentException("Missing attachment ID");
        }

        // 获取附件信息
        $file = $this->fileStorageService->read(['id' => $fileStorageId]);
        if (empty($file) || $file['cloud_type'] > 0) {
            $this->logger->warning("UploadToCloudJob: File not found in DB", ['file_storage_id' => $fileStorageId]);
            return ['status' => 'skipped', 'reason' => 'db_not_found'];
        }

        // 如果已经是绝对路径则直接使用，否则拼接 APP_PATH
        $path = (strpos($file['path'], APP_PATH) === 0) ? $file['path'] : APP_PATH . $file['path'];

        if (!file_exists($path)) {
            $this->logger->error("UploadToCloudJob: File not found on disk", ['path' => $path]);
            return ['status' => 'failed', 'reason' => 'file_missing'];
        }

        // 获取本地文件流
        $localDisk = $this->storageManager->disk('local');
        if (!$localDisk->exists($path)) return ['status' => 'failed', 'reason' => 'file_does_not_exist']; // 异常：文件丢失

        $stream = $localDisk->readStream($path);

        if ($cfg['default'] === 'local' || empty($cfg['disks'][$cfg['default']])) return ['status' => 'failed', 'reason' => 'configuration_error'];

        $success = false;
        $lastError = '';

        try {
            // 上传到云端 (读取配置中的默认云驱动)
            $cloudDisk = $this->storageManager->disk($cfg['default']);

            // 计算云端存储路径 (Object Key)
            // upload/202601/READ.md
            $objectKey = ltrim($file['url'], '/');

            // 3次重试逻辑
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                try {
                    // 重试前必须重置流指针到开头！
                    // 否则第二次重试会从上次断开的地方开始读，导致文件损坏
                    if (ftell($stream) > 0) rewind($stream);

                    // 尝试上传
                    // writeStream 内部若抛出异常会被 catch 捕获
                    // 若返回 false 也会进入下一次循环
                    if ($cloudDisk->writeStream($objectKey, $stream)) {
                        $success = true;
                        break; // 上传成功，跳出循环
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    // 仅记录日志，准备下一次重试
                    // error_log("Upload attempt {$attempt} failed: " . $e->getMessage());
                }

                // 如果还没成功且没达到最大次数，等待1秒后重试 (简单的退避策略)
                if ($attempt < $this->maxRetries) {
                    sleep(1);
                }
            }

            if ($success) {

                // 更新数据库状态
                $this->fileStorageService->update($fileStorageId, [
                    'cloud_type' => 1,
                    'cloud_url' => $objectKey // 后端输出的时候在组装完整URL，此处只存相对路径，如：upload/202601/READ.md
                ]);

                if ((int)$cfg['local_file'] ?? 0) {
                    // 删除本地文件 (节省空间)
                    $localDisk->delete($path);
                }

                return ['status' => 'success', 'reason' => 'upload_successfully'];
            } else {
                // 3次全部失败
                $this->logger->error("Failed to upload file ID {$fileStorageId} to cloud after {$this->maxRetries} attempts. Last error: {$lastError}");
            }
        } catch (\Exception $e) {
            // 记录日志，等待重试
            $this->logger->error("UploadToCloudJob Error: " . $e->getMessage());
        } finally {
            if (is_resource($stream)) fclose($stream);
        }

        return ['status' => 'failed', 'reason' => 'upload_failed'];
    }
}

/* $scheduler->createTask([
    'className' => 'App\\Jobs\\UploadToCloudJob',
    'methodName' => 'handle',
    'args' => [
        'id' => $fileStorageId,
        'config' => $uploadConfig
    ],
    'priority' => 5,
    'retryDelay' => 10,
    'maxRetries' => 3
]); */
