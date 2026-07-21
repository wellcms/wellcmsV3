<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Router\RequestHandler;

/**
 * 将任意可调用转换为 RequestHandlerInterface
 */
class CallableHandler implements \Framework\Http\Interfaces\RequestHandlerInterface
{
    /** @var callable */
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function handle(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface
    {
        // 调用原始闭包，返回 ResponseInterface
        return \call_user_func($this->callable, $request);
    }
}
