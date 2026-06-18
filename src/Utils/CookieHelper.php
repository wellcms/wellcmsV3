<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

/**
 * 工业级 Cookie 管理工具
 * 兼容 PHP 7.2+ 以及 Swoole 协程环境，支持 SameSite 属性
 */
class CookieHelper
{
    /**
     * 下发或清除 Cookie
     * 
     * @param string $name   Cookie 名称 (不含前缀)
     * @param string $value  Cookie 值
     * @param int    $expiry 过期时间戳
     * @param array  $config 核心配置 (需包含 cookie_path, cookie_domain, cookie_secure, httponly, cookie_samesite 等)
     */
    public static function set(string $name, string $value, int $expiry, array $config): void
    {
        // 1. 自动处理前缀
        $pre = $config['pre'] ?? '';
        $cookieName = $pre . $name;

        // 2. 提取公共参数
        $path = $config['cookie_path'] ?? '/';
        $domain = $config['cookie_domain'] ?? '';
        $secure = !empty($config['cookie_secure']);
        $httponly = !empty($config['httponly']);
        $samesite = $config['cookie_samesite'] ?? '';

        // 3. 传统 FPM/CLI 环境下发 (兼容 PHP 各个版本)
        if (PHP_VERSION_ID >= 70300) {
            $options = [
                'expires' => $expiry,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
            ];
            if ($samesite) {
                $options['samesite'] = $samesite;
            }
            setcookie($cookieName, $value, $options);
        } else {
            // PHP 7.2 不支持 $options 数组参数，亦不支持 SameSite
            setcookie($cookieName, $value, $expiry, $path, $domain, $secure, $httponly);
        }

        // 4. Swoole 环境动态穿透适配
        // 使用动态检测与调用，确保在非 Swoole 环境下无报错，且能穿透协程上下文下发
        if (extension_loaded('swoole') && function_exists('call_user_func')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            if ($cid > 0) {
                $ctx = call_user_func([$coroClass, 'getContext']);
                $swooleResponse = $ctx['swoole_response'] ?? null;
                if ($swooleResponse && method_exists($swooleResponse, 'cookie')) {
                    // Swoole 的 cookie 方法原生支持大部分常用参数，包括 samesite
                    $swooleResponse->cookie($cookieName, $value, $expiry, $path, $domain, $secure, $httponly, $samesite);
                }
            }
        }
    }
}
