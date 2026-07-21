<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Base;

use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};

class ResponseFormatter
{
    /** @var \Framework\Core\Container|null */
    protected $container;
    /** @var \Framework\Http\Interfaces\ResponseFactoryInterface */
    protected $response;
    /** @var array */
    protected $templateErrorPolicy;

    public function __construct(
        \Framework\Http\Interfaces\ResponseFactoryInterface $responseFactory,
        ?\Framework\Core\Container $container = null,
        array $templateErrorPolicy = []
    ) {
        $this->response = $responseFactory;
        $this->container = $container;
        $this->templateErrorPolicy = $templateErrorPolicy;
    }

    /**
     * 入口：根据 $request 决定返回 JSON 还是渲染 HTML
     *
     * @param array  $data 原始数据（包含 code, message, status, success, url, delay, modal, title）
     * @param string $templateFile HTML 模板文件绝对路径（.htm）
     * @param ServerRequestInterface|null $request 当前请求对象
     * @return ResponseInterface
     */
    public function createFormatter(array $data, string $templateFile = '', ?ServerRequestInterface $request = null): ResponseInterface
    {
        $api = false;
        if ($request) {
            $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());

            // 使用 PSR-7 标准方法获取头部，兼容 FPM 和 Swoole
            $httpXRequestedWith = $request->getHeaderLine('X-Requested-With');
            $accept = $request->getHeaderLine('Accept');
            $meta = $request->getAttribute('_route_meta', []);

            $api = (strtolower(trim($httpXRequestedWith)) === 'xmlhttprequest')
                || (isset($params['api']) && $params['api'])
                || strpos(strtolower($accept), 'application/json') !== false
                || (!empty($meta['api']));
        }

        if ($api) return $this->jsonResponseFormat($data);

