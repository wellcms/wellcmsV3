<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Core;

class ExceptionHandler
{
    /** @var \Framework\Core\Container */
    protected $container;

    public function __construct(?\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    /**
     * 将异常转换为结构化响应数据，由调用方根据环境决定如何发射。
     * 保持纯生成器模式，不直接操作 header()/echo，确保在容器未就绪时依然可用。
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    public function handle(\Throwable $e, ?\Framework\Http\Interfaces\ServerRequestInterface $request = null): array
    {
        $this->logException($e);

        if (PHP_SAPI === 'cli') {
            return ['statusCode' => 500, 'headers' => [], 'body' => "Error: " . $e->getMessage() . PHP_EOL];
        }

        $isJson = $this->expectsJson($request);
        $statusCode = ($e instanceof \Framework\Exception\ExceptionInterface) ? $e->getStatusCode() : 500;

        if ($isJson) {
            $body = $this->renderJsonBody($e, $statusCode);
            $contentType = 'application/json; charset=utf-8';
        } else {
            $body = $this->renderHtmlBody($e, $statusCode);
            $contentType = 'text/html; charset=utf-8';
        }

        return [
            'statusCode' => $statusCode,
            'headers'    => ['Content-Type' => $contentType],
            'body'       => $body,
        ];
    }

    protected function expectsJson(?\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
        if (!$request) return false;
        $accept = $request->getHeaderLine('Accept');
        return strpos($accept, 'application/json') !== false || strpos($accept, 'application/javascript') !== false;
    }

    /**
     * 生成 JSON 响应体，统一格式为 {status, code, message, data, timestamp}
     */
    protected function renderJsonBody(\Throwable $e, int $code): string
    {
        $data = [
            'status'    => 'error',
            'code'      => $code,
            'message'   => defined('DEBUG') && DEBUG ? $e->getMessage() : 'Server Error',
            'data'      => defined('DEBUG') && DEBUG ? ['trace' => $e->getTrace()] : [],
            'timestamp' => time(),
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * 生成 HTML 响应体
     */
    protected function renderHtmlBody(\Throwable $e, int $code): string
    {
        if (defined('DEBUG') && DEBUG > 0) {
            $html  = '<h1>App Error</h1>';
            $html .= '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            $html .= '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            return $html;
        }

        $errorFile = defined('APP_PATH') ? APP_PATH . 'app/views/htm/500.htm' : '';
        if ($errorFile && file_exists($errorFile)) {
            ob_start();
            include \App\Core\Compile::include($errorFile);
            return ob_get_clean() ?: '<h1>500 Internal Server Error</h1>';
        }

        return '<h1>500 Internal Server Error</h1>';
    }

    protected function logException(\Throwable $e): void{
        try {
            if ($this->container && is_object($this->container) && method_exists($this->container, 'has') && $this->container->has('logger')) {
                $this->container->get('logger')->error($e->getMessage(), ['exception' => $e]);
            } else {
                error_log($e->getMessage());
            }
        } catch (\Throwable $t) {
            error_log('Failed to log exception: ' . $t->getMessage());
        }
    }
}
