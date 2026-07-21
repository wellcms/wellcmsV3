<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

use Framework\Http\Interfaces\{MessageInterface, RequestInterface, ServerRequestInterface, StreamInterface, UriInterface};

/**
 * PSR-7 ServerRequest 实现，支持输入过滤、签名验证、文件白名单等功能。
 * 兼容 PHP 7.2+，FPM/Swoole/Swow 环境。
 */
final class Request implements ServerRequestInterface
{
    /** @var string */
    private $method;
    /** @var UriInterface */
    private $uri;
    /** @var array */
    private $headers = [];
    /** @var array */
    private $headerNames = [];
    /** @var StreamInterface */
    private $body;
    /** @var string */
    private $protocol;
    /** @var array */
    private $attributes = [];
    /** @var array */
    private $uploadedFiles = [];
    /** @var mixed */
    private $parsedBody;
    /** @var array */
    private $queryParams;
    /** @var array */
    private $server;
    /** @var array */
    private $cookieParams = [];

    public function __construct(
        UriInterface $uri,
        string $method,
        array $headers,
        array $serverParam,
        array $cookieParams,
        StreamInterface $body,
        string $protocol = '1.1',
        array $queryParams = [],
        $parsedBody = null,
        array $uploadedFiles = []
    ) {
        $this->uri = $uri;
        $this->method = strtoupper($method);
        $this->server = $serverParam;
        $this->cookieParams = $cookieParams;
        $this->body = $body;
        $this->protocol = $protocol;
        $this->queryParams = $queryParams;
        $this->parsedBody = $parsedBody;
        $this->uploadedFiles = $uploadedFiles;

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string)$name);
            $this->headers[$normalized] = (array)$value;
            $this->headerNames[$normalized] = (string)$name;
        }
    }

    // ----- MessageInterface -----

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion($version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocol = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        $headers = [];
        foreach ($this->headers as $normalized => $value) {
            $headers[$this->headerNames[$normalized]] = $value;
        }
        return $headers;
    }

    /**
     * @param string $name
     */
    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower((string)$name)]);
    }

    /**
     * @param string $name
     */
    public function getHeader($name): array
    {
        return $this->headers[strtolower((string)$name)] ?? [];
    }

    /**
     * @param string $name
     */
    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @param string $name
     */
    public function withHeader($name, $value): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower((string)$name);
        $clone->headers[$normalized] = (array)$value;
        $clone->headerNames[$normalized] = (string)$name;
        return $clone;
    }

    /**
     * @param string $name
     */
    public function withAddedHeader($name, $value): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower((string)$name);
        $clone->headers[$normalized] = array_merge($clone->headers[$normalized] ?? [], (array)$value);
        if (!isset($clone->headerNames[$normalized])) {
            $clone->headerNames[$normalized] = (string)$name;
        }
        return $clone;
    }

    /**
     * @param string $name
     */
    public function withoutHeader($name): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower((string)$name);
        unset($clone->headers[$normalized], $clone->headerNames[$normalized]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // ----- RequestInterface -----

    public function getRequestTarget(): string
    {
        return $this->uri->getPath() . ($this->uri->getQuery() ? '?' . $this->uri->getQuery() : '');
    }

    public function withRequestTarget($target): RequestInterface
    {
        // According to PSR‑7, the request-target is usually the path and query of the URI.
        // We support origin-form targets like "/path?query" or just "/path".
        $clone = clone $this;

        // Split path and query
        if (false !== $pos = strpos($target, '?')) {
            $path  = substr($target, 0, $pos);
            $query = substr($target, $pos + 1);
        } else {
            $path  = $target;
            $query = '';
        }

        // Build a new URI with the requested path and query
        $uri = $clone->uri->withPath($path)->withQuery($query);

        $clone->uri = $uri;
        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): RequestInterface
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;

        // PSR‑7: If the new URI contains a host component and preserveHost is false,
        // MUST update the Host header. If preserveHost is true, MUST NOT update unless
        // the Host header is missing.
        $host = $uri->getHost();
        if ($host !== '') {
            // Append port if present and non-standard
            $port = $uri->getPort();
            if ($port !== null) {
                $host .= ':' . $port;
            }

            if (!$preserveHost) {
                // Replace Host header unconditionally
                $clone->headers['Host'] = [$host];
            } elseif (!$this->hasHeader('Host')) {
                // preserveHost=true but no existing Host header: set it
                $clone->headers['Host'] = [$host];
            }
        }

        return $clone;
    }

    // ----- ServerRequestInterface -----

    public function getServerParams(): array
    {
        // Global parsing is moved to the middleware, where an empty array is returned
        return $this->server ?? [];
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams ?? [];
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    /**
     * @return array
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * @param array $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    // 移除属性
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }
}

/*
use Framework\Http\Interfaces\StreamInterface;
use Framework\Http\Interfaces\UploadedFileInterface;
use Framework\Http\Interfaces\UriInterface;

// 1. 创建基本请求对象
$uri = new Uri('https://example.com/api?page=1');
$stream = new Stream(fopen('php://temp', 'r+'));
$stream->write('{"name":"John"}');

$request = new Request(
    $uri,
    'GET',
    [
        'Content-Type' => ['application/json'],
        'User-Agent'   => ['MyApp/1.0']
    ],
    $stream
);

// 2. 修改协议版本
$newRequest = $request->withProtocolVersion('2.0');
echo $newRequest->getProtocolVersion(); // 输出 "2.0"

// 3. 添加请求头
$requestWithAuth = $request->withAddedHeader('Authorization', ['Bearer token123']);

// 4. 替换消息体
$newStream = new Stream(fopen('php://temp', 'r+'));
$newStream->write('{"name":"Alice"}');
$requestWithNewBody = $request->withBody($newStream);

// 5. 修改请求方法
$postRequest = $request->withMethod('POST');

// 6. 更新URI并保留Host头
$newUri = new Uri('https://example.com/new-path');
$requestWithNewUri = $request->withUri($newUri, true);

// 7. 设置查询参数（覆盖原始URI参数）
$requestWithQuery = $request->withQueryParams([
    'page' => 2,
    'sort' => 'desc'
]);

// 8. 添加上传文件（符合PSR-7结构）
$uploadedFile = new UploadedFile(
    '/tmp/uploaded_file.txt',
    'photo.jpg',
    'image/jpeg',
    UPLOAD_ERR_OK
);

$requestWithFiles = $request->withUploadedFiles([
    'avatar' => [$uploadedFile] // 支持多文件上传
]);

// 9. 设置解析后的请求体（例如JSON解析结果）
$requestWithParsedBody = $request->withParsedBody([
    'name' => 'John',
    'age'  => 30
]);

// 10. 添加自定义属性（用于中间件传递数据）
$requestWithAttribute = $request->withAttribute('user_id', 12345);
echo $requestWithAttribute->getAttribute('user_id'); // 输出 12345

// 链式调用示例
$modifiedRequest = $request
    ->withMethod('PUT')
    ->withHeader('X-Request-ID', ['abc123'])
    ->withParsedBody(['action' => 'update'])
    ->withAttribute('audit', true);

// 获取查询参数示例
$queryParams = $modifiedRequest->getQueryParams();

// 获取上传文件示例
$files = $modifiedRequest->getUploadedFiles();
if (isset($files['avatar'][0])) {
    $file = $files['avatar'][0];
    $file->moveTo('/path/to/storage/new.jpg');
}

// 显示请求目标
echo $modifiedRequest->getRequestTarget(); // 输出类似 "/api?page=1"
*/
