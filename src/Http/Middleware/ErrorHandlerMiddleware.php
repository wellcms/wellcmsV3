<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Middleware;

use Framework\Http\Interfaces\ResponseInterface;

class ErrorHandlerMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;
    /** @var bool */
    private $debug;
    /** @var \Framework\Http\Interfaces\ResponseFactoryInterface */
    private $responseFactory;
    /** @var \Framework\Http\Interfaces\StreamFactoryInterface */
    private $streamFactory;
    private /** @var bool */
    static $registered = false;

    public function __construct(\Framework\Logger\LoggerInterface $logger, \Framework\Http\Interfaces\ResponseFactoryInterface $responseFactory, \Framework\Http\Interfaces\StreamFactoryInterface $streamFactory, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): ResponseInterface
    {
        // 注册错误处理
        if (false === self::$registered) {
            set_error_handler([$this, 'handleError']);
            register_shutdown_function([$this, 'handleShutdown']);
            self::$registered = true;
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $response = $this->handleException($e);
        } finally {
            restore_error_handler();
        }

        return $response;
    }

    /**
     * 将错误转换为异常
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) return false;

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * 处理致命错误
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR];
        if ($error && in_array($error['type'], $fatalTypes, true)) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            $response = $this->handleException($exception, true);
            $this->emitShutdownResponse($response);
        }
    }

    /**
     * 统一异常处理
     */
    private function handleException(\Throwable $e, bool $isFatal = false): ResponseInterface
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e,
            'category'  => $isFatal ? 'fatal' : 'exception',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->createResponseByRequest($e);
    }

    private function createResponseByRequest(\Throwable $e): ResponseInterface
    {
        $request = \Framework\Http\Psr7\RequestStack::getCurrent();
        $accept = $request ? $request->getHeaderLine('Accept') : '';
        $isJson = strpos($accept, 'application/json') !== false ||
            ($request && strpos($request->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') !== false);

        if ($isJson) {
            return $this->createJsonResponse($e);
        }

        return $this->createHtmlResponse($e);
    }

    private function createJsonResponse(\Throwable $e): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(500);
        $payload = [
            'error' => 'Internal Server Error',
            'message' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
        ];
        if ($this->debug) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['sql'] = \Framework\Database\Collector\QueryCollector::getLoggedQueries();
        }

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        return $response->withBody($body)->withHeader('Content-Type', 'application/json');
    }

    private function createHtmlResponse(\Throwable $e): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(500);
        $title = $this->debug ? get_class($e) : 'Internal Server Error';
        $message = htmlspecialchars($e->getMessage());
        $file = $this->debug ? $e->getFile() : '';
        $line = $this->debug ? $e->getLine() : '';
        $trace = $this->debug ? nl2br(htmlspecialchars($e->getTraceAsString())) : '';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <style>
        body { font-family: sans-serif; padding: 20px; line-height: 1.6; color: #333; }
        h1 { color: #d9534f; }
        .info { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px; }
        .trace { font-family: monospace; font-size: 13px; white-space: pre-wrap; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>{$title}</h1>
    <div class="info">
        <p><strong>Message:</strong> {$message}</p>
        {$file} {$line}
    </div>
    <div class="trace">{$trace}</div>
</body>
</html>
HTML;

        $body = $this->streamFactory->createStream($html);
        return $response->withBody($body)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function emitShutdownResponse(ResponseInterface $response): void
    {
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            $swooleResponse = $ctx['swoole_response'] ?? null;
            if ($swooleResponse) {
                $swooleResponse->status($response->getStatusCode());
                foreach ($response->getHeaders() as $name => $values) {
                    $swooleResponse->header($name, implode(', ', $values));
                }
                $swooleResponse->end((string)$response->getBody());
                return;
            }
        }

        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                header("$name: " . implode(', ', $values));
            }
        }
        echo (string)$response->getBody();
    }
}
