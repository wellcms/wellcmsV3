<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

class UrlHelper
{
    /**
     * 将参数添加到 URL (支持标准 URL 参数与 WellCMS SEO 伪静态)
     * 
     * @param string $url 原始 URL
     * @param string $k   参数键名
     * @param string|int|float $v 参数值
     * @return string 处理后的 URL
     */
    public static function urlAddArg(string $url, string $k, $v): string
    {
        if ($url === '') return '';

        $v = (string)$v;
        $pos = strpos($url, '.html');

        // 1. 如果包含 .html，遵循 WellCMS 伪静态规则：直接附加值到文件名后（如 thread-1.html -> thread-1-2.html）
        if (false !== $pos && strpos($url, '?') === false) {
            return substr($url, 0, $pos) . '-' . urlencode($v) . substr($url, $pos);
        }

        // 2. 标准 URL 参数添加逻辑
        $encodedK = urlencode($k);
        $encodedV = urlencode($v);
        $pair = "{$encodedK}={$encodedV}";

        if (strpos($url, '?') === false) {
            return $url . '?' . $pair;
        }

        // 处理末尾已经有 ? 或 & 的特殊情况
        $lastChar = substr($url, -1);
        if ($lastChar === '?' || $lastChar === '&') {
            return $url . $pair;
        }

        return $url . '&' . $pair;
    }

    /**
     * 高级安全跳转逻辑
     * 
     * @param string $message 提示消息
     * @param string $url     跳转目标
     * @param int    $delay   延迟秒数
     * @return string
     */
    public static function jump(string $message = '', string $url = '', int $delay = 3): string
    {
        if ($url === '') {
            return htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 1. 安全处理 URL
        $isBack = ($url === 'back');
        if ($isBack) {
            $rawUrl = 'javascript:history.back()';
            $safeUrl = $rawUrl;
        } else {
            // 防御 XSS：对于非 back 链接，拦截危险协议，但允许正常跳转
            if (preg_match('/^(javascript|data|vbs):/i', trim($url))) {
                $rawUrl = '#'; // 拦截恶意执行
            } else {
                $rawUrl = $url;
            }
            $safeUrl = htmlspecialchars($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. 构建 JS 跳转
        // 使用 json_encode 完美处理 JS 字符串转义，杜绝引号逃逸攻击
        $jsUrl = json_encode($rawUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = sprintf(
            '<script>setTimeout(function(){ window.location = %s; }, %d);</script>',
            $jsUrl,
            $delay * 1000
        );

        return sprintf('<a href="%s">%s</a> %s', $safeUrl, $safeMessage, $script);
    }

    /**
     * 验证 SEO Slug 是否符合规范 [a-z0-9-.]
     * 
     * @param mixed $slug
     * @return bool
     */
    public static function isValidSlug($slug): bool
    {
        if ($slug === null || $slug === '') return false;
        return (bool)preg_match('/^[a-zA-Z0-9\-.]+$/', (string)$slug);
    }
}
