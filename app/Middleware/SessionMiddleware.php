<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

/**
 * 会话中间件
 * 负责在请求生命周期内初始化 Session 对象并注入 Request 属性
 */
class SessionMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \App\Session\Service\SessionManager */
    private $sessionManager;

    public function __construct(\App\Session\Service\SessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // hook app_Middleware_SessionMiddleware_process_start.php

        // 1. 获取 Session 对象 (根据环境自动处理 session_start 或手动加载)
        $session = $this->sessionManager->startSession($request);

        // hook app_Middleware_SessionMiddleware_process_before.php

        // 2. 将会话对象注入请求属性，供后续服务注入使用
        $request = $request->withAttribute(\Framework\Session\SessionInterface::class, $session);

        // hook app_Middleware_SessionMiddleware_process_after.php
        \Framework\Http\Psr7\RequestStack::push($request);
        try {
            // 执行后续中间件及业务逻辑
            $response = $handler->handle($request);
        } finally {
            \Framework\Http\Psr7\RequestStack::pop();
        }

        // hook app_Middleware_SessionMiddleware_process_end.php

        // 3. 状态持久化 (根据环境自动处理 session_write_close 或手动写入)
        $this->sessionManager->saveSession($session);

        return $response;
    }
}
