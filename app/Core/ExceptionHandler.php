<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Core;

use App\View\Error\DefaultErrorViewModel;
use App\View\Error\ErrorViewModelInterface;
use App\View\Error\ErrorViewRenderer;

class ExceptionHandler
{
    /** @var \Framework\Core\Container|null */
    protected $container;

    /** @var bool */
    protected $debug;

    /** @var array */
    protected $errorConfig;

    /** @var ErrorViewRenderer|null */
    protected $renderer;

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

        $templateFile = $this->resolveErrorTemplate($code);
        if (!$templateFile) {
            return $this->createFallbackHtml($code);
        }

        $view = $this->createViewModel();
        return $this->getRenderer()->render($templateFile, $view);
    }

    /**
     * 惰性获取或创建 ErrorViewRenderer。
     * 优先从容器获取，容器不可用时直接 new 兜底（零依赖）。
     */
    protected function getRenderer(): ErrorViewRenderer
    {
        if ($this->renderer === null) {
            try {
                if ($this->container && $this->container->has(ErrorViewRenderer::class)) {
                    $this->renderer = $this->container->get(ErrorViewRenderer::class);
                }
            } catch (\Throwable $e) {
                error_log('ExceptionHandler::getRenderer fallback: ' . $e->getMessage());
            }
            if ($this->renderer === null) {
                $this->renderer = new ErrorViewRenderer();
            }
        }
        return $this->renderer;
    }

    /**
     * 创建基础 ViewModel（无运行时数据）。
     * 供启动期兜底路径使用。
     */
    private function createViewModel(): ErrorViewModelInterface
    {
        // Container::has() 在检查接口时存在 false positive：
        // 当 ErrorViewModelInterface 文件可被自动加载时，class_exists() 返回 true，
        // 但接口无法用反射实例化，get() 会抛 ReflectionException。
        // 下面的 try/catch 兜底捕获此异常并回退到 DefaultErrorViewModel。
        try {
            if ($this->container && $this->container->has(ErrorViewModelInterface::class)) {
                return $this->container->get(ErrorViewModelInterface::class);
            }
        } catch (\Throwable $e) {
            error_log('ExceptionHandler::createViewModel fallback: ' . $e->getMessage());
        }
        return new DefaultErrorViewModel();
    }

    /**
     * 注入运行时数据到 ViewModel。
     * 供 ErrorResponseBuilder 路径调用，将 build() 构造的 $data 传入 ViewModel。
     *
     * @param array $runtimeData build() 方法构造的错误数据（message, code, debug等）
     */
    private function createViewModelWithRuntimeData(array $runtimeData): ErrorViewModelInterface
    {
        try {
            if ($this->container && $this->container->has(ErrorViewModelInterface::class)) {
                $viewModel = $this->container->get(ErrorViewModelInterface::class);
                if ($viewModel instanceof ConfiguredErrorViewModel) {
                    $viewModel->setRuntimeData($runtimeData);
                }
                return $viewModel;
            }
        } catch (\Throwable $e) {
            error_log('ExceptionHandler::createViewModelWithRuntimeData fallback: ' . $e->getMessage());
        }

        return new DefaultErrorViewModel($runtimeData);
    }

    /**
     * 将 HTTP 状态码解析为错误模板文件名。
     * 安全设计：硬编码路径到核心模板目录，不受 Overwrite 机制影响（错误页面统一由核心提供）。
     *
     * @return string|null 模板绝对路径，null 表示无对应模板
     */
    private function resolveErrorTemplate(int $code): ?string
    {
        $map = [
            500 => '500.htm',
            404 => '404.htm',
            403 => '403.htm',
        ];
        $file = $map[$code] ?? null;
        if (!$file) {
            return null;
        }

        $path = defined('APP_PATH') ? APP_PATH . 'app/views/htm/' . $file : '';
        return ($path && file_exists($path)) ? $path : null;
    }

    /**
     * 无可用模板时的最终兜底 HTML。
     */
    private function createFallbackHtml(int $code): string
    {
        $title = $code >= 500 ? '500 Internal Server Error'
               : ($code === 404 ? '404 Not Found'
               : ($code === 403 ? '403 Forbidden'
               : 'Error'));

        return sprintf(
            '<html><head><title>%s</title><meta charset="utf-8"></head><body style="font-family:sans-serif;padding:2rem;text-align:center"><h1>%d</h1><p>%s</p></body></html>',
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $code,
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
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
