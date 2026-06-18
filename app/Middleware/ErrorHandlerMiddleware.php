<?php

declare(strict_types=1);

namespace App\Middleware;

use Framework\Http\Interfaces\ResponseInterface;

/**
 * 统一错误处理中间件 (Unified Error Handler)
 * 整合了 PHP 原生错误处理、致命错误捕获、以及应用层业务异常分发。
 */
class ErrorHandlerMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var bool */
    protected $debug;
    /** @var bool */
    private static $registered = false;

    public function __construct(\Framework\Core\Container $container, bool $debug = false)
    {
        $this->container = $container;
        $this->debug = $debug;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. 注册底层错误句柄 (只需执行一次)
        if (false === self::$registered) {
            set_error_handler([$this, 'handleRawError']);
            register_shutdown_function([$this, 'handleShutdown']);
            self::$registered = true;
        }

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, $request);
        } finally {
            // 在常规请求结束时恢复，但在 Swoole 环境下需谨慎，通常建议由 Kernel 统一管理
            // restore_error_handler();
        }
    }

    /**
     * 将 PHP 原生 Error (Warning/Notice) 转换为异常
     */
    public function handleRawError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) return false;
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * 处理致命错误 (Shutdown)
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR];
        if ($error && in_array($error['type'], $fatalTypes, true)) {
            $e = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            // 对于致命错误，我们无法返回常规 Response，尝试直接输出简易 JSON
            $this->emitFatalResponse($e);
        }
    }

    /**
     * 核心异常处理逻辑
     */
    protected function handleThrowable(\Throwable $e, \Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 1. 记录日志 (Infra 级别记录 Critical，业务端记录 Warning)
        $this->logThrowable($e);

        // 2. 构造基础响应数据 (兼容 BaseController 格式)
        $responseData = [
            'status'    => 'error',
            'code'      => $e->getCode(),
            'message'   => $e->getMessage(),
            'success'   => false,
            'timestamp' => time(),
        ];

        // 获取推荐状态码
        $statusCode = ($e instanceof \Framework\Exception\ExceptionInterface) ? $e->getStatusCode() : 500;

        // 特殊处理：验证异常
        if ($e instanceof \Framework\Exception\ValidationException) {
            $responseData['errors'] = $e->getErrors();
        }

        // 调试模式下增加堆栈信息与 SQL 轨迹
        if ($this->debug) {
            $responseData['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'sql' => \Framework\Database\Collector\QueryCollector::getLoggedQueries(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        // 3. 智能响应格式化 (尝试利用 ResponseFormatter 渲染主题)
        try {
            if ($this->container->has(\App\Controllers\Base\ResponseFormatter::class)) {
                $formatter = $this->container->get(\App\Controllers\Base\ResponseFormatter::class);
                
                // 构造 BaseController 样式的数据包，包含 UI 元素
                $fullData = array_merge($responseData, [
                    'url' => '', // 默认不跳转
                    'delay' => 3,
                    'data' => [
                        'title' => 'System Notice',
                        'keywords' => 'Notice',
                        'description' => 'Operation Notice',
                        'redirect' => null,
                        'modal' => 1,
                    ]
                ]);

                // 获取 message 模板路径
                $templatePath = '';
                if ($this->container->has(\App\Controllers\Base\TemplateManager::class)) {
                    $templatePath = $this->container->get(\App\Controllers\Base\TemplateManager::class)->template(false, 'message', '', $request);
                }

                // createFormatter 会自动检测请求头并决定 JSON 或 HTML
                $response = $formatter->createFormatter($fullData, $templatePath, $request);
                $response = $this->addExceptionHeaders($response, $e);
                return $response;
            }
        } catch (\Throwable $internalError) {
            // 格式化器自身可能因配置缺失或模板丢失报错，记录并走兜底
            error_log("ErrorHandlerMiddleware Formatter Error: " . $internalError->getMessage());
        }

        // 4. 熔断降级逻辑 (Formatter 失败或不可用)
        if ($this->isApiRequest($request)) {
            $response = $this->createJsonResponse($responseData, $statusCode);
        } else {
            $response = $this->createSimpleHtmlResponse($responseData, $statusCode);
        }

        $response = $this->addExceptionHeaders($response, $e);
        return $response;
    }

    /**
     * 为响应添加异常携带的自定义 headers
     *
     * 支持 RateLimitException 等携带 getRateLimitHeaders() 的异常
     */
    protected function addExceptionHeaders(ResponseInterface $response, \Throwable $e): ResponseInterface
    {
        if (method_exists($e, 'getRateLimitHeaders')) {
            foreach ($e->getRateLimitHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }

    /**
     * 判断是否为 API/AJAX 请求 (复用 Formatter 识别逻辑)
     */
    private function isApiRequest(\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
        $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
        $httpXRequestedWith = $request->getServerParams()['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept = $request->getServerParams()['HTTP_ACCEPT'] ?? '';
        
        return ($httpXRequestedWith && strtolower(trim($httpXRequestedWith)) == 'xmlhttprequest') 
            || (isset($params['api']) && $params['api']) 
            || strpos(strtolower($accept), 'application/json') !== false;
    }

    /**
     * 极简 HTML 兜底响应
     */
    protected function createSimpleHtmlResponse(array $data, int $statusCode): ResponseInterface
    {
        $response = new \Framework\Http\Response($statusCode);
        $html = "<html><head><title>Operation Notice</title><meta charset='utf-8'></head>";
        $html .= "<body style='font-family:sans-serif;padding:2rem;background:#f8f9fa;'>";
        $html .= "<div style='max-width:600px;margin:0 auto;background:#fff;padding:2rem;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.1);'>";
        $html .= "<h2 style='color:#dc3545;margin-top:0;'>Notice</h2>";
        $html .= "<p style='font-size:1.1rem;'>" . htmlspecialchars($data['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        
        if ($this->debug && isset($data['debug'])) {
            $html .= "<hr style='border:0;border-top:1px solid #eee;margin:2rem 0;'>";
            $html .= "<pre style='background:#f4f4f4;padding:1rem;overflow:auto;font-size:0.9rem;'>";
            $html .= htmlspecialchars($data['debug']['exception'] . "\n" . implode("\n", $data['debug']['trace']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "</pre>";
        }
        
        $html .= "</div></body></html>";
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    protected function logThrowable(\Throwable $e): void
    {
        $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
        $level = ($e instanceof \Framework\Exception\ExceptionInterface && $e->getStatusCode() < 500) ? 'info' : 'error';
        
        $logger->log($level, $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ]);
    }

    protected function createJsonResponse(array $data, int $statusCode): ResponseInterface
    {
        $response = new \Framework\Http\Response($statusCode);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->debug ? JSON_PRETTY_PRINT : 0) | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * 致命错误紧急输出 (适配 Swoole 与 FPM)
     */
    private function emitFatalResponse(\Throwable $e): void
    {
        $payload = [
            'status'  => 'error',
            'code'    => 500,
            'message' => 'Fatal Error: ' . $e->getMessage()
        ];
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Swoole 环境：优先写入协程上下文中的 Response 对象
        if (\extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            $swooleResponse = $ctx['swoole_response'] ?? null;
            if ($swooleResponse && is_a($swooleResponse, '\\Swoole\\Http\\Response')) {
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->status(500);
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->header('Content-Type', 'application/json; charset=utf-8');
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->end($json);
                return;
            }
        }

        // FPM / CLI fallback
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo $json;
    }
}
