<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Api;

use App\Services\LinkService;
use App\Utils\HtmlParseHelper;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;

/**
 * 链接预览 API
 *
 * 接受外部 URL，返回标题、favicon、域名、站内/站外判定。
 * 供 WellRTE 编辑器在用户粘贴/输入 URL 时调用，自动预填链接卡片信息。
 *
 * GET /api/linkPreview?url=https://example.com/article
 *
 * @see \App\Services\LinkService
 * @see \App\Utils\HtmlParseHelper
 */
class LinkPreviewController
{
    use HtmlParseHelper;

    /** @var LinkService */
    protected $linkService;

    /** @var \Framework\Core\Container */
    protected $container;

    /** @var \App\Interfaces\LanguageLoaderInterface */
    protected $language;

    public function __construct(
        \Framework\Core\Container $container
    ) {
        $this->container = $container;
        $this->linkService = $container->get(LinkService::class);
        $this->language = $container->get(\App\Interfaces\LanguageLoaderInterface::class);

        // hook app_Controllers_Api_LinkPreviewController_construct_end.php
    }

    /**
     * 获取链接预览信息
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function fetch(ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Api_LinkPreviewController_fetch_start.php

        // 清除前序输出的任何意外内容（如 PHP 弃用警告），确保 JSON 输出纯净
        while (ob_get_level()) ob_end_clean();

        // 1. 仅限登录用户
        $user = $request->getAttribute('user', []);
        if (empty($user['id'])) {
            return $this->json([
                'code' => 1,
                'message' => $this->language->get('login_required') ?: 'Login required',
                'status' => 'error',
                'data' => [],
            ], 401);
        }

        // 2. 获取并校验 URL 参数
        $params = $request->getQueryParams();
        $url = isset($params['url']) ? trim($params['url']) : '';
        if ($url === '' || !$this->isValidUrl($url)) {
            return $this->json([
                'code' => 1,
                'message' => $this->language->get('invalid_url') ?: 'Invalid URL',
                'status' => 'error',
                'data' => [],
            ]);
        }

        // 3. SSRF 防护：校验目标地址安全性
        $validationError = $this->validatePreviewUrl($url);
        if ($validationError !== null) {
            return $this->json([
                'code' => 1,
                'message' => $validationError,
                'status' => 'error',
                'data' => [],
            ]);
        }

        // 4. 抓取页面
        try {
            [$html, $headers] = $this->fetchUrl($url);

            // 未能获取到内容时返回空数据
            if ($html === '') {
                return $this->json([
                    'code' => 0,
                    'status' => 'success',
                    'message' => '',
                    'data' => [
                        'url' => $url,
                        'title' => '',
                        'favicon' => '',
                        'domain' => parse_url($url, PHP_URL_HOST) ?: '',
                        'is_internal' => $this->linkService->isInternal($url),
                    ],
                ]);
            }

            // 响应体长度限制（512KB 以内）
            $maxBytes = 512 * 1024;
            if (strlen($html) > $maxBytes) {
                $html = substr($html, 0, $maxBytes);
            }

            // 5. 提取信息
            $title = $this->extractTitle($html);

            // 过滤 CDN 拦截页面的假标题（Cloudflare 等返回的挑战页）
            if ($title !== '' && preg_match('/^(just a moment|attention required|please wait|checking your browser)/i', $title)) {
                $title = '';
            }
            $favicon = $this->extractFavicon($html, $url);
            $domain = parse_url($url, PHP_URL_HOST);
            $isInternal = $this->linkService->isInternal($url);

            // 安全净化
            $title = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($favicon !== '' && !preg_match('#^https?://#i', $favicon)) {
                $favicon = '';
            }

            $data = [
                'url' => $url,
                'title' => $title,
                'favicon' => $favicon,
                'domain' => $domain ?: '',
                'is_internal' => $isInternal,
            ];

            // hook app_Controllers_Api_LinkPreviewController_fetch_end.php

            return $this->json([
                'code' => 0,
                'status' => 'success',
                'message' => '',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            // 抓取失败 → 返回空数据，让前端回退为普通链接
            return $this->json([
                'code' => 0,
                'status' => 'success',
                'message' => '',
                'data' => [
                    'url' => $url,
                    'title' => '',
                    'favicon' => '',
                    'domain' => parse_url($url, PHP_URL_HOST) ?: '',
                    'is_internal' => $this->linkService->isInternal($url),
                ],
            ]);
        }
    }

    // ========================================================================
    //  JSON 响应
    // ========================================================================

    /**
     * 返回 JSON 响应
     *
     * @param array $data
     * @param int $statusCode
     * @return ResponseInterface
     */
    private function json(array $data, int $statusCode = 200): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = json_encode(['code' => 1, 'message' => 'JSON encode error']);
        }

        $response = \Framework\Http\Psr7\Factories\ResponseFactory::getInstance()
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        $response->getBody()->write($body);
        return $response;
    }

    // ========================================================================
    //  安全校验
    // ========================================================================

    /**
     * 检查 URL 基本格式是否合法
     */
    private function isValidUrl(string $url): bool
    {
        if ($url === '') return false;

        // 仅允许 http / https
        if (!preg_match('#^https?://#i', $url)) return false;

        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) return false;

        // 必须包含点号（排除 localhost 等裸主机名）
        if (strpos($host, '.') === false) return false;

        return true;
    }

    /**
     * SSRF 防护：校验目标地址是否安全
     *
     * @param string $url
     * @return string|null 返回 null 表示安全，返回字符串为错误信息
     */
    private function validatePreviewUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return 'Invalid URL';

        // 端口仅允许 80 / 443
        $port = parse_url($url, PHP_URL_PORT);
        if ($port && !in_array((int)$port, [80, 443], true)) {
            return 'Port not allowed';
        }

        // DNS 解析后检查是否为内网 IP
        $ip = @gethostbyname($host);
        if ($ip === $host) {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ip = $host;
            } else {
                return 'DNS resolution failed';
            }
        }

        // 过滤私有/保留 IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Private or reserved IP not allowed';
        }

        // 禁止链接本地地址
        if (preg_match('/^169\.254\./', $ip)) {
            return 'Link-local IP not allowed';
        }

        return null;
    }
}
