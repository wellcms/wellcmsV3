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

    /** @var array */
    protected $errorConfig;

    /** @var \App\Services\ErrorResponseBuilder|null */
    protected $errorBuilder;

    /** @var bool */
    private static $registered = false;

    /** @var bool shutdown 阶段标记（此标志存活于整个进程生命周期） */
    private static $shuttingDown = false;

    public function __construct(\Framework\Core\Container $container, bool $debug = false, array $errorConfig = [])
    {
        $this->container = $container;
        $this->debug = $debug;
        $this->errorConfig = $errorConfig;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): ResponseInterface
    {
        // 重置 shutdown 标志（重要：Swoole 模式下进程复用，跨请求必须重置）
        self::$shuttingDown = false;

        if (false === self::$registered) {
            set_error_handler([$this, 'handleRawError']);

            // 注册 shutdown-preamble：在 handleShutdown 之前设置标志
            // 这样后续任何 shutdown 函数中的错误都能检测到
            register_shutdown_function(function () {
                self::$shuttingDown = true;
            });

            register_shutdown_function([$this, 'handleShutdown']);
            self::$registered = true;
        }

        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, $request);
        }
    }

    /**
     * 将 PHP 原生 Error (Warning/Notice) 转换为异常
     */
    public function handleRawError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) return false;

        // shutdown 阶段：不抛异常，直接 error_log 降级
        // 理由：shutdown 中 uncaught exception = Fatal Error，会破坏正常响应
        if (self::$shuttingDown) {
            error_log(sprintf(
                'Shutdown suppressed: [%d] %s in %s:%d',
                $severity, $message, $file, $line
            ));
            return true;
        }

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
     * 审计修订：增加 ErrorResponseBuilder 构造失败的降级路径。
     */
    protected function handleThrowable(\Throwable $e, \Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $this->logThrowable($e);

        // 内层中间件（RequestProcessor/Language/Session）的 finally 可能已经 pop 了 RequestStack，
        // 但 ErrorResponseBuilder 创建 ErrorController/MessageController 时依赖 RequestStack::getCurrent()。
        // 因此错误处理期间需要确保 RequestStack 中有当前请求。
        $needPush = \Framework\Http\Psr7\RequestStack::getCurrent() === null;
        if ($needPush) {
            \Framework\Http\Psr7\RequestStack::push($request);
        }

        try {
            $builder = $this->getErrorBuilder();
            $response = $builder->build($e, $request);
        } catch (\Throwable $builderError) {
            // Builder 自身异常：记录并降级到最简响应
            try {
                $this->logInternalError('ErrorResponseBuilder failed, using fallback', $builderError);
            } catch (\Throwable $logError) {
                error_log('ErrorResponseBuilder + log both failed: ' . $logError->getMessage());
            }

            $response = $this->isApiRequest($request)
                ? $this->createJsonResponse([
                    'status'    => 'error',
                    'code'      => 500,
                    'message'   => $this->debug ? $e->getMessage() : 'Internal Server Error',
                    'timestamp' => time(),
                ], 500)
                : $this->createSimpleHtmlResponse([
                    'status'    => 'error',
                    'code'      => 500,
                    'message'   => $this->debug ? $e->getMessage() : 'Internal Server Error',
                    'timestamp' => time(),
                ], 500);
        } finally {
            if ($needPush) {
                \Framework\Http\Psr7\RequestStack::pop();
            }
        }

        $response = $this->addExceptionHeaders($response, $e);
        return $response;
    }

    protected function getErrorBuilder(): \App\Services\ErrorResponseBuilder
    {
        if ($this->errorBuilder === null) {
            $this->errorBuilder = new \App\Services\ErrorResponseBuilder(
                $this->container,
                $this->debug,
                $this->errorConfig
            );
        }
        return $this->errorBuilder;
    }

    protected function logThrowable(\Throwable $e): void
    {
        // HTTP 状态码异常由框架直接处理响应，不记录日志
        if ($e instanceof \Framework\Exception\HttpException) {
            return;
        }
        try {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $level = ($e instanceof \Framework\Exception\ExceptionInterface && $e->getStatusCode() < 500)
                ? 'warning'
                : 'error';

            $context = [
                'file' => \App\Utils\PathHelper::relative($e->getFile()),
                'line' => $e->getLine(),
                'type' => \get_class($e),
            ];

            if (\defined('LOG_ABSOLUTE_PATH') && \LOG_ABSOLUTE_PATH) {
                $context['absolute_file'] = $e->getFile();
            }

            $logger->log($level, $e->getMessage(), $context);
        } catch (\Throwable $t) {
            error_log('Failed to log throwable: ' . $t->getMessage());
        }
    }

    /**
     * 审计修订：统一 API 判定逻辑，与 ErrorResponseBuilder::isApiRequest() 完全一致。
     */
    private function isApiRequest(\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
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
     * 极简 HTML 兜底响应
     */
    protected function createSimpleHtmlResponse(array $data, int $statusCode): ResponseInterface
    {
        $response = new \Framework\Http\Response($statusCode);
        $html = "<html><head><title>Operation Notice</title><meta charset='utf-8'></head>";
        $html .= "<body style='font-family:sans-serif;padding:2rem;background:#f8f9fa;'>";
        $html .= "<div style='max-width:1024px;margin:0 auto;background:#fff;padding:2rem;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.1);'>";
        $html .= "<h2 style='color:#dc3545;margin-top:0;'>Notice</h2>";
        $html .= "<p style='font-size:1.1rem;'>" . htmlspecialchars($data['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";

        if ($this->debug && isset($data['debug'])) {
            $html .= "<hr style='border:0;border-top:1px solid #eee;margin:2rem 0;'>";
            $html .= "<pre style='background:#f4f4f4;padding:1rem;overflow:auto;font-size:0.9rem;'>";

            $debugText = ($data['debug']['exception'] ?? '') . "\n"
                . ($data['debug']['file'] ?? '') . ' : ' . ($data['debug']['line'] ?? '') . "\n\n"
                . json_encode($data['debug']['trace'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $html .= htmlspecialchars($debugText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "</pre>";
        }

        $html .= "</div></body></html>";

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 记录格式化器内部错误，优先使用主程序 Logger，失败时降级到 error_log
     */
    protected function logInternalError(string $message, \Throwable $e): void
    {
        try {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error($message . ': ' . $e->getMessage(), [
                'file' => \App\Utils\PathHelper::relative($e->getFile()),
                'line' => $e->getLine(),
                'type' => \get_class($e),
            ]);
        } catch (\Throwable $t) {
            error_log($message . ' fallback: ' . $t->getMessage());
        }
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