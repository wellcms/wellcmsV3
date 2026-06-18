<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services;

use Framework\Utils\IpHelper;

/**
 * 核心链接服务 —— URL 判断、域名解析、站点信息
 *
 * 职责：
 * - isInternal()     判断 URL 是否为站内链接（被 RTE link-preview API、ExternalLinkService 共用）
 * - getSiteUrl()     返回当前站点 URL（供前端站内/站外判断）
 * - getSiteHosts()   返回当前站点所有合法 Host（含 www 兼容）
 * - getWhitelist()   返回外链白名单域名
 *
 * 与 ExternalLinkService 的关系：
 *   LinkService（基础层）：isInternal()、域名解析、白名单管理
 *   ExternalLinkService（业务层）：DOM 改写、data-external 注入、跳转 URL 构建
 *
 * @see \App\Services\ExternalLinkService
 */
class LinkService
{
    /** @var \Framework\Core\Container */
    protected $container;

    /** @var array|null 当前站点所有合法 Host（含 www 兼容），惰性加载 */
    protected $siteHosts;

    /** @var array|null 白名单 Host，惰性加载 */
    protected $whitelistHosts;

    /** @var string|null 站点 URL（含 scheme + host），惰性加载 */
    protected $siteUrl;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;

        // hook app_Services_LinkService_construct_end.php
    }

    /**
     * 判断 URL 是否为站内链接
     *
     * @param string $url
     * @return bool true=站内/false=站外/危险协议
     */
    public function isInternal(string $url): bool
    {
        $trimmedUrl = trim($url);
        if ($trimmedUrl === '') {
            return true;
        }

        // 危险协议 → 视为站外（不应存活，但防御性处理）
        if (preg_match('/^(javascript|data|vbscript|file|about|chrome):/i', $trimmedUrl)) {
            return false;
        }

        // 无 host（相对路径如 /forum/1、./page、#anchor）→ 站内
        $host = parse_url($trimmedUrl, PHP_URL_HOST);
        if (empty($host)) {
            return true;
        }

        $host = strtolower($host);
        $port = parse_url($trimmedUrl, PHP_URL_PORT);
        if (!empty($port)) {
            $host .= ':' . $port;
        }

        // 当前站点域名 或 白名单
        return in_array($host, array_merge($this->getSiteHosts(), $this->getWhitelistHosts()), true);
    }

    /**
     * 获取站点 URL（含协议 + 域名），用于传给前端做站内/站外判断
     * 例如: "https://example.com"
     *
     * @return string
     */
    public function getSiteUrl(): string
    {
        if ($this->siteUrl !== null) {
            return $this->siteUrl;
        }

        $appConfig = $this->getAppConfig();
        $configuredDomain = $appConfig['domain'] ?? '';

        if (!empty($configuredDomain)) {
            // 配置可能带协议也可能不带
            if (preg_match('#^https?://#i', $configuredDomain)) {
                $this->siteUrl = rtrim($configuredDomain, '/');
            } else {
                // 根据当前请求判断协议
                $scheme = $this->getScheme();
                $this->siteUrl = $scheme . '://' . rtrim($configuredDomain, '/');
            }
        } else {
            // 无配置时从当前请求构建
            $scheme = $this->getScheme();
            $host = IpHelper::host();
            $this->siteUrl = $scheme . '://' . ($host ?: 'localhost');
        }

        // hook app_Services_LinkService_getSiteUrl_end.php

        return $this->siteUrl;
    }

    /**
     * 获取当前站点所有合法 Host（含 www 变体）
     *
     * @return array
     */
    public function getSiteHosts(): array
    {
        if ($this->siteHosts !== null) {
            return $this->siteHosts;
        }

        $appConfig = $this->getAppConfig();
        $configuredDomain = $appConfig['domain'] ?? '';

        if (!empty($configuredDomain)) {
            // 从配置域名解析 host（移除协议和路径）
            $host = parse_url($configuredDomain, PHP_URL_HOST);
            if (!empty($host)) {
                $this->siteHosts = $this->expandWwwVariants(strtolower($host));
            }
        }

        // 配置为空时使用当前请求 Host
        if (empty($this->siteHosts)) {
            $httpHost = IpHelper::host();
            if (!empty($httpHost)) {
                $this->siteHosts = $this->expandWwwVariants(strtolower($httpHost));
            }
        }

        $this->siteHosts = array_values(array_unique(array_filter($this->siteHosts ?? [])));

        // hook app_Services_LinkService_getSiteHosts_end.php

        return $this->siteHosts;
    }

    /**
     * 获取外链白名单域名列表
     *
     * 从核心配置 app.external_link_whitelist 读取，逗号分隔。
     *
     * @return array
     */
    public function getWhitelistHosts(): array
    {
        if ($this->whitelistHosts !== null) {
            return $this->whitelistHosts;
        }

        $this->whitelistHosts = [];

        $appConfig = $this->getAppConfig();
        $whitelist = $appConfig['external_link_whitelist'] ?? '';

        if (!empty($whitelist)) {
            $this->whitelistHosts = $this->parseWhitelist($whitelist);
        }

        $this->whitelistHosts = array_values(array_unique(array_filter($this->whitelistHosts)));

        // hook app_Services_LinkService_getWhitelistHosts_end.php

        return $this->whitelistHosts;
    }

    // ========================================================================
    //  内部方法
    // ========================================================================

    /**
     * 获取 appConfig（容器 lazy 加载）
     */
    protected function getAppConfig(): array
    {
        return $this->container->get('appConfig') ?: [];
    }

    /**
     * 获取当前请求协议（http / https）
     */
    protected function getScheme(): string
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
            ? 'https'
            : 'http';
    }

    /**
     * 解析逗号分隔的白名单为 Host 数组
     *
     * @param string $whitelist 逗号分隔的域名 / URL
     * @return array
     */
    protected function parseWhitelist(string $whitelist): array
    {
        $hosts = [];
        foreach (explode(',', $whitelist) as $item) {
            $item = trim($item);
            if (empty($item)) continue;

            // 如果包含协议，提取 host 部分
            if (strpos($item, '://') !== false) {
                $item = parse_url($item, PHP_URL_HOST) ?: $item;
            }

            if (!empty($item)) {
                $hosts[] = strtolower($item);
            }
        }
        return $hosts;
    }

    /**
     * 生成域名及其 www 变体
     * example.com → [example.com, www.example.com]
     * www.example.com → [www.example.com, example.com]
     *
     * @param string $host
     * @return array
     */
    protected function expandWwwVariants(string $host): array
    {
        $variants = [$host];

        if (strpos($host, 'www.') === 0) {
            $variants[] = substr($host, 4);
        } else {
            $variants[] = 'www.' . $host;
        }

        return $variants;
    }
}
