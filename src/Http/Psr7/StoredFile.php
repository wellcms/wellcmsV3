<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/
/* -------------------------------------------------------------------------
 * StoredFile：将已落盘文件以只读形式暴露给后续业务，而不暴露真实磁盘路径
 * --------------------------------------------------------------------- */

namespace Framework\Http\Psr7;

use Framework\Exception\Http\UploadSecurityException;

/**
 * 为已存储的本地文件提供统一的 UploadedFileInterface 接口
 * 简化本地文件的管理和操作
 */
class StoredFile implements \Framework\Http\Interfaces\UploadedFileInterface
{
    /** @var string */
    private $path;
    /** @var string */
    private $clientFilename;
    /** @var string */
    private $clientMediaType;
    /** @var int */
    private $size;

    public function __construct(string $path, string $name, string $mediaType, int $size)
    {
        $this->path = $path;
        $this->clientFilename = $name;
        $this->clientMediaType = $mediaType;
        $this->size = $size;
    }

    /* ------------------ UploadedFileInterface --------------------------*/
    public function getStream(): \Framework\Http\Interfaces\StreamInterface
    {
        return \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStreamFromFile($this->path, 'r');
    }

    /**
     * 更稳健的移动实现：
     * 1) 确保目标目录存在且可写（0755）
     * 2) 优先尝试 rename（原子移动、同设备最快）
     * 3) rename 失败时，回退到 copy + unlink（跨设备/已存在等情况）
     */
    public function moveTo(string $targetPath): void
    {
        // [修改] 基础校验
        if (!is_string($targetPath) || $targetPath === '') {
            throw new UploadSecurityException('Invalid target path');
        }
        if (!file_exists($this->path)) {
            throw new UploadSecurityException('Source file does not exist');
        }

        $dir = dirname($targetPath);

        // [修改] 确保目录存在，权限使用 0755 与 UploadedFile::moveTo 一致，避免 0777 过宽
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new UploadSecurityException("Unable to create target directory: {$dir}");
            }
        }
        if (!is_writable($dir)) {
            throw new UploadSecurityException("Target directory is not writable: {$dir}");
        }

        // [修改] 优先尝试原子 rename（同设备场景最优）
        if (@rename($this->path, $targetPath)) {
            $this->path = $targetPath;
            return;
        }

        // [修改] 回退策略：copy + unlink（跨分区/已有锁等导致 rename 失败时）
        if (@copy($this->path, $targetPath)) {
            // 复制成功后尽量清理源文件；失败也不应影响新文件可用性，但这里严格要求清理成功
            if (!@unlink($this->path)) {
                // 如果无法删除源文件，回滚以避免文件重复（根据业务偏好，也可仅记录警告日志）
                @unlink($targetPath);
                throw new UploadSecurityException("Failed to remove source after copy: {$this->path}");
            }
            $this->path = $targetPath;
            return;
        }

        // [修改] 两种路径都失败，抛出统一异常
        throw new UploadSecurityException("Failed to move stored file to {$targetPath}");
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return UPLOAD_ERR_OK;
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
