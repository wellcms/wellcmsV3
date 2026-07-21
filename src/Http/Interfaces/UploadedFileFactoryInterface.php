<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{StreamInterface, UploadedFileInterface};

/**
 * Interface UploadedFileFactoryInterface
 *
 * PSR‑17 上传文件工厂接口
 */
interface UploadedFileFactoryInterface
{
    /**
     * 创建一个新的 UploadedFile
     *
     * @param string|resource|StreamInterface $streamOrFile     文件路径、资源或流
     * @param int                             $size             文件大小
     * @param int                             $error            上传错误码
     * @param string                          $clientFilename   客户端文件名
     * @param string                          $clientMediaType  客户端 MIME 类型
     * @return UploadedFileInterface
     */
    public function createUploadedFile(
        $streamOrFile,
        int $size,
        int $error,
        string $clientFilename,
        string $clientMediaType
    ): UploadedFileInterface;
}
