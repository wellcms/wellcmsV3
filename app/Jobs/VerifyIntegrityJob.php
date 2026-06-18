<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 对于超大文件，提交后触发异步任务验证hash和size
 */
class VerifyIntegrityJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Services\Storage\FileStorageService */
    protected $fileStorageService;
    /** @var \Framework\Logger\LoggerInterface */
    protected $logger;
    /** @var \App\Services\Storage\StorageManager */
    protected $storageManager;

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

    public function handle(int $fileStorageId): array
    {
        if (!$fileStorageId) {
            throw new \InvalidArgumentException("Missing File StorageId ID");
        }

        // 获取附件信息
        $file = $this->fileStorageService->read(['id' => $fileStorageId]);
        if (empty($file) || $file['cloud_type'] > 0) {
            $this->logger->warning("VerifyIntegrityJob: File not found in DB", ['file_storage_id' => $fileStorageId]);
            return ['status' => 'skipped', 'reason' => 'db_not_found'];
        }

        // 如果已经是绝对路径则直接使用，否则拼接 APP_PATH
        $filePath = (strpos($file['path'], APP_PATH) === 0) ? $file['path'] : APP_PATH . $file['path'];

        if (!file_exists($filePath)) {
            $this->logger->error("VerifyIntegrityJob: File not found on disk", ['path' => $filePath]);
            return ['status' => 'failed', 'reason' => 'file_missing'];
        }

        try {
            // 获取本地文件流
            $filesize = (int)filesize($filePath);
            //$filehashrRaw = hash_file('sha256', $filePath, true); // 32位二进制符串
            $filehashrRaw = \Framework\Utils\FileHasher::sha256($filePath, 'raw'); // 32位二进制符串
            if ($filehashrRaw === false) return ['status' => 'failed', 'reason' => 'operation_failed'];

            $update = [];
            if ($filehashrRaw !== $file['filehash'] && $file['filesize'] && $filesize !== $file['filesize']) {
                $update['is_reviewed'] = 2;
                $update['review_note'] = 'The hash and size do not match; this is dangerous.';
            } else {
                $update['filesize'] = $filesize;
                $update['filehash'] = $filehashrRaw;
                // 可触发定时任务上传云储存
            }

            $update['newhash'] = $filehashrRaw;
            $update['newsize'] = $filesize;

            $this->fileStorageService->update($fileStorageId, $update);

            return ['status' => 'success', 'reason' => 'operation_successfully'];
        } catch (\Exception $e) {
            // 记录日志，等待重试
            $this->logger->error("VerifyIntegrityJob Error: " . $e->getMessage());
        }

        return ['status' => 'failed', 'reason' => 'operation_successfully'];
    }
}

/* $scheduler->createTask([
    'className' => 'App\\Jobs\\VerifyIntegrityJob',
    'methodName' => 'handle',
    'args' => [
        'file_storage_id' => $fileStorageId
    ],
    'priority' => 5,
    'retryDelay' => 10,
    'maxRetries' => 3
]); */
