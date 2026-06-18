<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

use Framework\Http\Interfaces\{MessageInterface, StreamInterface};

/**
 * Class Response
 *
 * PSR‑7 Response 实现，支持状态码、原因短语、协议版本、头部与主体流操作。
 */
class Response implements \Framework\Http\Interfaces\ResponseInterface
{
    /** @var int */
    private $statusCode;
    /** @var string */
    private $reasonPhrase;
    /** @var string */
    private $protocol = '1.1';
    /** @var array */
    private $headers = [];
    /** @var array */
    private $headerNames = [];
    /** @var StreamInterface */
    private $body;

    /**
     * 状态码与原因短语映射（RFC 7231）
     */
    /** @var array */
    private static $phrases = [
        // 2xx Success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',

        // 3xx Redirection
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        // 4xx Client Errors
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        451 => 'Unavailable For Legal Reasons',

        // 5xx Server Errors
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocol = '1.1'
    ) {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = self::$phrases[$statusCode] ?? '';
        $this->body = $body ?? new Stream(fopen('php://memory', 'r+'));
        $this->protocol = $protocol;

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string)$name);
            $this->headers[$normalized] = (array)$value;
            $this->headerNames[$normalized] = (string)$name;
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): \Framework\Http\Interfaces\ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode   = $code;
        $clone->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::$phrases[$code] ?? '');
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): MessageInterface
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

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headers[$normalized] = (array)$value;
        $clone->headerNames[$normalized] = $name;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headers[$normalized] = array_merge(
            $clone->headers[$normalized] ?? [],
            (array)$value
        );
        if (!isset($clone->headerNames[$normalized])) {
            $clone->headerNames[$normalized] = $name;
        }
        return $clone;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
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

    // 辅助方法已不再需要，由内部 normalized 逻辑替代
}

/* // 1. 创建基础响应​
$response = new Response(200, ['Content-Type' => 'text/html'], new Stream('Hello World'));
echo $response->getStatusCode();          // 200
echo $response->getReasonPhrase();        // "OK"
echo $response->getHeaderLine('Content-Type'); // "text/html"

// 2. 修改状态码
$newResponse = $response->withStatus(404);
echo $newResponse->getStatusCode();       // 404
echo $newResponse->getReasonPhrase();     // "Not Found"

// 3. 自定义原因短语​
$customResponse = $newResponse->withStatus(299, "Custom Status");
echo $customResponse->getReasonPhrase();   // "Custom Status"

// 4. 添加/修改头部​
$responseWithHeader = $response->withHeader('Cache-Control', 'no-cache');
$responseWithAdded = $responseWithHeader->withAddedHeader('Cache-Control', 'max-age=3600');
echo $responseWithAdded->getHeaderLine('Cache-Control'); // "no-cache,max-age=3600"

// 5. 替换响应体​
$stream = new Stream(fopen('php://temp', 'r+'));
$stream->write('{"error": "Not Found"}');
$jsonResponse = $response->withBody($stream)->withHeader('Content-Type', 'application/json');

// 6. 协议版本控制
$http10Response = $response->withProtocolVersion('1.1');
echo $http10Response->getProtocolVersion(); // "1.1" */
