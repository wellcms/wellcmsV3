<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

/**
 * CSRF 令牌验证
 */
class CsrfMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var int */
    private $ttl;
    /** @var \App\Services\Auth\TokenService */
    private $tokenService;

    // 支持传入定位器或函数注入
    public function __construct(\Framework\Core\Container $container, \App\Services\Auth\TokenService $tokenService, int $ttl = 1800)
    {
        $this->container = $container;
        $this->tokenService = $tokenService;
        $this->ttl = $ttl;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // hook app_Middleware_CsrfMiddleware_process_start.php
        try {
            $method = strtoupper($request->getMethod());

            // hook app_Middleware_CsrfMiddleware_process_before.php
            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
                $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);

                // hook app_Middleware_CsrfMiddleware_process_center.php

                $user = $request->getAttribute('user');
                if (empty($user)) return $messageController->errorMessage('Forbidden', 8);
                $token = $params['_csrf_token'] ?? $request->getHeaderLine('X-CSRF-TOKEN') ?: ($params['X-CSRF-TOKEN'] ?? '');

                // hook app_Middleware_CsrfMiddleware_process_after.php

                if (!$this->tokenService->verifyToken($token, $user['salt'], $this->ttl, true)) {
                    return $messageController->errorMessage('Forbidden', 8);
                }
            }

            // hook app_Middleware_CsrfMiddleware_process_end.php
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (defined('DEBUG') && DEBUG >= 2) throw $e;

            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('CSRF verification error: ' . $e->getMessage(), 500);
        }
    }
}
