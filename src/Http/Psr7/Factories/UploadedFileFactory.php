<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7\Factories;

/**
 * Class UploadedFileFactory
 *
 * 实现 PSR‑17 UploadedFileFactoryInterface，创建 UploadedFile 对象，
 * 支持多类型输入（路径/资源/流）及协程实例缓存。
 * 从不同来源创建标准化的 UploadedFile 对象
 * 支持文件路径、资源句柄、StreamInterface 等多种输入
 * 协程环境下的单例管理
 */
class UploadedFileFactory implements \Framework\Http\Interfaces\UploadedFileFactoryInterface
{
    /** @var self[] 协程/全局单例 */
    private static $instances = [];

    /** 获取工厂实例（协程复用 / 全局单例） */
    public static function getInstance(): self
    {
        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            if ($cid > 0) {
                if (!isset(self::$instances[$cid])) {
                    self::$instances[$cid] = new self();
                    call_user_func([$coroClass, 'defer'], static function () use ($cid): void {
                        unset(self::$instances[$cid]);
                    });
                }
                return self::$instances[$cid];
            }
        }
        static $instance = null;
        return $instance ?: $instance = new self();
    }

    /**
     * @param string $streamOrFile
     */
    public function createUploadedFile(
        $streamOrFile,
        ?int $size,
        int $error,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): \Framework\Http\Interfaces\UploadedFileInterface {
        // 上传失败或无文件时，构造空流对象，不检查文件存在性
        if ($error !== \UPLOAD_ERR_OK) {
            $stream = StreamFactory::getInstance()->createStream();
        } elseif (is_string($streamOrFile)) {
            if (!file_exists($streamOrFile)) {
                throw new \InvalidArgumentException("File not found: {$streamOrFile}");
            }
            $stream = StreamFactory::getInstance()->createStreamFromFile($streamOrFile);
        } elseif ($streamOrFile instanceof \Framework\Http\Interfaces\StreamInterface) {
            $stream = $streamOrFile;
        } elseif (is_resource($streamOrFile)) {
            $stream = StreamFactory::getInstance()->createStreamFromResource($streamOrFile);
        } else {
            throw new \InvalidArgumentException('First argument must be string|resource|StreamInterface');
        }

        return new \Framework\Http\Psr7\UploadedFile(
            $stream,
            $size,
            $error,
            $clientFilename,
            $clientMediaType
        );
    }
}

// 上传文件处理
// parseFiles() 将 PHP 原生 $_FILES 或 $ctx['files'] 按字段名处理为 UploadedFileFactory::createUploadedFile(...) 返回的对象阵列
// getUploadedFiles() 提供 Psr\Http\Message\UploadedFileInterface[]，支持 getStream()、moveTo()、getError() 等方法