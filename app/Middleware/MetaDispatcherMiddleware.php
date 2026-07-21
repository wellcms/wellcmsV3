<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

class MetaDispatcherMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \App\Factory\ControllerFactory */
    private $controllerFactory;
    /** @var \App\Meta\MetaRegistry */
    private $metaRegistry;
    /** @var \Framework\Http\Middleware\MiddlewareFactory */
    private $middlewareFactory;

    public function __construct(\App\Factory\ControllerFactory $controllerFactory, \App\Meta\MetaRegistry $metaRegistry, \Framework\Http\Middleware\MiddlewareFactory $middlewareFactory)
    {
        $this->controllerFactory = $controllerFactory;
        $this->metaRegistry = $metaRegistry;
        $this->middlewareFactory = $middlewareFactory;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // 从请求属性中取出匹配结果
        $meta = $request->getAttribute('_route_meta', []);
        $handlerDef = $request->getAttribute('_route_handler');
        $routeMw = $request->getAttribute('_route_middleware', []);

        $stack = [];
        if (!empty($meta)) {
            // 根据 meta 解析动态中间件
            foreach ($meta as $k => $v) {
                if ($mw = $this->metaRegistry->getMiddleware($k, $v)) {
                    $stack[] = $mw;
                }
            }
        }

        if (!empty($routeMw)) {
            // 装配路由自定义中间件
            foreach ($routeMw as $cfg) {
                $stack[] = $this->middlewareFactory->create($cfg['class'], $cfg['params'] ?? []);
            }
        }

        // 封装控制器调用
        $controllerFactory = $this->controllerFactory;
        $callable = function (\Framework\Http\Interfaces\ServerRequestInterface $req) use ($handlerDef, $controllerFactory) {
            if (is_array($handlerDef)) {
                list($class, $method) = $handlerDef;
                $controller = $controllerFactory->create($class);
                $ref = new \ReflectionMethod($controller, $method);

                return $ref->getNumberOfParameters() > 0 ? $controller->{$method}($req) : $controller->{$method}();
            }

            return \call_user_func($handlerDef, $req);
        };

        \Framework\Http\Psr7\RequestStack::push($request);

        // 执行路由级管道，返回最终 Response
        $pipeline = new \Framework\Http\Middleware\Pipeline($stack, new \Framework\Http\Router\RequestHandler\CallableHandler($callable));
        return $pipeline->handle($request);
    }
}
