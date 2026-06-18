<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Http;

/**
 * HTTP流实现类（PSR-7兼容）
 * 简化版流实现，用于响应体
 *
 * @package Framework\Http
 */
class Stream implements \Framework\Http\Interfaces\StreamInterface
{
    /** @var resource|null */
    protected $stream;

    /** @var int|null */
    protected $size;

    /** @var bool */
    protected $seekable = false;

    /** @var bool */
    protected $writable = false;

    /** @var bool */
    protected $readable = false;

    /**
     * @param string $content
     */
    public function __construct(string $content = '')
    {
        $this->stream = fopen('php://temp', 'r+');
        if ($this->stream) {
            $this->seekable = true;
            $this->writable = true;
            $this->readable = true;
            if ($content !== '') {
                $this->write($content);
                $this->rewind();
            }
        }
    }

    public function close(): void
    {
        if ($this->stream) {
            fclose($this->stream);
        }
        $this->detach();
    }

    public function detach()
    {
        $result = $this->stream;
        $this->stream = null;
        $this->size = null;
        $this->seekable = $this->writable = $this->readable = false;
        return $result;
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->stream === null) {
            return null;
        }

        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    public function tell(): int
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        $position = ftell($this->stream);
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function eof(): bool
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        return feof($this->stream);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * @param int $offset
     * @param int $whence
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException('Unable to seek to stream position "' . $offset . '"');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @param string $string
     */
    public function write($string): int
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }

        $this->size = null;
        $result = fwrite($this->stream, $string);

        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        $result = fread($this->stream, $length);

        if ($result === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $result;
    }

    public function getContents(): string
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    /**
     * @param null $key
     * @return array
     */
    public function getMetadata($key = null)
    {
        if ($this->stream === null) {
            return $key ? null : [];
        }

        $meta = stream_get_meta_data($this->stream);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }
}
