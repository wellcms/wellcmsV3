<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\UriInterface;

/**
 * Interface UriFactoryInterface
 *
 * PSR‑17 URI 工厂接口
 */
interface UriFactoryInterface
{
    /**
     * 创建一个新的 URI
     *
     * @param string $uri URI 字符串
     * @return UriInterface
     */
    public function createUri(string $uri = ''): UriInterface;
}