        return $this->htmlResponseFormat($data, $templateFile);
    }

    /**
     * 渲染 HTML 响应
     */
    public function htmlResponseFormat(array $data, string $templateFile): ResponseInterface
    {
        $policy = $this->templateErrorPolicy;
        $renderError = null;

        $content = (function (array $vars, string $tpl) use ($policy, &$renderError) {
            // 生成一个简单的“视图容器”支持
            $view = new class($vars) {
                private $data = [];
                private $cache = [];

                public function __construct(array $data)
                {
                    $this->data = $data;
                }

                // 支持点分多级访问
                public function get(string $key, $default = null)
                {
                    $cacheKey = 'view-get-' . $key;
                    if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

                    // 直接访问一级键（轻量路径）
                    if (false === strpos($key, '.')) {
                        $this->cache[$cacheKey] = isset($this->data[$key]) ? $this->data[$key] : $default;
                        return $this->cache[$cacheKey];
                    }

                    // 多级键走完整解析逻辑
                    $this->cache[$cacheKey] = $this->resolveNestedKey($key, $this->data) ?? $default;

                    return $this->cache[$cacheKey];
                }

                // 安全输出方法支持多级访问
                public function e(string $key, $default = '')
                {
                    $cacheKey = 'view-e-' . $key;
                    if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

                    if (false === strpos($key, '.')) {
                        $item = isset($this->data[$key]) ? $this->data[$key] : $default;
                    } else {
                        $item = $this->resolveNestedKey($key, $this->data) ?? $default;
                    }

                    $this->cache[$cacheKey] = htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');

                    return $this->cache[$cacheKey];
                }

                // 原始输出支持多级访问
                public function raw(string $key, $default = '')
                {
                    $cacheKey = 'view-raw-' . $key;
                    if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

                    if (false === strpos($key, '.')) {
                        $this->cache[$cacheKey] = isset($this->data[$key]) ? $this->data[$key] : $default;
                        return $this->cache[$cacheKey];
                    }

                    $this->cache[$cacheKey] = $this->resolveNestedKey($key, $this->data);
                    return empty($this->cache[$cacheKey]) ? $default : $this->cache[$cacheKey];
                }

                // 递归解析点分键，多级键走完整解析逻辑
                private function resolveNestedKey(string $key, array $data)
                {
                    if (isset($this->cache[$key])) return $this->cache[$key];

                    $keys = explode('.', $key);
                    // 安全阈值
                    if (count($keys) > 10) return null;

                    $keys = explode('.', $key);
                    $current = $data;

                    foreach ($keys as $segment) {
                        if (!is_array($current) || !array_key_exists($segment, $current)) return null;
                        $current = $current[$segment];
                    }

                    $this->cache[$key] = $current;
                    return $current;
                }

                // 支持迭代访问
                public function iterate(string $key)
                {
                    $data = $this->get($key) ?? [];
                    foreach ($data as $k => $v) yield $k => new self($v);
                }

                // 魔术方法支持直接访问一级键，对高频访问的一级键提供快速通道
                public function __get(string $name)
                {
                    return new self(isset($this->data[$name]) ? $this->data[$name] : []);
                }
            };

            try {
                ob_start();
                include \App\Core\Compile::include($tpl);
                return ob_get_clean();
            } catch (\Throwable $e) {
                $renderError = $e;

                if (!empty($policy['log_render_errors'])) {
                    $this->logTemplateError($tpl, $e);
                }

                return $this->buildTemplateErrorMessage($e, $policy);
            }
        })($data, $templateFile);

        // 确定状态码
        $code = 200;
        if ($renderError !== null && !empty($policy['error_status_code'])) {
            $code = (int) $policy['error_status_code'];
        } elseif (isset($data['code']) && $data['code'] > 200) {
            $code = (int)$data['code'];
        }

        $response = $this->response->createResponse($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write((string) $content);
        return $response;
    }

    protected function logTemplateError(string $tpl, \Throwable $e): void
    {
        try {
            $context = [
                'template' => \App\Utils\PathHelper::relative($tpl),
                'file' => \App\Utils\PathHelper::relative($e->getFile()),
                'line' => $e->getLine(),
                'type' => get_class($e),
            ];

            // 生产环境 DEBUG=0 时 Compile::include 走 I/O 冻结，二次调用成本可控
            $compiled = method_exists(\App\Core\Compile::class, 'include')
                ? \App\Core\Compile::include($tpl)
                : $tpl;
            if ($compiled !== $tpl) {
                $context['compiled'] = \App\Utils\PathHelper::relative($compiled);
            }

            if ($this->container && method_exists($this->container, 'has') && $this->container->has('logger')) {
                $this->container->get('logger')->error('Template render error: ' . $e->getMessage(), $context);
            } else {
                error_log('Template render error: ' . $e->getMessage() . ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $t) {
            error_log('Failed to log template error: ' . $t->getMessage());
        }
    }

    protected function buildTemplateErrorMessage(\Throwable $e, array $policy): string
    {
        if (defined('DEBUG') && \DEBUG > 0) {
            return '<h1>Template Render Error</h1>'
                . '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><strong>File:</strong> ' . htmlspecialchars(\App\Utils\PathHelper::relative($e->getFile()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' : ' . $e->getLine() . '</p>'
                . '<pre>' . htmlspecialchars(\App\Utils\PathHelper::relativeTraceAsString($e), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }

        if (!empty($policy['show_public_message'])) {
            return '<h1>Internal Server Error</h1><p>The page could not be rendered. Please try again later.</p>';
        }

        return '';
    }

    /**
     * 输出 JSON 响应
     */
    public function jsonResponseFormat(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) {
            $error = json_last_error_msg();
            error_log("ResponseFormatter JSON Encode Error: " . $error . " | Data: " . substr(print_r($data, true), 0, 1000));
            $json = json_encode(['code' => 500, 'message' => 'Internal JSON Error: ' . $error], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }
        $stream = \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStream((string)$json);
        return $this->response->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8')->withBody($stream);
    }
}