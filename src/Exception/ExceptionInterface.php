<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Exception;

/**
 * 框架自定义异常标记接口。
 * 统一所有框架抛出的异常类型，便于在上层做统一捕获处理。
 */
interface ExceptionInterface extends \Throwable
{
    /**
     * 获取推荐的 HTTP 状态码
     */
    public function getStatusCode(): int;
}
