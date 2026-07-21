<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

/**
 * Interface StreamInterface
 *
 * 表示可读写的流数据（PSR‑7）
 */
interface StreamInterface
{
    public function __toString(): string;
    public function close(): void;
    public function detach();
    /**
     * @return array
     */
    public function getSize();
    public function tell(): int;
    public function eof(): bool;
    public function isSeekable(): bool;
    public function seek(int $offset, int $whence = SEEK_SET): void;
    public function rewind(): void;
    public function isWritable(): bool;
    public function write(string $string): int;
    public function isReadable(): bool;
    public function read(int $length): string;
    public function getContents(): string;
    /**
     * @param string|null $key
     * @return array|mixed|null
     */
    public function getMetadata($key = null);
}