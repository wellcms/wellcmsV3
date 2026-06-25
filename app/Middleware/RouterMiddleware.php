<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

class RouterMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Http\Router\CompiledRouter */
    private $compiledRouter;

    public function __construct(\Framework\Http\Router\CompiledRouter $compiledRouter)
    {
        $this->compiledRouter = $compiledRouter; // 直接注入已初始化的路由实例
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        $method = $request->getMethod();
        $path = (string)$this->pathHandle($request);

        // 路由匹配
        $match = $this->compiledRouter->match($method, $path);
        if (!$match) {
            // 过滤浏览器/调试器常见的噪声请求，避免污染日志 (Noise filter)
            // 静态文件路径（/upload/、/static/、/storage/）应由 Web Server 处理，
            // 落入 PHP 路由时直接 404 静默响应，不记日志
            if (substr($path, -12) === '/favicon.ico'
                || strpos($path, '/.well-known/') === 0
                || strpos($path, '/upload/') === 0
                || strpos($path, '/static/') === 0
                || strpos($path, '/storage/') === 0
            ) {
                return new \Framework\Http\Response(404);
            }
            throw new \Framework\Exception\Http\NotFoundException("Route not found: $path");
        }

        list($handlerDef, $vars, $meta, $routeMw) = $match;

        // 注入 URI 参数到属性
        if (!empty($vars)) {
            foreach ($vars as $k => $v) {
                $request = $request->withAttribute($k, $v);
            }
        }

        // 注入路由结果到属性，供下游使用
        $request = $request
            ->withAttribute('_route_meta', $meta)
            ->withAttribute('_route_handler', $handlerDef)
            ->withAttribute('_route_middleware', $routeMw);

        // 继续执行下一个中间件
        return $handler->handle($request);
    }

    /**
     * /?api=true
     * /?user-home.html&api=true
     * /user-home.html?api=true
     * /user/home.html?api=true
     * /user/home/?api=true
     */
    private function pathHandle(\Framework\Http\Interfaces\ServerRequestInterface $request): string
    {
        $path = '';
        if (!empty($request->getAttribute('route_params'))) {
            $path = implode('/', $request->getAttribute('route_params'));
        }

        return '/' . $path;
    }
}
