<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 异步同步头像到云储存
 */
class SyncAvatarToCloudJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var object */
    protected $userService;
    /** @var \Framework\Logger\LoggerInterface */
    protected $logger;
    /** @var object */
    protected $storageManager;

    /** @var int 最大重试次数 */
    private $maxRetries = 3;

    public function __construct(
        \App\Services\Auth\UserService $userService,
        \Framework\Logger\LoggerInterface $logger,
        \App\Services\Storage\StorageManager $storageManager
    ) {
        $this->userService = $userService;
        $this->logger = $logger;
        $this->storageManager = $storageManager;
    }

    /**
     * @param int $userId 用户ID
     * @param string $localPath 本地全路径
     * @param string $cloudKey 云端存储路径 (e.g. avatar/202601/1705900000.png)
     * @param array $uploadConfig 上传配置
     */
    public function handle(int $userId, string $localPath, string $cloudKey, array $uploadConfig): array
    {
        if (!file_exists($localPath)) {
            $this->logger->error("SyncAvatarToCloudJob: Local file not found", ['path' => $localPath, 'user_id' => $userId]);
            return ['status' => 'failed', 'reason' => 'file_missing'];
        }

        // 获取本地文件流
        $localDisk = $this->storageManager->disk('local');
        // 上传到云端 (读取配置中的默认云驱动)
        $cloudDisk = $this->storageManager->disk($uploadConfig['default']);

        $stream = $localDisk->readStream($localPath);
        if (!is_resource($stream)) {
            return ['status' => 'failed', 'reason' => 'read_failed'];
        }

        $success = false;
        $lastError = '';

        try {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                try {
                    // 重试前必须重置流指针到开头！
                    // 否则第二次重试会从上次断开的地方开始读，导致文件损坏
                    if (ftell($stream) > 0) rewind($stream);

                    // 尝试上传
                    // writeStream 内部若抛出异常会被 catch 捕获
                    // 若返回 false 也会进入下一次循环
                    if ($cloudDisk->writeStream($cloudKey, $stream)) {
                        $success = true;
                        break; // 上传成功，跳出循环
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                }

                // 如果还没成功且没达到最大次数，等待1秒后重试 (简单的退避策略)
                if ($attempt < $this->maxRetries) sleep(1);
            }

            if ($success) {
                // 更新用户头像状态为云储存已同步 (avatar_status = 1)
                $this->userService->update($userId, ['avatar_status' => 1]);

                // 如果配置了删除本地文件
                if ((int)($uploadConfig['local_file'] ?? 0)) {
                    $localDisk->delete($localPath);
                }

                return ['status' => 'success', 'reason' => 'sync_successfully'];
            } else {
                $this->logger->error("SyncAvatarToCloudJob: Failed after {$this->maxRetries} attempts. Last error: {$lastError}");
            }
        } catch (\Exception $e) {
            $this->logger->error("SyncAvatarToCloudJob Exception: " . $e->getMessage());
        } finally {
            if (is_resource($stream)) fclose($stream);
        }

        return ['status' => 'failed', 'reason' => 'upload_failed'];
    }
}
