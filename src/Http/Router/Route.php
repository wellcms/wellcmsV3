<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Router;

/**
 * 路由实体：封装方法、路径、处理器及元数据
 */
class Route
{
    /**
     * Summary of methods
     * @var array
     */
    protected $methods;
    /**
     * Summary of path
     * @var string
     */
    protected $path;
    /**
     * Summary of handler
     * @var mixed
     */
    protected $handler;
    /**
     * Summary of meta
     * @var array
     */
    protected $meta        = [];
    /**
     * Summary of middleware
     * @var array
     */
    protected $middleware  = [];

    public function __construct(array $methods, string $path, $handler)
    {
        $this->methods = $methods;
        $this->path    = $path;
        $this->handler = $handler;
    }

    /** 设置或追加路由级元数据 */
    public function setMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    /** 挂载一个中间件类名 */
    public function middleware(string $mwClass, array $params = []): self
    {
        $this->middleware[] = ['class' => $mwClass, 'params' => $params];
        return $this;
    }

    // —— 访问器 —— //

    public function getMethods(): array
    {
        return $this->methods;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    /**
     * @return array
     */
    public function getHandler()
    {
        return $this->handler;
    }
    public function getMeta(): array
    {
        return $this->meta;
    }
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
