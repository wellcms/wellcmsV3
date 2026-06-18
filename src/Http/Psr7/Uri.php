<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

use Framework\Http\Interfaces\UriInterface;

/**
 * 标准的 URI 操作工具，涵盖解析、构建、修改与格式化功能，适用于需要严格遵循 HTTP 协议规范的应用（如 API 开发、路由系统）
 */
class Uri implements UriInterface
{
    /** @var string */
    protected $scheme = '';
    /** @var string */
    protected $host = '';
    /** @var null */
    protected $port = null;
    /** @var string */
    protected $path = '';
    /** @var string */
    protected $query = '';
    /** @var string */
    protected $fragment = '';
    /** @var string */
    protected $user = '';
    /** @var string */
    protected $password = '';

    public function __construct(string $uri = '')
    {
        if ($uri) {
            /* if (!filter_var($uri, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Invalid URI: $uri");
            } */
            $parts = parse_url($uri);
            $this->scheme = $parts['scheme'] ?? '';
            $this->host = $parts['host'] ?? '';
            $this->port = $parts['port'] ?? null;
            $this->path = $parts['path'] ?? '';
            $this->query = $parts['query'] ?? '';
            $this->fragment = $parts['fragment'] ?? '';
            if (isset($parts['user'])) {
                $this->user = $parts['user'];
                $this->password = $parts['pass'] ?? '';
            }
        }
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    // 输出格式: admin:pass@api.wellcms.com:8080
    public function getAuthority(): string
    {
        $auth = $this->host;
        if ($this->user) {
            $auth = $this->user . ($this->password ? ':' . $this->password : '') . '@' . $auth;
        }
        if ($this->port) {
            $auth .= ':' . $this->port;
        }
        return $auth;
    }

    public function getUserInfo(): string
    {
        return $this->user . ($this->password ? ':' . $this->password : '');
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $clone = clone $this;
        $clone->scheme = $scheme;
        return $clone;
    }

    /**
     * @param null $password
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->user = $user;
        $clone->password = $password;
        return $clone;
    }

    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    public function withPort(?int $port): UriInterface
    {
        $clone = clone $this;
        $clone->port = $port;
        return $clone;
    }

    public function withPath(string $path): UriInterface
    {
        $path = $this->normalizePath($path);
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    private function normalizePath(string $path): string
    {
        // 替换连续斜杠//和相对路径符号./
        $path = preg_replace(['#/+#', '#(/\./)+#'], '/', $path);
        // 解析上级目录（需谨慎处理）清理 ../
        $path = preg_replace('#/[^/]+/\.\./#', '/', $path);
        return $path;
    }

    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = $query;
        return $clone;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = $fragment;
        return $clone;
    }

    public function __toString(): string
    {
        $uri = $this->scheme ? $this->scheme . '://' : '';
        $uri .= $this->getAuthority() . $this->path;
        $uri .= $this->query ? '?' . $this->query : '';
        $uri .= $this->fragment ? '#' . $this->fragment : '';
        return $uri;
    }
}
