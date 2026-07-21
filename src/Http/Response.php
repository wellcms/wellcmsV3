<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Http;

use Framework\Http\Interfaces\MessageInterface;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\StreamInterface;

/**
 * HTTP响应实现类（PSR-7兼容）
 * 简化版响应实现，用于错误处理
 *
 * @package Framework\Http
 */
class Response implements \Framework\Http\Interfaces\ResponseInterface
{
    /** @var array */
    protected $headers = [];

    /** @var int */
    protected $statusCode;

    /** @var string */
    protected $reasonPhrase;

    /** @var StreamInterface */
    protected $body;

    /** @var string */
    protected $protocolVersion = '1.1';

    /** @var array */
    protected static $messages = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * @param int $status
     * @param array<string, string|string[]> $headers
     * @param StreamInterface|null $body
     */
    public function __construct(int $status = 200, array $headers = [], ?StreamInterface $body = null)
    {
        $this->statusCode = $status;
        $this->reasonPhrase = self::$messages[$status] ?? '';
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string)$name)] = $this->normalizeHeaderValue($value);
        }
        $this->body = $body ?? new Stream();
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        if ($this->protocolVersion === $version) {
            return $this;
        }
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        $name = strtolower($name);
        $values = $this->headers[$name] ?? [];
        return implode(',', array_values($values));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = $this->normalizeHeaderValue($value);
        return $new;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $new = clone $this;
        $name = strtolower($name);
        $new->headers[$name] = array_merge($new->headers[$name] ?? [], $this->normalizeHeaderValue($value));
        return $new;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        if ($body === $this->body) {
            return $this;
        }
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase ?: (self::$messages[$code] ?? '');
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * 标准化标头值
     *
     * @param string|string[] $value
     * @return string[]
     */
    protected function normalizeHeaderValue($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $values = [];
        foreach ($value as $v) {
            $values[] = (string)$v;
        }

        return $values;
    }
}
