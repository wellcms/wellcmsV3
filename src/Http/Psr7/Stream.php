<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

/**
 * Class Stream
 *
 * PSR‑7 Stream 实现，封装 PHP 流资源，支持读写、定位和元数据访问。
 */
class Stream implements \Framework\Http\Interfaces\StreamInterface
{

    /** @var resource|null */
    private $resource;

    /** @var array|null */
    private $meta;

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Stream must be a PHP resource');
        }
        $this->resource = $resource;
    }

    /**
     * 从给定字符串创建不可变流
     */
    public static function fromString(string $content): \Framework\Http\Interfaces\StreamInterface
    {
        $resource = fopen('php://temp', 'r+'); // 可读写
        fwrite($resource, $content);
        rewind($resource);
        return new self($resource);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $res = $this->resource;
        $this->resource = null;
        return $res;
    }

    /**
     * @return array
     */
    public function getSize()
    {
        $stats = fstat($this->resource);
        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        return ftell($this->resource);
    }

    public function eof(): bool
    {
        return feof($this->resource);
    }

    public function isSeekable(): bool
    {
        $meta = $this->getMetadata();
        return $meta['seekable'] ?? false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }
        fseek($this->resource, $offset, $whence);
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = $this->getMetadata('mode');
        return is_string($mode) && (bool)preg_match('/[waxc+]/', $mode);
    }

    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }
        return fwrite($this->resource, $string);
    }

    public function isReadable(): bool
    {
        $mode = $this->getMetadata('mode');
        return is_string($mode) && (bool)preg_match('/[r+]/', $mode);
    }

    public function read(int $length): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }
        return fread($this->resource, $length);
    }

    public function getContents(): string
    {
        return stream_get_contents($this->resource);
    }

    /**
     * @param string|null $key
     * @return array|mixed|null
     */
    public function getMetadata($key = null)
    {
        if ($this->meta === null) {
            $this->meta = stream_get_meta_data($this->resource);
        }
        if ($key === null) {
            return $this->meta;
        }
        return $this->meta[$key] ?? null;
    }

    public function __destruct() {
        $this->close();
    }
}

/*
// 使用 src/Http/Psr7/Factories/StreamFactory 工厂替换直接操作此类
//$stream = Stream::fromString('Hello World');
$response = new Response(200, ['Content-Type' => 'text/html'], $stream);

echo $response->getStatusCode(); // 200
echo '<hr>';
echo $response->getReasonPhrase(); // "OK"
echo '<hr>';
echo $response->getHeaderLine('Content-Type'); // "text/html"
echo '<hr>';
*/
