<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Support;

/** Redis 实现 */
class UploadSessionStore implements \App\Services\Storage\Interfaces\UploadSessionStoreInterface
{
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var array */
    private $cfg;

    public function __construct(\Framework\Cache\Interfaces\CacheInterface $cache, array $cfg)
    {
        $this->cache = $cache;
        $this->cfg   = $cfg;
    }

    public function create(array $meta)
    {
        $uploadId = 'ul_' . bin2hex(random_bytes(8));
        $metaKey  = "upload:meta:{$uploadId}";
        $partsKey = "upload:parts:{$uploadId}";
        $this->cache->set($metaKey, $meta, $this->cfg['ttl'] ?? 6 * 3600);
        $this->cache->set($partsKey, [], $this->cfg['ttl'] ?? 6 * 3600);
        return $uploadId;
    }

    public function getMeta(string $uploadId)
    {
        $meta = $this->cache->get("upload:meta:{$uploadId}");
        return $meta ? $meta : null;
    }

    public function getUploadedParts(string $uploadId)
    {
        $arr = $this->cache->get("upload:parts:{$uploadId}");
        $res = array_map('intval', $arr);
        sort($res);
        return $res;
    }

    public function addPart(string $uploadId, int $index): void{
        $key = 'upload:parts:' . $uploadId;
        $arr = $this->cache->get($key) ?: [];
        if (!is_array($arr)) $arr = [];

        if (!in_array($index, $arr, true)) {
            $arr[] = $index;
            sort($arr, SORT_NATURAL);
            $this->cache->set($key, $arr, $this->cfg['ttl'] ?? 6 * 3600);
        } else {
            // 续期：再 set 一次
            $this->cache->set($key, $arr, $this->cfg['ttl'] ?? 6 * 3600);
        }

        $this->touch($uploadId);
    }

    public function touch(string $uploadId): void{
        $key = 'upload:meta:' . $uploadId;
        $val = $this->cache->get($key);
        if ($val !== null) $this->cache->set($key, $val, $this->cfg['ttl'] ?? 6 * 3600);
    }

    public function destroy(string $uploadId): void{
        $this->cache->delete("upload:parts:{$uploadId}");
        $this->cache->delete("upload:meta:{$uploadId}");
    }
}
