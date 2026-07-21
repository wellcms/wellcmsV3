<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Interfaces;

use Framework\Http\Interfaces\StreamInterface;

/**
 * Interface UploadedFileInterface
 *
 * 表示通过 HTTP 上传的文件（PSR‑7）
 */
interface UploadedFileInterface
{
    public function getStream(): StreamInterface;
    public function moveTo(string $targetPath): void;
    public function getSize(): ?int;
    public function getError(): int;
    public function getClientFilename(): ?string;
    public function getClientMediaType(): ?string;
}
