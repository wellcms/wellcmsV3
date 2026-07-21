<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\RequestInterface;

/**
 * Interface ServerRequestInterface
 *
 * 表示服务器接收到的 HTTP 请求（PSR‑7 扩展）
 */
interface ServerRequestInterface extends RequestInterface
{
    /** 获取 PHP 超全局 SERVER 参数 */
    public function getServerParams(): array;

    /** 获取 Cookie 参数 */
    public function getCookieParams(): array;
    public function withCookieParams(array $cookies): self;

    /** 获取查询参数 */
    public function getQueryParams(): array;
    public function withQueryParams(array $query): self;

    /** 获取已上传文件列表 */
    public function getUploadedFiles(): array;
    public function withUploadedFiles(array $uploadedFiles): self;

    /** 获取解析后的请求体
 * @return array
 */
    public function getParsedBody();
    public function withParsedBody(array $data): self;

    /** 获取请求属性集合 */
    public function getAttributes(): array;
    public function getAttribute(string $name, $default = null);
    public function withAttribute(string $name, $value): self;
    public function withoutAttribute(string $name): self;
}
