<?php

declare(strict_types=1);

/**
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

//FileHasher::sha256($file, 'raw');     // string (32 bytes) 存 BINARY(32)
//FileHasher::sha256($file, 'hex');     // string (64 chars) API / 前端
//FileHasher::sha256($file, 'base64');  // string (44 chars) JSON
class FileHasher
{
    // 支持的算法（白名单，避免 hash_algos() 开销）
    const ALGORITHM_SHA256 = 'sha256';
    const ALGORITHM_SHA1   = 'sha1';
    const ALGORITHM_MD5    = 'md5';

    // 输出格式
    const FORMAT_RAW    = 'raw';
    const FORMAT_HEX    = 'hex';
    const FORMAT_BASE64 = 'base64';

    /**
     * 计算文件哈希
     *
     * @param string $filePath
     * @param string $algorithm
     * @param string $outputFormat raw | hex | base64
     * @return string|false
     */
    public static function hash(
        string $filePath,
        string $algorithm = self::ALGORITHM_SHA256,
        string $outputFormat = self::FORMAT_HEX
    ): string {
        self::validateFile($filePath);

        if (!self::isSupportedAlgorithm($algorithm)) {
            throw new \InvalidArgumentException("Unsupported hash algorithm: {$algorithm}");
        }

        $raw = @hash_file($algorithm, $filePath, true);
        if ($raw === false) {
            throw new \RuntimeException("Failed to calculate hash for file: {$filePath}");
        }

        return self::formatHash($raw, $outputFormat);
    }

    /**
     * 计算 SHA256（快捷方法）
     *
     * @param string $filePath
     * @param string $outputFormat
     * @return string|false
     */
    public static function sha256(string $filePath, string $outputFormat = self::FORMAT_HEX): string
    {
        return self::hash($filePath, self::ALGORITHM_SHA256, $outputFormat);
    }

    /**
     * ===== 内部方法 =====
     */

    private static function isSupportedAlgorithm(string $algorithm): bool
    {
        return in_array($algorithm, [
            self::ALGORITHM_SHA256,
            self::ALGORITHM_SHA1,
            self::ALGORITHM_MD5,
        ], true);
    }

    /**
     * 格式化 hash（二进制 → 表示层）
     *
     * @param string $raw
     * @param string $format
     * @return string|false
     */
    private static function formatHash(string $raw, string $format): string
    {
        switch ($format) {
            case self::FORMAT_RAW:
                return $raw;

            case self::FORMAT_HEX:
                return bin2hex($raw);

            case self::FORMAT_BASE64:
                return base64_encode($raw);

            default:
                throw new \InvalidArgumentException("Invalid output format: {$format}");
        }
    }

    /**
     * 验证文件
     */
    private static function validateFile(string $filePath): void
    {
        if ($filePath === '') {
            throw new \InvalidArgumentException("File path cannot be empty");
        }

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File does not exist: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new \RuntimeException("Path is not a file: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File is not readable: {$filePath}");
        }
    }
}
