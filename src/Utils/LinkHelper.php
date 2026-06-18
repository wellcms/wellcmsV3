<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

/**
 * 高性能链接压缩与签名
 */
class LinkHelper {
    private /** @var string */
    static $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // 转为 62 进制
    public static function encode62(int $num): string {
        $res = '';
        while ($num > 0) {
            $res = self::$chars[$num % 62] . $res;
            $num = (int)($num / 62);
        }
        return $res ?: '0';
    }

    // 62 进制转回数字
    public static function decode62(string $str): int {
        $res = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $res = $res * 62 + strpos(self::$chars, $str[$i]);
        }
        return $res;
    }

    // 生成带签名的短 Slug
    public static function makeSlug(int $id, int $ts, string $salt): string {
        $id62 = self::encode62($id);
        $ts62 = self::encode62($ts);
        // 签名不需要太长，6位 md5 截断足以防御 99.999% 的篡改尝试
        $sign = substr(hash_hmac('md5', $id . $ts, $salt), 0, 6);
        return "{$id62}-{$ts62}-{$sign}";
    }

    /**
     * 解析 Slug 并验证签名
     * @return array [id, created_at] 验证失败返回空数组
     */
    public static function parseSlug(string $slug, string $salt): array {
        $parts = explode('-', $slug);
        if (count($parts) !== 3) return [];

        $id = self::decode62($parts[0]);
        $ts = self::decode62($parts[1]);
        $sign = $parts[2];

        // 重新计算签名验证
        $expectedSign = substr(hash_hmac('md5', $id . $ts, $salt), 0, 6);

        if ($sign !== $expectedSign) {
            return [];
        }

        return ['id' => $id, 'created_at' => $ts];
    }
}
