<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Middleware;

use Framework\Http\Interfaces\MiddlewareInterface;

class MiddlewareFactory
{
    /**
     * @var \Framework\Core\Container
     */
    protected $container;

    /**
     * @var array
     */
    protected $cache = [];

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param array $middlewareClass
     */
    public function create($middlewareClass, array $params = []): MiddlewareInterface
    {
        if (is_array($middlewareClass)) {
            [$class, $extra] = $middlewareClass;
            $params = array_merge($params, (array)$extra);
        } else {
            $class = $middlewareClass;
        }

        $key = $this->makeCacheKey($class, $params);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (!class_exists($class)) {
            throw new \RuntimeException("Middleware class $class not found");
        }

        $instance = $this->container->get($class, $params);
        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException("Class $class is not a valid MiddlewareInterface");
        }

        return $this->cache[$key] = $instance;
    }

    /**
     * 按参数类型逐一打包，生成不含 Closure 的 key
     */
    protected function makeCacheKey(string $class, array $params): string
    {
        $segments = [$class];

        foreach ($params as $param) {
            if ($param instanceof \Closure) {
                // 闭包：用文件 + 行号当标识
                $ref   = new \ReflectionFunction($param);
                $file  = $ref->getFileName() ?: 'unknown';
                $start = $ref->getStartLine();
                $end   = $ref->getEndLine();
                $segments[] = "closure@{$file}:{$start}-{$end}";
            } elseif (is_scalar($param) || $param === null) {
                // 标量或 null
                $segments[] = (string)$param;
            } elseif (is_object($param)) {
                // 对象：用 spl_object_hash() 区分不同实例
                $segments[] = spl_object_hash($param);
            } elseif (is_array($param)) {
                // 数组：递归哈希
                $segments[] = md5($this->flattenArray($param));
            } else {
                // 其它类型（resource 等），用类型名称
                $segments[] = gettype($param);
            }
        }

        return md5(implode('|', $segments));
    }

    /**
     * 将数组扁平化成字符串（内部也会把闭包转成签名）
     */
    protected function flattenArray(array $arr): string
    {
        $parts = [];
        foreach ($arr as $k => $v) {
            $parts[] = (string)$k;
            if ($v instanceof \Closure) {
                $ref   = new \ReflectionFunction($v);
                $file  = $ref->getFileName() ?: 'unknown';
                $start = $ref->getStartLine();
                $end   = $ref->getEndLine();
                $parts[] = "closure@{$file}:{$start}-{$end}";
            } elseif (is_array($v)) {
                $parts[] = $this->flattenArray($v);
            } elseif (is_object($v)) {
                $parts[] = spl_object_hash($v);
            } else {
                $parts[] = (string)$v;
            }
        }
        // 键值已按顺序追加，直接拼字符串
        return implode('|', $parts);
    }
}
