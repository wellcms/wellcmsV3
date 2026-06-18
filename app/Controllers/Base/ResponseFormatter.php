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

    public function __construct(\Framework\Http\Interfaces\ResponseFactoryInterface $responseFactory)
    {
        $this->response = $responseFactory;
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
        // 使用闭包在独立作用域中渲染模板
        $content = (function (array $vars, string $tpl) {
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
                return "Template render error: " . $e->getMessage();
            }
        })($data, $templateFile);

        // 组装并返回 PSR‑7 响应
        $code = isset($data['code']) && $data['code'] > 200 ? (int)$data['code'] : 200;
        $response = $this->response->createResponse($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write((string)$content);
        return $response;
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
