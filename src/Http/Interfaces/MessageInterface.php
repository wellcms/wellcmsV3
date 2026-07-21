<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\StreamInterface;

/**
 * Interface MessageInterface
 *
 * 定义所有 HTTP 消息的基础方法（遵循 PSR‑7）
 */
interface MessageInterface
{
    /** 获取协议版本，例如 "1.1" */
    public function getProtocolVersion(): string;

    /**
     * 返回指定协议版本的副本
     *
     * @param string $version 协议版本
     * @return static
     */
    public function withProtocolVersion(string $version): self;

    /** 获取所有头部及其值 */
    public function getHeaders(): array;

    /**
     * 判断指定头部是否存在
     *
     * @param string $name 头部名称
     */
    public function hasHeader(string $name): bool;

    /**
     * 获取指定头部的所有值（数组）
     *
     * @param string $name 头部名称
     * @return string[]
     */
    public function getHeader(string $name): array;

    /**
     * 获取指定头部的逗号分隔值
     *
     * @param string $name 头部名称
     */
    public function getHeaderLine(string $name): string;

    /**
     * 返回添加或替换某头部后的副本
     *
     * @param string $name  头部名称
     * @param string|string[] $value 头部值
     * @return static
     */
    public function withHeader(string $name, $value): self;

    /**
     * 返回附加头部值后的副本
     *
     * @param string $name 头部名称
     * @param string|string[] $value 新增值
     * @return static
     */
    public function withAddedHeader(string $name, $value): self;

    /**
     * 返回移除指定头部后的副本
     *
     * @param string $name 头部名称
     * @return static
     */
    public function withoutHeader(string $name): self;

    /** 获取消息体流 */
    public function getBody(): StreamInterface;

    /**
     * 返回设置新消息体后的副本
     *
     * @param StreamInterface $body 消息体流
     * @return static
     */
    public function withBody(StreamInterface $body): self;
}
