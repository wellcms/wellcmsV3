<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

/**
 * Token 验证
 */
class TokenMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var array */
    private $appConfig;
    /** @var int */
    private $ttl;
    /** @var \App\Services\Auth\TokenService */
    private $tokenService;

    // 支持传入定位器或函数注入
    public function __construct(\Framework\Core\Container $container, \App\Services\Auth\TokenService $tokenService, int $ttl = 600)
    {
        $this->container = $container;
        $this->tokenService = $tokenService;
        $this->ttl = $ttl;
        $this->appConfig = $container->get('appConfig');
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        try {
            $method = strtoupper($request->getMethod());
            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
                $user = $request->getAttribute('user', []);
                $userId = $user['id'] ?? 0;
                $salt = $user['salt'] ?? '';

                if ($userId > 0) {
                    $token = $params['_csrf_token'] ?? $request->getHeaderLine('X-CSRF-TOKEN') ?: ($params['X-CSRF-TOKEN'] ?? '');
                } else {
                    $token = $params['_token'] ?? $request->getHeaderLine('X-TOKEN') ?: ($params['X-TOKEN'] ?? '');
                    // 访客模式下的 salt 通常为空或特定标识，与 SecurityHelper::decrypt 配合
                }

                $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);

                if (!$this->tokenService->verifyToken($token, $salt, $this->ttl, true)) {
                    // 记录 Token 失败次数以配合行为墙
                    /** @var mixed */
                    $session = $request->getAttribute(\Framework\Session\SessionInterface::class);
                    $sessionId = $session ? $session->getId() : null;
                    if ($sessionId) {
                        $cache = $this->container->get(\Framework\Cache\Interfaces\CacheInterface::class);
                        $tokenFailKey = 'token_fail_count:' . $sessionId;
                        $count = (int)($cache->get($tokenFailKey) ?: 0);
                        $cache->set($tokenFailKey, $count + 1, 3600);
                    }
                    return $messageController->errorMessage('Forbidden', 8);
                }
            }

            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (\defined('DEBUG') && \DEBUG >= 2) throw $e;

            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('Token verification error: ' . $e->getMessage(), 500);
        }
    }
}