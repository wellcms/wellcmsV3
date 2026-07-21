<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Utils;

/**
 * 通用 HTML 解析工具（PHP 7.2 兼容）
 * - detectEncoding(): 从 HTTP 头 / <meta> 猜测原始编码
 * - normalizeToUtf8(): 将 HTML 统一转为 UTF-8，并注入 <meta charset="utf-8">
 * - loadDom(): 安全加载为 DOMDocument
 * - fetchUrl(): 简易抓取，返回 [body, headers]
 * - absolutizeUrl(): 把相对链接转为绝对 URL（用于 favicon 等）
 */
trait HtmlParseHelper
{
    /**
     * 简易抓取：优先用框架 HttpClient，兜底 stream
     * @return array [string $body, array $headers]
     */
    protected function fetchUrl(string $url): array
    {
        // 真实浏览器 User-Agent 串，降低被 Cloudflare / bot 检测拦截的概率
        $browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

        // 优先使用框架 HttpClient（走 cURL）
        try {
            $client = new \Framework\Utils\HttpClient();
            $response = $client->request([
                'url' => $url,
                'timeout' => 8,
                'followRedirects' => true,
                'maxRedirects' => 3,
                'verifySSL' => false,
                'returnResponse' => true,
                'throwOnError' => false,
                'headers' => [
                    'User-Agent: ' . $browserUA,
                    'Accept: text/html, application/xhtml+xml',
                ],
            ]);

            if ($response instanceof \Framework\Http\Interfaces\ResponseInterface) {
                $body = (string) $response->getBody();
                $headers = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $headers[] = "$name: " . implode(', ', $values);
                }
                return [$body, $headers];
            }
        } catch (\Throwable $e) {
            // HttpClient 异常，降级到 stream
        }

