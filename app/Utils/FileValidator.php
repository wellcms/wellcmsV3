<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Utils;

use Framework\Exception\ValidationException;
use Framework\Exception\Http\UploadSecurityException;

/**
 * 文件上传验证类
 * 提供文件上传的安全验证（MIME类型、文件大小、扩展名等）
 *
 * @package App\Utils
 */
class FileValidator
{
    /** @var array 允许的文件扩展名 */
    protected $allowedExtensions = [];

    /** @var array 允许的MIME类型 */
    protected $allowedMimeTypes = [];

    /** @var int 最大文件大小（字节） */
    protected $maxFileSize;

    /** @var int 最大文件名长度 */
    protected $maxFileNameLength = 255;

    /** @var array 上传错误消息 */
    protected static $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];

    /**
     * 构造函数
     * @param array $config 验证配置
     */
    public function __construct(array $config = [])
    {
        $this->allowedExtensions = $config['extensions'] ?? [];
        $this->allowedMimeTypes = $config['mime_types'] ?? [];
        $this->maxFileSize = $config['max_size'] ?? 2 * 1024 * 1024;  //默认2MB
        $this->maxFileNameLength = $config['max_filename_length'] ?? 255;
    }

    /**
     * 验证单个上传文件
     * @param array $file $_FILES数组中的文件项
     * @return bool
     * @throws \Exception
     */
    public function validate(array $file): bool
    {
        if (empty($file) || !is_array($file)) {
            throw new ValidationException("Invalid file data");
        }

        // 1. 检查上传错误
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $message = self::$errorMessages[$file['error']] ?? 'Unknown upload error';
            throw new ValidationException($message);
        }

        // 2. 检查文件是否上传
        if (empty($file['tmp_name'])) {
            throw new UploadSecurityException("File was not uploaded through HTTP POST");
        }

        // FPM 模式下需要 is_uploaded_file 校验来源；Swoole 模式下 $request->files 由引擎直接解析，来源可信
        if ((!\defined('SWOOLE_MODE') || !\SWOOLE_MODE) && !is_uploaded_file($file['tmp_name'])) {
            throw new UploadSecurityException("File was not uploaded through HTTP POST");
        }

        // 3. 验证文件名
        $this->validateFileName($file['name'] ?? '');

        // 4. 验证文件大小
        $this->validateFileSize($file['size'] ?? 0);

        // 5. 验证MIME类型
        $this->validateMimeType($file['type'] ?? '', $file['tmp_name']);

        // 6. 验证扩展名
        $this->validateExtension($file['name'] ?? '');

        return true;
    }

    /**
     * 批量验证多个文件
     * @param array $files $_FILES数组
     * @return bool
     * @throws \Exception
     */
    public function validateMultiple(array $files): bool
    {
        if (isset($files['name']) && is_array($files['name'])) {
            // 多个文件上传（相同name）
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $this->validate($file);
            }
        } else {
            // 单个文件或已标准化的多个文件
            foreach ($files as $file) {
                $this->validate($file);
            }
        }

        return true;
    }

    /**
     * 验证文件名
     * @param string $fileName
     * @throws \Exception
     */
    protected function validateFileName(string $fileName): void
    {
        // 移除路径（防止路径遍历）
        $fileName = basename($fileName);

        // 检查文件名长度
        if (strlen($fileName) > $this->maxFileNameLength) {
            throw new UploadSecurityException("File name exceeds maximum length of {$this->maxFileNameLength} characters");
        }

        // 检查文件名是否包含非法字符
        if (!preg_match('/^[a-zA-Z0-9_-]+\.[^\\\/:*?"<>|]+$/', $fileName)) {
            throw new UploadSecurityException("Invalid file name: {$fileName}");
        }
    }

    /**
     * 验证文件大小
     * @param int $size
     * @throws \Exception
     */
    protected function validateFileSize(int $size): void
    {
        if ($size <= 0) {
            throw new ValidationException("File size must be greater than 0");
        }

        if ($size > $this->maxFileSize) {
            $mb = round($this->maxFileSize / 1024 / 1024, 2);
            throw new ValidationException("File size must not exceed {$mb}MB");
        }
    }

    /**
     * 验证MIME类型
     * @param string $clientMime 客户端提供的MIME类型
     * @param string $tmpPath 临时文件路径
     * @throws \Exception
     */
    protected function validateMimeType(string $clientMime, string $tmpPath): void
    {
        // 仅当配置了允许的MIME类型时才验证
        if (empty($this->allowedMimeTypes)) {
            return;
        }

        // 使用文件系统检测真实的MIME类型（更安全）
        if (!function_exists('finfo_open')) {
            throw new \RuntimeException("Fileinfo extension is required for MIME type validation");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($tmpPath);

        // 使用真实的MIME类型进行验证
        if (!in_array($realMime, $this->allowedMimeTypes, true)) {
            throw new UploadSecurityException(
                "File type {$realMime} is not allowed. Allowed types: " . implode(', ', $this->allowedMimeTypes)
            );
        }

        // 验证客户端提供的MIME类型是否匹配（可选）
        if ($clientMime && $clientMime !== $realMime) {
            throw new UploadSecurityException("MIME type mismatch. Client: {$clientMime}, Actual: {$realMime}");
        }
    }

    /**
     * 验证文件扩展名
     * @param string $fileName
     * @throws \Exception
     */
    protected function validateExtension(string $fileName): void
    {
        if (empty($this->allowedExtensions)) {
            return; // 未配置则跳过验证
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new UploadSecurityException(
                "File extension .{$extension} is not allowed. Allowed extensions: " . implode(', ', $this->allowedExtensions)
            );
        }
    }

    /**
     * 设置允许的扩展名
     * @param array $extensions
     * @return mixed
     */
    public function setAllowedExtensions(array $extensions): FileValidator
    {
        $this->allowedExtensions = array_map('strtolower', $extensions);
        return $this;
    }

    /**
     * 设置允许的MIME类型
     * @param array $mimeTypes
     * @return mixed
     */
    public function setAllowedMimeTypes(array $mimeTypes): FileValidator
    {
        $this->allowedMimeTypes = $mimeTypes;
        return $this;
    }

    /**
     * 设置最大文件大小
     * @param int $size 字节数
     * @return mixed
     */
    public function setMaxFileSize(int $size): FileValidator
    {
        $this->maxFileSize = $size;
        return $this;
    }

    /**
     * 设置预定义的常见图片配置
     * @return mixed
     */
    public function setImageDefaults(): FileValidator
    {
        $this->allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $this->allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        $this->maxFileSize = 5 * 1024 * 1024; // 5MB
        return $this;
    }

    /**
     * 设置预定义的文档配置
     * @return mixed
     */
    public function setDocumentDefaults(): FileValidator
    {
        $this->allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $this->allowedMimeTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
        return $this;
    }

    /**
     * 生成安全的文件名
     * @param string $originalName 原始文件名
     * @param string $prefix 前缀
     * @return string
     */
    public static function generateSafeFilename(string $originalName, string $prefix = ''): string
    {
        // 获取扩展名
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        // 生成随机字符串
        $random = bin2hex(random_bytes(16));

        // 构建安全文件名
        $filename = $prefix . $random;

        // 如果扩展名合法则保留
        if (!empty($extension) && preg_match('/^[a-zA-Z0-9]+$/', $extension)) {
            $filename .= '.' . strtolower($extension);
        }

        return $filename;
    }
}