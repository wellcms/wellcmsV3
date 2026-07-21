<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

/**
 * PHP 7.2+ 高性能UUIDv7生成器
 * 特性：使用random_bytes()（PHP 7+优化）
 */
class UuidHelper
{
    private /** @var int */
    static $last_timestamp = 0;
    private /** @var int */
    static $sequence = 0;
    /** @var object|null */
    private static $lock = null;

    /**
     * 获取协程/进程级锁，优先使用协程 Mutex（只阻塞协程，不阻塞进程）
     * @return object|null
     */
    private static function getLock()
    {
        if (self::$lock === null && \extension_loaded('swoole')) {
            $mutexClass = '\\Swoole\\Coroutine\\Mutex';
            $lockClass  = '\\Swoole\\Lock';
            if (class_exists($mutexClass)) {
                self::$lock = new $mutexClass();
            } elseif (class_exists($lockClass) && \defined('SWOOLE_MUTEX')) {
                self::$lock = new $lockClass(\SWOOLE_MUTEX);
            }
        }
        return self::$lock;
    }

    /**
     * 判断当前是否处于 Swoole 协程环境
     */
    private static function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * 高性能生成 UUIDv7 (时间有序)
     * 直接位运算生成二进制，避免字符串拼接开销 (提升约 5-10 倍性能)
     *
     * @param bool $binary 是否返回 16 字节二进制格式
     * @return string
     */
    public static function generate(bool $binary = true): string
    {
        $timestamp = (int)floor(microtime(true) * 1000);
        $lock = self::getLock();
        $inSwoole = self::inCoroutine();

        if ($inSwoole && $lock) {
            $lock->lock();
        }

        if ($timestamp <= self::$last_timestamp) {
            self::$sequence++;
            $timestamp = self::$last_timestamp;
        } else {
            self::$sequence = 0;
            self::$last_timestamp = $timestamp;
        }

        // 复制到局部变量，尽量缩短锁持有时间
        $seq = self::$sequence;

        if ($inSwoole && $lock) {
            $lock->unlock();
        }

        // 1. 构造 48 位时间戳 (6 字节)
        $bin = pack('J', $timestamp);
        $bin = substr($bin, 2);

        // 2. 构造版本位 + 序列号 (2 字节): 0x7xxx (v7)
        $bin .= pack('n', (0x7000 | ($seq & 0x0FFF)));

        // 3. 构造变体位 + 随机数 (8 字节)
        $random = random_bytes(8);
        // Variant RFC 4122: 10xx 表明版本兼容性 (8, 9, a, b)
        $random[0] = chr((ord($random[0]) & 0x3F) | 0x80);
        $bin .= $random;

        return $binary ? $bin : self::fromBinary($bin);
    }

    /**
     * 字符串转二进制（MySQL BINARY(16) 高效存储）
     * 增加幂等保护和 hex2bin 优化
     *
     * @param string $uuid
     * @return string
     */
    public static function toBinary($uuid): string
    {
        if (empty($uuid)) return '';

        // 兼容 PostgreSQL 等资源流返回（与 fromBinary 保持一致）
        if (is_resource($uuid)) {
            $uuid = stream_get_contents($uuid);
        }

        $uuid = (string)$uuid;
        $len = strlen($uuid);

        // 第一层幂等保护：已经是 16 字节二进制原始内容，直接返回
        if ($len === 16) return $uuid;

        // 第二层鲁棒性：检测被错误执行 bin2hex 的异常情况
        // 64字节 = 32位十六进制被bin2hex一次；72字节 = 36位UUID字符串被bin2hex一次
        if (($len === 64 || $len === 72) && ctype_xdigit($uuid)) {
            $decoded = @hex2bin($uuid);
            if ($decoded !== false) {
                $uuid = $decoded;
                $len = strlen($uuid);
            }
        }

        $clean = ($len === 36) ? str_replace('-', '', $uuid) : $uuid;

        // 最终校验：必须是 32 位纯十六进制才能 hex2bin
        if (strlen($clean) === 32 && ctype_xdigit($clean)) {
            $binary = @hex2bin($clean);
            return ($binary !== false) ? $binary : '';
        }

        return '';
    }

    /**
     * 二进制转字符串 (增加资源类型兼容)
     * 适用于 PostgreSQL BYTEA 等流式返回场景
     *
     * @param mixed $binary
     * @return string
     */
    public static function fromBinary($binary): string
    {
        if (empty($binary)) return '';

        // 兼容 PostgreSQL 可能返回的资源流
        if (is_resource($binary)) {
            $binary = stream_get_contents($binary);
        }

        if (strlen((string)$binary) !== 16) {
            return (string)$binary;
        }

        $hex = bin2hex((string)$binary);
        return substr($hex, 0, 8) . '-' .
            substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' .
            substr($hex, 20, 12);
    }
}

// 1. 生成UUID
// $uuid = UUserID::generate(false);
// generate() 返回字符串格式：018dbddd-8f5a-7c00-0000-000000000000

// 2. 去掉连字符
//$uuid_hex = str_replace('-', '', $uuid_str);
//echo "十六进制格式: " . strlen($uuid_hex) . "位\n"; // 输出：32位

// 3. 转为二进制
//$uuid_bin = UUserID::toBinary($uuid_str);
//echo "二进制格式: " . strlen($uuid_bin) . "字节\n"; // 输出：16字节

// 4. 转回十六进制
//$hex_back = bin2hex($uuid_bin);
//echo "转回十六进制: " . strlen($hex_back) . "位\n"; // 输出：32位

// 5. 转回字符串
//$str_back = UUserID::fromBinary($uuid_bin);
//echo "转回字符串: " . strlen($str_back) . "位\n"; // 输出：36位

// 验证一致性
//var_dump($uuid_str === $str_back); // bool(true)

// 批量生成（性能：约88万/秒）
/* $uuids = [];
for ($i = 0; $i < 1000; $i++) {
    $uuids[] = UUserID::generate(true);
}
 */
