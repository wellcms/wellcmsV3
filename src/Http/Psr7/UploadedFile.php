<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

/**
 * 封装上传文件的流、大小、错误状态、客户端文件名等信息
 * 提供安全的文件移动方法 moveTo()
 * 支持流操作，避免直接文件操作的安全风险
 * 自动处理资源分离和关闭，防止文件锁定
 */
class UploadedFile implements \Framework\Http\Interfaces\UploadedFileInterface
{
    /** @var \Framework\Http\Interfaces\StreamInterface */
    protected $stream;
    /** @var int */
    protected $size;
    /** @var int */
    protected $error;
    /** @var string */
    protected $clientFilename;
    /** @var string */
    protected $clientMediaType;

    public function __construct(
        \Framework\Http\Interfaces\StreamInterface $stream,
        ?int $size,
        int $error,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        // 1. 校验 error 合法性
        if ($error < UPLOAD_ERR_OK || $error > UPLOAD_ERR_EXTENSION) {
            throw new \InvalidArgumentException("Invalid upload error code: {$error}");
        }

        $this->error = $error;

        // 2. 流与大小
        $this->stream = $stream;
        if ($size === null || $size <= 0) {
            $this->size = $stream->getSize();
        } else {
            $this->size = $size;
        }

        // 3. 客户端字段
        $this->clientFilename  = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    public function getStream(): \Framework\Http\Interfaces\StreamInterface
    {
        return $this->stream;
    }

    // $uploaded->moveTo(__DIR__ . '/uploads/avatar.png');
    public function moveTo(string $targetPath): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \Framework\Exception\Http\UploadSecurityException("Cannot move file due to upload error {$this->error}");
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (!is_writable($dir)) {
            throw new \Framework\Exception\Http\UploadSecurityException("Target directory is not writable: {$dir}");
        }

        // 1. 获取底层文件路径
        $uri = $this->stream->getMetadata('uri') ?? null;

        // 2. 若存在文件路径，则先分离/关闭资源句柄，再尝试重命名
        if (is_string($uri) && file_exists($uri)) {
            // 分离底层资源并关闭以解除锁定
            if (method_exists($this->stream, 'detach')) {
                $resource = $this->stream->detach();
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }

            // 尝试原子重命名
            if (@rename($uri, $targetPath)) {
                return;
            }

            // 如果重命名失败（如目标已存在或跨盘符），回退到 copy + unlink
            if (@copy($uri, $targetPath)) {
                @unlink($uri);
                return;
            }

            throw new \RuntimeException("Failed to move upload file from {$uri} to {$targetPath}");
        }

        // 3. 普通流复制分支（无底层 URI 或非文件流）
        // 分离底层资源
        $source = method_exists($this->stream, 'detach') ? $this->stream->detach() : null;
        if (!is_resource($source)) {
            throw new \RuntimeException('No valid stream resource to move');
        }

        $dest = @fopen($targetPath, 'wb');
        if ($dest === false) {
            throw new \RuntimeException("Unable to open target path: {$targetPath}");
        }

        if (stream_copy_to_stream($source, $dest) === false) {
            throw new \RuntimeException("Failed to copy upload stream to {$targetPath}");
        }

        fclose($dest);
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
