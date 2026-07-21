<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Support;

/** -----------------------------
 * 简易文件锁（基于 lockfile）
 * ----------------------------- */
class FileLock
{
    /** @var resource */
    private $fp;
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->fp = fopen($path, 'c');
        if ($this->fp === false) {
            throw new \RuntimeException('Unable to create lock file:' . $path);
        }
        if (!flock($this->fp, LOCK_EX)) {
            fclose($this->fp);
            throw new \RuntimeException('Unable to acquire lock:' . $path);
        }
    }

    public function __destruct() {
        if ($this->fp) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            @unlink($this->path);
        }
    }
}
