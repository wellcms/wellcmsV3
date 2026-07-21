<?php

declare(strict_types=1);

namespace App\Middleware;

use Framework\Utils\LoggerContext;
use Framework\Http\Interfaces\MiddlewareInterface;
use Framework\Http\Interfaces\RequestHandlerInterface;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Utils\IpHelper;

class LoggingContextMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 审计修订：IpHelper::ip() 使用 try/catch 降级，
        // 避免该中间件（位于 ErrorHandlerMiddleware 之前）抛异常导致 request_id 丢失
        try {
            $ip = IpHelper::ip($request->getServerParams()) ?: '0.0.0.0';
        } catch (\Throwable $e) {
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        LoggerContext::setMultiple([
            'request_id' => $request->getAttribute('request_id') ?: '',
            'ip'         => $ip,
            'method'     => $request->getMethod(),
            'uri'        => (string)$request->getUri(),
        ]);

        try {
            return $handler->handle($request);
        } finally {
            LoggerContext::clear();
        }
    }
}
