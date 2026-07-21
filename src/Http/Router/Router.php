<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Router;

class Router
{
    /**
     * Summary of routes
     * @var array
     */
    protected static $routes = [];
    /**
     * Summary of groupStack
     * @var array
     */
    protected static $groupStack = [];

    /**
     * 定义 GET 路由
     * @param callable $handler
     */
    public static function get(string $path, $handler, array $meta = [], array $middleware = []): void{
        self::addRoute('GET', $path, $handler, $meta, $middleware);
    }

    /**
     * 定义 POST 路由
     * @param callable $handler
     */
    public static function post(string $path, $handler, array $meta = [], array $middleware = []): void{
        self::addRoute('POST', $path, $handler, $meta, $middleware);
    }

    /**
     * 路由分组
     * @param array $attributes ['prefix' => '/admin', 'middleware' => [], 'meta' => []]
     * @param \Closure $callback
     */
    public static function group(array $attributes, \Closure $callback): void{
        self::$groupStack[] = $attributes;
        $callback();
        array_pop(self::$groupStack);
    }

    /**
     * @param callable $handler
     */
    protected static function addRoute(string $method, string $path, $handler, array $meta, array $middleware): void{
        $prefix = '';
        $groupMeta = [];
        $groupMiddleware = [];

        foreach (self::$groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
            if (isset($group['meta'])) {
                $groupMeta = array_merge($groupMeta, (array)$group['meta']);
            }
            if (isset($group['middleware'])) {
                $groupMiddleware = array_merge($groupMiddleware, (array)$group['middleware']);
            }
        }

        $path = $prefix . '/' . trim($path, '/');
        $path = $path === '/' ? '/' : $path; // 根路径修正

        // 构建兼容旧格式的键名，需包含 HTTP 方法以区分同路径多方法路由
        $key = $method . ' ' . $path;

        // 存储结构需适配原 Routes.php 返回的格式
        self::$routes[$key] = [
            'methods' => [$method],
            'path' => $path,
            'handler' => $handler,
            'middleware' => array_merge($groupMiddleware, $middleware),
            'meta' => array_merge($groupMeta, $meta)
        ];
    }

    public static function getRoutes(): array
    {
        return self::$routes;
    }
}
