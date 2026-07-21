<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Interfaces;

interface MetaMiddlewareResolverInterface
{
    /** 返回 true 表示该解析器处理此 meta 键 */
    public function supports(string $key, $value): bool;

    /** 基于 meta 生成对应的中间件实例 */
    public function create(string $key, $value): \Framework\Http\Interfaces\MiddlewareInterface;
}
