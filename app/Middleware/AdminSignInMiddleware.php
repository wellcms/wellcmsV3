<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

class AdminSignInMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // hook app_Middleware_AdminSignInMiddleware_process_start.php
        try {
            $urlGenerator = $this->container->get(\Framework\Http\Routing\UrlGeneratorInterface::class);
            $responseFactory = $this->container->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class);

            // hook app_Middleware_AdminSignInMiddleware_process_before.php

            $user = $request->getAttribute('user');
            if (empty($user)) return $responseFactory->createResponse(302)->withHeader('Location', $urlGenerator->url('auth/signIn'));

            $tokenManager = $this->container->get(\App\Controllers\Admin\Service\TokenManager::class);
            if (false === $tokenManager->adminTokenCheck()) {
                return $responseFactory->createResponse(302)->withHeader('Location', $urlGenerator->url('admin/signIn'));
            }

            // hook app_Middleware_AdminSignInMiddleware_process_end.php

            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (defined('DEBUG') && \DEBUG >= 2) throw $e;

            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('Admin SignIn verification error: ' . $e->getMessage(), 500);
        }
    }
}