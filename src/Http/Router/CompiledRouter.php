<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Router;

class CompiledRouter
{
    /**
     * Summary of routes
     * @var array
     */
    private $routes = [];

    public function __construct(array $routes)
    {
        foreach ($routes as $route) {
            foreach ($route->getMethods() as $method) {
                $this->routes[$method][] = $route;
            }
        }
    }

    /**
     * 匹配并返回 [handler, vars, meta, middleware] | false
     */
    public function match(string $method, string $path)
    {
        if (!isset($this->routes[$method])) return false;

        // 标准化请求路径，去除首尾多余的斜杠
        $pathSegments = explode('/', trim($path, '/'));

        foreach ($this->routes[$method] as $route) {
            $raw = $route->getPath();  // 原始路由，如 /user/{id}
            // 标准化路由配置
            $routeSegments = explode('/', trim($raw, '/'));
            
            // 段数必须一致，才有继续匹配的可能
            if (count($routeSegments) !== count($pathSegments)) continue;

            $vars = [];
            $matched = true;

            foreach ($routeSegments as $i => $segment) {
                // 判断当前段是否为变量：格式为 {xxx}
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m)) {
                    $vars[$m[1]] = $pathSegments[$i];
                } elseif ($segment !== $pathSegments[$i]) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return [
                    $route->getHandler(),
                    $vars,
                    $route->getMeta(),
                    $route->getMiddleware()
                ];
            }
        }

        return false;
    }

    // 旧的匹配方法，保留以便参考。
    /* public function match(string $method, string $path)
    {
        if (!isset($this->routes[$method])) {
            return false;
        }

        foreach ($this->routes[$method] as $route) {
            $raw = $route->getPath(); // 原始配置
            $pattern = preg_replace('#\{([^/]+)\}#', '(?P<$1>[^/]+)', $raw);

            // root 特判，其他路由保持去尾斜线
            $pattern = $raw === '/' ? '#^\/$#' : '#^' . rtrim($pattern, '/') . '$#';

            if (preg_match($pattern, $path, $matches)) {
                $vars = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) $vars[$k] = $v;
                }
                return [
                    $route->getHandler(),
                    $vars,
                    $route->getMeta(),
                    $route->getMiddleware()
                ];
            }
        }

        return false;
    } */
}
