<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\LinkService;
use Framework\Core\Container;
use Framework\Http\Routing\UrlGeneratorInterface;
use Framework\Utils\SafeHelper;

/**
 * 核心外链安全跳转服务 — 全站 UGC 内容外链统一处理
 *
 * 职责：
 * - process()        统一处理 UGC HTML，注入 data-external 标记 + href 改写为中间跳转页
 * - isEnabled()      读取 config/App.php 的外链跳转总开关（每次调用实时读取，不缓存）
 * - isModuleEnabled() 检查指定模块是否已启用外链处理
 * - isInternal()     委托 LinkService 判断站内/站外
 * - buildRedirectUrl() 构建 /redirect/external?target=... 跳转 URL
 *
 * 与 LinkService 的分工：
 *   LinkService（基础层）：isInternal()、域名解析、白名单管理
 *   ExternalLinkService（业务层）：DOM 改写、data-external 注入、跳转 URL 构建、模块开关
 *
 * 危险协议处理委托 SafeHelper::filterUgcLinks() 作为唯一真实来源。
 *
 * @see \App\Services\LinkService
 * @see \Framework\Utils\SafeHelper
 */
class ExternalLinkService
{
    /** @var Container */
    protected $container;

    /** @var LinkService|null 惰性加载 */
    protected $linkService;

    /** @var UrlGeneratorInterface|null 惰性加载 */
    protected $urlGenerator;

    /** @var array|null 惰性加载 */
    protected $appConfig;

    public function __construct(Container $container)
    {
        $this->container = $container;

        // 注意：不在此处读取或缓存任何配置
        // 注意：不注入 LanguageLoaderInterface（无文案依赖）
        // 注意：不注入 KeyValueService（配置从 appConfig 读取，不再从 KV）

        // hook app_Services_ExternalLinkService_construct_end.php
    }

    /**
     * 处理富文本中的外链
     * 1. 模块白名单检查
     * 2. SafeHelper::filterUgcLinks 完成基础安全过滤（危险协议/白名单/rel/target）
     * 3. DOMDocument 完成 L2 中间跳转页 href 改写
     *
     * @param string $html    UGC 原始 HTML
     * @param array  $context 上下文，支持 ['module' => 'forum']
     * @return string 处理后的 HTML
     */
    public function process(string $html, array $context = []): string
    {
        if (empty($html) || stripos($html, '<a') === false) {
            return $html;
        }

        // hook app_Services_ExternalLinkService_process_start.php
        // 入参：$html (string), $context (array)

        // 模块白名单检查：仅当模块启用时才处理
        if (isset($context['module']) && !$this->isModuleEnabled($context['module'])) {
            return $html;
        }

        // Step 1: 复用主程序工具类完成基础安全属性注入
        $linkService = $this->getLinkService();
        $allSafeHosts = array_merge(
            $linkService->getSiteHosts(),
            $linkService->getWhitelistHosts()
        );
        $html = SafeHelper::filterUgcLinks($html, $allSafeHosts);

        // Step 2: 如果未开启 L2 跳转，到此结束
        if (!$this->isEnabled()) {
            return $html;
        }

        // Step 3: DOMDocument 进行二次处理，仅对外链进行 href 改写
        $result = $this->rewriteExternalAnchors($html);

        // hook app_Services_ExternalLinkService_process_end.php
        // 入参：$html (string), $context (array)

        return $result;
    }

    /**
     * 外链跳转总开关是否开启
     * 每次从 appConfig 读取，不缓存，保证 Swoole 常驻进程下配置变更即时生效
     */
    public function isEnabled(): bool
    {
        return !empty($this->getAppConfig()['external_link_redirect_enabled']);
    }

    /**
     * 指定模块的外链处理是否已启用
     * 检查 external_link_modules 白名单
     */
    public function isModuleEnabled(string $module): bool
    {
        $modules = $this->getAppConfig()['external_link_modules'] ?? [];
        if (!is_array($modules)) {
            $modules = [];
        }
        return in_array($module, $modules, true);
    }

    /**
     * 判断 URL 是否为站内链接（委托 LinkService）
     */
    public function isInternal(string $url): bool
    {
        return $this->getLinkService()->isInternal($url);
    }

    /**
     * 构建中间跳转页 URL
     */
    public function buildRedirectUrl(string $targetUrl): string
    {
        $encoded = rawurlencode(base64_encode($targetUrl));
        return $this->getUrlGenerator()->url('redirect/external', ['target' => $encoded]);
    }

    // ========================================================================
    //  内部方法
    // ========================================================================

    /**
     * 标记需要前端弹窗确认的外链
     * 此时 SafeHelper 已注入 rel/target，本方法仅添加 data-external 标记
     */
    protected function rewriteExternalAnchors(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $internalErrors = libxml_use_internal_errors(true);

        // 通过 XML 编码声明强制 UTF-8，防止 DOMDocument 将中文转为实体
        $wrapped = '<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $html;
        $dom->loadHTML($wrapped, LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($internalErrors);

        $anchors = $dom->getElementsByTagName('a');
        if ($anchors->length === 0) {
            return $html;
        }

        $modified = false;

        for ($i = $anchors->length - 1; $i >= 0; $i--) {
            $a = $anchors->item($i);
            $href = $a->getAttribute('href');

            if (empty($href) || $this->isInternal($href)) {
                continue;
            }

            // 标记 http/https 外链，由前端弹窗确认后新窗口打开
            if (preg_match('#^https?://#i', $href)) {
                $redirectUrl = $this->buildRedirectUrl($href);
                // data-external-dialog 仅存储 URL，前端从 window.wellcms_lang 动态读取文案
                // 向后兼容：旧版存储完整 JSON（含 title/confirm 等），新版仅存 {url}
                $a->setAttribute('href', $redirectUrl);
                $a->setAttribute('data-external', '1');
                $a->setAttribute('data-external-dialog', json_encode(['url' => $href], JSON_UNESCAPED_UNICODE));
                $modified = true;
            }
        }

        if (!$modified) {
            return $html;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

    protected function getLinkService(): LinkService
    {
        if ($this->linkService === null) {
            $this->linkService = $this->container->get(LinkService::class);
        }
        return $this->linkService;
    }

    protected function getUrlGenerator(): UrlGeneratorInterface
    {
        if ($this->urlGenerator === null) {
            $this->urlGenerator = $this->container->get(UrlGeneratorInterface::class);
        }
        return $this->urlGenerator;
    }

    protected function getAppConfig(): array
    {
        if ($this->appConfig === null) {
            $this->appConfig = $this->container->get('appConfig') ?: [];
        }
        return $this->appConfig;
    }
}