        // 兜底：stream 上下文（仅当 cURL 不可用时）
        $prevLevel = error_reporting();
        error_reporting($prevLevel & ~E_DEPRECATED);
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'header' => "User-Agent: " . $browserUA . "\r\nAccept: text/html\r\n",
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = @file_get_contents($url, false, $context);
        } finally {
            error_reporting($prevLevel);
        }
        return [$body ?: '', []];
    }

    /**
     * 从响应头与 HTML <meta> 中猜测原始编码；默认回退 UTF-8
     */
    protected function detectEncoding(string $html, array $headers = []): string
    {
        // 1) 先从响应头找 charset
        foreach ($headers as $line) {
            if (stripos($line, 'content-type:') !== false && stripos($line, 'charset=') !== false) {
                $pos = stripos($line, 'charset=');
                if ($pos !== false) {
                    $enc = trim(substr($line, $pos + 8));
                    $enc = trim(str_replace([';', '"', "'"], '', $enc));
                    if ($enc !== '') return strtoupper($enc);
                }
            }
        }

        // 2) 再从 <meta charset=...> / <meta http-equiv=...> 中找
        //   使用正则快速匹配（无需先 load DOM，避免错误编码导致的乱码）
        if (preg_match('/<meta\s+charset=["\']?\s*([a-z0-9\-\_]+)\s*["\']?/i', $html, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/<meta\s+http-equiv=["\']content-type["\']\s+content=["\'][^"\']*charset=([a-z0-9\-\_]+)[^"\']*["\']/i', $html, $m)) {
            return strtoupper($m[1]);
        }

        // 3) 默认回退 UTF-8
        return 'UTF-8';
    }

    /**
     * 统一转为 UTF-8，并确保 <head> 里存在 <meta charset="UTF-8">
     */
    protected function normalizeToUtf8(string $html, string $sourceEncoding = 'UTF-8'): string
    {
        // 先把原始字节流转换为 HTML-ENTITIES（DOMDocument 最稳）
        // 如果源本身是 UTF-8，mb_convert_encoding 也安全
        $utf8Html = @mb_convert_encoding($html, 'HTML-ENTITIES', $sourceEncoding ?: 'UTF-8');

        // 注入/替换 meta charset，确保 DOMDocument 以 UTF-8 解读
        // 有 <head>：在 <head> 起始处插入；无 <head>：加一个
        if (stripos($utf8Html, '<head') !== false) {
            // 若已有 meta charset，尽量替换；否则插入
            if (preg_match('/<meta\s+charset=["\']?([a-z0-9\-\_]+)["\']?/i', $utf8Html)) {
                $utf8Html = preg_replace('/<meta\s+charset=["\']?([a-z0-9\-\_]+)["\']?/i', '<meta charset="UTF-8"', $utf8Html, 1);
            } else {
                $utf8Html = preg_replace('/<head([^>]*)>/i', '<head$1><meta charset="UTF-8">', $utf8Html, 1);
            }
        } else {
            // 粗暴兜底：包一层 <head>
            $utf8Html = '<head><meta charset="UTF-8"></head>' . $utf8Html;
        }

        return $utf8Html;
    }

    /**
     * 安全加载 HTML 为 DOMDocument（UTF-8）
     */
    protected function loadDom(string $html, array $headers = []): \DOMDocument
    {
        $enc = $this->detectEncoding($html, $headers);
        $normalized = $this->normalizeToUtf8($html, $enc);

        $doc = new \DOMDocument();
        // 避免警告：HTML 不完整/非法时不抛 warning
        @$doc->loadHTML($normalized, LIBXML_NOWARNING | LIBXML_NOERROR);
        return $doc;
    }

    /**
     * 将相对 URL 转为绝对 URL（简易版，够 favicon 等使用）
     */
    protected function absolutizeUrl(string $base, string $href): string
    {
        if ($href === '') return $base;
        // 已是绝对
        if (preg_match('~^(?:https?:)?//~i', $href)) return $href;

        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $href;

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $root = $scheme . '://' . $host . $port;

        if ($href[0] === '/') {
            return $root . $href;
        }

        // 相对路径：拼在 base path 后
        $path = isset($parts['path']) ? $parts['path'] : '/';
        // 去掉文件名，保留目录
        $path = rtrim(substr($path, 0, strrpos($path . '/', '/')), '/') . '/';
        return $root . $path . $href;
    }

    /**
     * 从 HTML 中提取标题
     *
     * 优先提取 <title>，兜底 Open Graph / Twitter Card 元标签
     *
     * @param string $html 页面 HTML
     * @return string 标题（纯文本，已 trim），找不到返回空字符串
     */
    protected function extractTitle(string $html): string
    {
        if ($html === '') return '';

        // 1. <title> 标签
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        // 2. Open Graph og:title（JS 渲染站点通常服务端输出此标签）
        if (preg_match('/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/si', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\']\s+[^>]*property=["\']og:title["\']/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        // 3. Twitter Card twitter:title
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\']/si', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\']\s+[^>]*name=["\']twitter:title["\']/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        return '';
    }

    /**
     * 从 HTML 中提取 favicon URL
     *
     * 查找优先级：
     * 1. <link rel="icon" href="...">
     * 2. <link rel="shortcut icon" href="...">
     * 3. <link rel="apple-touch-icon" href="...">
     * 4. 回退：{pageUrl}/favicon.ico
     *
     * @param string $html    页面 HTML
     * @param string $pageUrl 页面完整 URL（用于解析相对路径 + 回退）
     * @return string favicon 绝对 URL，找不到返回空字符串
     */
    protected function extractFavicon(string $html, string $pageUrl): string
    {
        $patterns = [
            // 标准 favicon + shortcut icon
            '/<link[^>]+rel=["\']?(?:shortcut\s+)?icon["\']?[^>]+href=["\']([^"\']+)["\']/si',
            // apple-touch-icon
            '/<link[^>]+rel=["\']?apple-touch-icon["\']?[^>]+href=["\']([^"\']+)["\']/si',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $favicon = trim($m[1]);
                if ($favicon !== '') {
                    return $this->absolutizeUrl($pageUrl, $favicon);
                }
            }
        }

        // 回退：默认 favicon 路径
        $default = rtrim($pageUrl, '/') . '/favicon.ico';
        return $default;
    }
}
