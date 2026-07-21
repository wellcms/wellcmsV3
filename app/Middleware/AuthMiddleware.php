<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

class AuthMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // hook app_Middleware_AuthMiddleware_process_start.php

        try {
            if (!$request->getAttribute('user')) {
                $urlGenerator = $this->container->get(\Framework\Http\Routing\UrlGeneratorInterface::class);
                $redirectUrl = $urlGenerator->url('auth/signIn');

                // hook app_Middleware_AuthMiddleware_process_before.php

                // 使用响应工厂创建 302 重定向响应
                $responseFactory = $this->container->get(\Framework\Http\Interfaces\ResponseFactoryInterface::class);
                return $responseFactory->createResponse(302)->withHeader('Location', $redirectUrl);

                // 同步/异步弹窗提示
                //$messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
                //return $messageController->errorMessage('Access Denied', 8, $redirectUrl, 0);
            }

            // hook app_Middleware_AuthMiddleware_process_end.php

            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (defined('DEBUG') && \DEBUG >= 2) throw $e;

            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('Auth verification error: ' . $e->getMessage(), 500);
        }
    }
}