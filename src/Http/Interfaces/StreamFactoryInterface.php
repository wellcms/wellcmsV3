<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\StreamInterface;

/**
 * Interface StreamFactoryInterface
 *
 * PSR‑17 流工厂接口
 */
interface StreamFactoryInterface
{
    /**
     * 创建一个可写入字符串的临时 Stream
     *
     * @param string $content
     * @return StreamInterface
     */
    public function createStream(string $content = ''): StreamInterface;

    /**
     * 从文件创建 Stream
     *
     * @param string $filename
     * @param string $mode
     * @return StreamInterface
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;

    /**
     * 从已有资源创建 Stream
     *
     * @param resource $resource
     * @return StreamInterface
     */
    public function createStreamFromResource($resource): StreamInterface;
}
