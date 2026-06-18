<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

/**
 * Interface UriInterface
 *
 * 表示 URI 对象（PSR‑7）
 */
interface UriInterface
{
    public function getScheme(): string;
    public function getAuthority(): string;
    public function getUserInfo(): string;
    public function getHost(): string;
    public function getPort(): ?int;
    public function getPath(): string;
    public function getQuery(): string;
    public function getFragment(): string;
    public function withScheme(string $scheme): self;
    public function withUserInfo(string $user, ?string $password = null): self;
    public function withHost(string $host): self;
    public function withPort(?int $port): self;
    public function withPath(string $path): self;
    public function withQuery(string $query): self;
    public function withFragment(string $fragment): self;
    public function __toString(): string;
}
