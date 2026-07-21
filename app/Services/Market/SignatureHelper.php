<?php

/**
 * SignatureHelper - 应用商店签名辅助类
 *
 * 提供签名、验证相关的工具方法
 *
 * 职责：
 * 1. 基础签名（http_build_query + HMAC-SHA256）
 * 2. 请求签名（JSON + 递归排序 + HMAC-SHA256）
 * 3. 响应签名验证
 * 4. 辅助方法：递归排序
 */

declare(strict_types=1);

namespace App\Services\Market;

class SignatureHelper
{
    /**
     * 基础签名方法（使用 http_build_query）
     *
     * @param array $params 待签名参数
     * @param string $key 密钥
     * @return string Base64 编码的签名
     */
    public static function sign(array $params, string $key): string
    {
        ksort($params);
        $payload = http_build_query($params);
        return base64_encode(hash_hmac('sha256', $payload, $key, true));
    }

    /**
     * 签名请求数据（使用 JSON + 递归排序）
     *
     * 这是 V4 协议推荐的标准签名方法
     *
     * @param array $data 待签名数据
     * @param string $key 密钥
     * @return string 十六进制签名
     */
    public static function signRequest(array $data, string $key): string
    {
        // 移除 sign 字段（如果存在）
        $signData = $data;
        unset($signData['sign']);

        // 递归排序
        $sorted = self::recursiveKsort($signData);

        // JSON 编码（无转义斜杠和 Unicode）
        $payload = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode data for signing: ' . json_last_error_msg());
        }

        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * 验证请求签名
     *
     * @param array $data 包含 sign 字段的数据
     * @param string $key 密钥
     * @return bool 签名是否有效
     */
    public static function verifyRequest(array $data, string $key): bool
    {
        if (empty($data['sign'])) {
            return false;
        }

        $expectedSign = $data['sign'];
        $computedSign = self::signRequest($data, $key);

        return hash_equals($expectedSign, $computedSign);
    }

    /**
     * 递归按键排序数组
     *
     * 对数组进行深度排序，确保嵌套数组也能保持稳定的序列化顺序
     *
     * @param array $array 待排序数组
     * @return array 排序后的数组
     */
    public static function recursiveKsort(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::recursiveKsort($value);
            }
        }

        return $array;
    }

    /**
     * 生成随机 nonce
     *
     * @param int $length 长度（默认16）
     * @return string
     */
    public static function generateNonce(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 计算时间差是否有效（防止重放攻击）
     *
     * @param int $requestTime 请求时间戳
     * @param int $maxWindow 最大时间窗口（秒，默认300）
     * @return bool
     */
    public static function isTimestampValid(int $requestTime, int $maxWindow = 300): bool
    {
        $diff = abs(time() - $requestTime);
        return $diff <= $maxWindow;
    }
}
