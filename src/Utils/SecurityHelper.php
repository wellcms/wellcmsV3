<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

use Exception;

/**
 * 加密解密类
 */
class SecurityHelper
{
    /**
     * 通用函数：强制把字符串/数组转为 UTF-8
     *
     * @param mixed $data      输入数据，可以是字符串或数组
     * @param string $default  当无法检测出编码时，假定的原始编码（默认 GBK）
     * @return mixed
     */
    public static function forceUtf8($data, string $default = 'GBK')
    {
        if (is_array($data)) {
            // 递归处理数组
            return array_map(function ($item) use ($default) {
                return self::forceUtf8($item, $default);
            }, $data);
        }

        if (!is_string($data) || $data === '') {
            return $data;
        }

        // 快速检测：如果已经是合法的 UTF-8，直接返回，避免冗余转换
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }

        // 常见编码列表
        $encodings = ['UTF-8', 'GBK', 'BIG5', 'ISO-8859-1', 'Windows-1252'];

        // 检测编码
        $detected = mb_detect_encoding($data, $encodings, true);

        // 如果检测失败，使用默认编码转 UTF-8，否则使用检测到的编码
        return mb_convert_encoding($data, 'UTF-8', $detected ?: $default);
    }

    /**
     * @param string $url
     */
    public static function urlencode($url)
    {
        $url = urlencode($url);
        $search = ['_', '-', '.', '+', '=', '%'];
        $replace = ['_5f', '_2d', '_2e', '_2b', '_3d', '_'];
        return str_replace($search, $replace, $url);
    }

    /**
     * @param string $url
     */
    public static function urldecode($url)
    {
        $search = ['_5f', '_2d', '_2e', '_2b', '_3d', '_'];
        $replace = ['_', '-', '.', '+', '=', '%'];
        $url = str_replace($search, $replace, $url);
        return urldecode($url);
    }

    /**
     * 数组转JSON
     * @param array $data
     * @param bool $pretty true 输出格式化后json / false 输出整串未格式化的json字符串
     * @return string|false
     */
    public static function jsonEncode(array $data, bool $pretty = false)
    {
        return $pretty ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * JSON转数组
     */
    public static function jsonDecode(string $json)
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        // 移除各种可能的 BOM 头和首尾空白
        $json = trim($json, "\xEF\xBB\xBF\xFE\xFF ");
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return void
     */
    public static function removeJsonComments(string $jsonString)
    {
        // 改进正则：保留字符串内容，只删除非字符串内的 // 和 /* ... */ 注释
        return preg_replace_callback(
            '~("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|//.*|/\*.*?\*/~s',
            function ($matches) {
                // 如果匹配到的是 $matches[1]，说明是在字符串内，原样返回
                if (isset($matches[1]) && '' !== $matches[1]) {
                    return $matches[1];
                }
                // 否则是注释，返回空
                return '';
            },
            $jsonString
        );
    }

    // 解码客户端提交的 base64 数据
    /**
     * @param string $data
     */
    public static function base64DecodeFileData(string $data)
    {
        if ('data:' == substr($data, 0, 5)) {
            $data = substr($data, strpos($data, ',') + 1); // 去掉 data:image/png;base64,
        }
        return base64_decode($data);
    }

    // 字符串编码转换
    public static function codeConversion(string $str, string $charset = 'utf-8', string $original = '')
    {
        // 定义支持的字符集
        $defaultEncodings = [
            'utf-8',
            'gb2312',
            'gbk',
            'big5',
            'ascii',
            'utf-16',
            'ucs-2',
            'iso-8859-1',
            'iso-8859-15'
        ];

        // 如果提供了原始编码，直接使用 iconv 转换
        if (!empty($original)) {
            try {
                return iconv($original, $charset . '//IGNORE', $str);
            } catch (Exception $e) {
                error_log("Encoding conversion failed: " . $e->getMessage());
                return $str; // 返回原始字符串作为回退
            }
        }

        // 检测原始编码
        $detectedEncoding = mb_detect_encoding($str, $defaultEncodings, true);

        if ($detectedEncoding === false) {
            // 如果检测失败，记录日志并假定为目标编码
            error_log("Encoding detection failed for string. Assuming charset: $charset");
            $detectedEncoding = $charset;
        }

        try {
            // 转换编码
            return mb_convert_encoding($str, $charset, $detectedEncoding);
        } catch (Exception $e) {
            error_log("Encoding conversion failed: " . $e->getMessage());
            return $str; // 返回原始字符串作为回退
        }
    }

    /**
     * 生成 Token
     * @param string $key 长度不能超过64位
     * @param array $data = ['user_id' => $userId, 'ip' => $ip, 'ua' => md5($userAgent), 'time' => time()];
     * @return string|false
     */
    public static function generateToken(string $key, array $data)
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data array must contain 'user_id', 'ip', 'ua', and 'time' keys.");
        }
        $json = json_encode($data);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode data to JSON: " . json_last_error_msg());
        }
        return self::encrypt($json, $key);
    }

    /**
     * 解密 Token
     * @param string $key 长度不能超过64位
     * @param string $token
     * @return array|null
     * 解密得到数据比对 $data = ['user_id' => $userId, 'ip' => $ip, 'ua' => md5($userAgent), 'time' => time()];
     * $data['user_id'] === $userId && $data['ip'] === $ip && $data['ua'] === md5($userAgent) && time() - $data['time'] <= 3600;
     */
    public static function decodeToken(string $key, string $token)
    {
        $decrypted = self::decrypt($token, $key);
        if (!$decrypted) return null;

        return json_decode($decrypted, true);
    }

    public static function base64UrlEncode(string $string)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], $string);
    }

    /**
     * 脱敏日志中的敏感信息
     *
     * 对字符串中可能包含的密码、密钥、token 等敏感字段进行掩码处理。
     *
     * @param string $message 原始日志消息
     * @return string 脱敏后的消息
     */
    public static function maskSensitiveInString(string $message): string
    {
        // 敏感关键字列表（键值对形式：字段名 => 是否掩码值）
        $sensitiveKeys = [
            'password', 'passwd', 'secret', 'token', 'auth_key', 'api_secret',
            'private_key', 'pay_sign_key', 'session_key', 'access_token',
            'credential', 'api_key', 'sign_key', 'webhook_secret',
        ];

        // 1. 掩码 URL 查询参数中的敏感信息
        $message = preg_replace_callback(
            '/(' . implode('|', $sensitiveKeys) . ')=([^&\s]+)/i',
            function ($matches) {
                $key = $matches[1];
                $val = $matches[2];
                $masked = strlen($val) > 4
                    ? substr($val, 0, 2) . str_repeat('*', strlen($val) - 4) . substr($val, -2)
                    : str_repeat('*', strlen($val));
                return $key . '=' . $masked;
            },
            $message
        );

        // 2. 掩码 JSON 中的敏感字段值（简单字符串替换）
        foreach ($sensitiveKeys as $key) {
            $message = preg_replace(
                '/"' . preg_quote($key, '/') . '"\s*:\s*"([^"]+)"/i',
                '"' . $key . '":"***MASKED***"',
                $message
            );
        }

        // 3. 掩码数据库连接字符串中的密码
        $message = preg_replace('/(password|pwd)=([^;\s]+)/i', '$1=***MASKED***', $message);

        return $message;
    }

    /**
     * 字符传输加密数据
     *
     * 支持 AES-256-CBC（遗留兼容）和 AES-256-GCM（推荐，提供机密性+完整性）。
     * GCM 模式下密文格式：base64url(base64(IV + tag + ciphertext))
     *
     * @param string $data 待加密明文
     * @param string $key 加密密钥
     * @param string $method 加密算法，默认 AES-256-CBC，建议显式传入 AES-256-GCM
     * @return string|false 加密后的密文，失败返回 false
     */
    public static function encrypt(string $data, string $key, string $method = 'AES-256-CBC')
    {
        // 1. Key 安全推导：确保密钥长度固定为算法要求的长度（AES-256 为 32 字节）
        $hashKey = hash('sha256', $key, true);

        $ivSize = openssl_cipher_iv_length($method) ?: 16;
        $iv = random_bytes($ivSize);

        // GCM 模式：同时获得机密性和完整性认证（AEAD）
        if (stripos($method, 'GCM') !== false || stripos($method, 'CCM') !== false) {
            $tag = '';
            $tagLength = 16;
            $encrypted = openssl_encrypt($data, $method, $hashKey, OPENSSL_RAW_DATA, $iv, $tag, '', $tagLength);
            if ($encrypted === false) {
                return false;
            }
            return self::base64UrlEncode(base64_encode($iv . $tag . $encrypted));
        }

        // CBC 模式：仅提供机密性（向后兼容）
        $encrypted = openssl_encrypt($data, $method, $hashKey, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return false;
        }

        // 2. 将 IV 和加密数据合并并转为 URL 安全的 Base64
        return self::base64UrlEncode(base64_encode($iv . $encrypted));
    }

    public static function base64UrlDecode(string $string)
    {
        $string = str_replace(['-', '_'], ['+', '/'], $string);

        // 补充缺失的 '='，使字符串长度是 4 的倍数
        $mod4 = strlen($string) % 4;
        if ($mod4 > 0) {
            $string .= str_repeat('=', 4 - $mod4);
        }

        return $string;
    }

    /**
     * 字符传输解密数据
     *
     * GCM 模式下密文格式：base64url(base64(IV + tag + ciphertext))
     *
     * @param string $encryptedData 密文
     * @param string $key 解密密钥
     * @param string $method 加密算法，默认 AES-256-CBC，建议显式传入 AES-256-GCM
     * @return string|false 解密后的明文，失败返回 false
     */
    public static function decrypt(string $encryptedData, string $key, string $method = 'AES-256-CBC')
    {
        // 1. 恢复 Base64 补位并解码
        $rawData = base64_decode(self::base64UrlDecode($encryptedData));
        if ($rawData === false) {
            return false;
        }

        $ivSize = openssl_cipher_iv_length($method) ?: 16;

        // GCM 模式：解析 IV + tag + ciphertext
        if (stripos($method, 'GCM') !== false || stripos($method, 'CCM') !== false) {
            $tagLength = 16;
            $minLength = $ivSize + $tagLength;
            if (strlen($rawData) <= $minLength) {
                return false;
            }
            $iv = substr($rawData, 0, $ivSize);
            $tag = substr($rawData, $ivSize, $tagLength);
            $encrypted = substr($rawData, $minLength);
            $hashKey = hash('sha256', $key, true);
            return openssl_decrypt($encrypted, $method, $hashKey, OPENSSL_RAW_DATA, $iv, $tag);
        }

        // CBC 模式（向后兼容）
        if (strlen($rawData) <= $ivSize) {
            return false;
        }

        $iv = substr($rawData, 0, $ivSize);
        $encrypted = substr($rawData, $ivSize);

        // 2. Key 保持推导一致性
        $hashKey = hash('sha256', $key, true);

        return openssl_decrypt($encrypted, $method, $hashKey, OPENSSL_RAW_DATA, $iv);
    }
}