<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Core;

class ExceptionHandler
{
    /** @var \Framework\Core\Container|null */
    protected $container;

    /** @var bool */
    protected $debug;

    /** @var array */
    protected $errorConfig;

    public function __construct(?\Framework\Core\Container $container, bool $debug = false, array $errorConfig = [])
    {
        $this->container = $container;
        $this->debug = $debug;
        $this->errorConfig = $errorConfig;
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

    /**
     * 审计修订：统一 API 判定逻辑。
     * 与 ErrorResponseBuilder::isApiRequest() 和 ErrorHandlerMiddleware::isApiRequest() 完全一致。
     */
    protected function expectsJson(?\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
        if (!$request) return false;

        $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
        $xrw = $request->getHeaderLine('X-Requested-With');
        $accept = $request->getHeaderLine('Accept');
        $meta = $request->getAttribute('_route_meta', []);

        return ($xrw && strtolower(trim($xrw)) === 'xmlhttprequest')
            || (!empty($params['api']))
            || strpos(strtolower($accept), 'application/json') !== false
            || strpos(strtolower($accept), 'application/javascript') !== false
            || (!empty($meta['api']));
    }

    /**
     * 生成 JSON 响应体，统一格式为 {status, code, message, data, timestamp}
     * 审计修订：移除布尔型 success 字段，增加 json_encode 失败检测
     */
    protected function renderJsonBody(\Throwable $e, int $code): string
    {
        $data = [
            'status'    => 'error',
            'code'      => $code,
            'message'   => $this->debug ? $e->getMessage() : 'Server Error',
            'data'      => $this->debug
                ? ['trace' => \App\Utils\PathHelper::relativeTrace($e->getTrace())]
                : [],
            'timestamp' => time(),
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($json === false) {
            error_log('ExceptionHandler JSON Encode Error: ' . json_last_error_msg());
            $json = json_encode([
                'status'    => 'error',
                'code'      => 500,
                'message'   => 'Internal JSON Error',
                'data'      => [],
                'timestamp' => time(),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }

        return $json;
    }

    /**
     * 生成 HTML 响应体
     */
    protected function renderHtmlBody(\Throwable $e, int $code): string
    {
        if ($this->debug) {
            $html  = '<h1>App Error</h1>';
            $html .= '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            $html .= '<p><strong>File:</strong> ' . htmlspecialchars(\App\Utils\PathHelper::relative($e->getFile()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                  . ' : ' . $e->getLine() . '</p>';
            $html .= '<pre>' . htmlspecialchars(\App\Utils\PathHelper::relativeTraceAsString($e), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            return $html;
        }

        $errorFile = defined('APP_PATH') ? APP_PATH . 'app/views/htm/500.htm' : '';
        if ($errorFile && file_exists($errorFile)) {
            ob_start();
            try {
                include \App\Core\Compile::include($errorFile);
                $body = ob_get_clean() ?: '<h1>500 Internal Server Error</h1>';
            } catch (\Throwable $renderError) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                error_log('ExceptionHandler render 500.htm failed: ' . $renderError->getMessage());
                $body = '<h1>500 Internal Server Error</h1>';
            }
            return $body;
        }

        return '<h1>500 Internal Server Error</h1>';
    }

    protected function logException(\Throwable $e): void
    {
        try {
            $context = array_merge(\Framework\Utils\LoggerContext::all(), [
                'exception' => \get_class($e),
                'file'      => \App\Utils\PathHelper::relative($e->getFile()),
                'line'      => $e->getLine(),
                'trace'     => \App\Utils\PathHelper::relativeTrace($e->getTrace()),
            ]);

            if (\defined('LOG_ABSOLUTE_PATH') && LOG_ABSOLUTE_PATH) {
                $context['absolute_file'] = $e->getFile();
            }

            if ($this->container && \is_object($this->container) && \method_exists($this->container, 'has') && $this->container->has(\Framework\Logger\LoggerInterface::class)) {
                $this->container->get(\Framework\Logger\LoggerInterface::class)->error($e->getMessage(), $context);
            } else {
                error_log($e->getMessage() . ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $t) {
            error_log('Failed to log exception: ' . $t->getMessage());
        }
    }
}
