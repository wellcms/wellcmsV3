<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\{MessageInterface, UriInterface};

/**
 * Interface RequestInterface
 *
 * 表示客户端发起的 HTTP 请求（PSR‑7）
 */
interface RequestInterface extends MessageInterface
{
    /** 获取请求目标（请求行路径） */
    public function getRequestTarget(): string;

    /**
     * 返回设置新请求目标后的副本
     *
     * @param string $requestTarget
     * @return static
     */
    public function withRequestTarget(string $requestTarget): self;

    /** 获取 HTTP 方法 */
    public function getMethod(): string;

    /**
     * 返回设置新方法后的副本
     *
     * @param string $method
     * @return static
     */
    public function withMethod(string $method): self;

    /** 获取 URI 对象 */
    public function getUri(): UriInterface;

    /**
     * 返回设置新 URI 后的副本
     *
     * @param UriInterface $uri
     * @param bool $preserveHost 是否保留原 Host 头
     * @return static
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): self;
}
