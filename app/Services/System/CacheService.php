<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\System;

class CacheService implements \Framework\Cache\Interfaces\CacheInterface
{
    /** @var \App\Models\CacheModel */
    private $dbModel;
    /** @var array */
    private $static;

    public function __construct(\App\Models\CacheModel $dbModel)
    {
        $this->dbModel = $dbModel;
    }

    private function getCacheKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    public function withPrefix(string $key): string
    {
        return $this->getCacheKey($key);
    }

    public function getPrefix(): string
    {
        return '';
    }

    /**
     * @param null $default
     */
    public function getMulti(array $keys, $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $val = $this->get($key);
            $result[$key] = $val !== null ? $val : $default;
        }
        return $result;
    }

    /**
     * @param null $default
     */
    public function getMultiple(array $keys, $default = null): array
    {
        return $this->getMulti($keys, $default);
    }

    public function setMulti(array $items, int $ttl = 0): bool
    {
        foreach ($items as $key => $value) {
            if (!$this->set($key, $value, $ttl)) return false;
        }
        return true;
    }

    public function setMultiple(array $items, int $ttl = 0): bool
    {
        return $this->setMulti($items, $ttl);
    }

    /**
     * @param null $default
     * @return array
     */
    public function get(string $key, $default = null)
    {
        $key = $this->getCacheKey($key);
        if (isset($this->static[$key])) return $this->static[$key];
        $result = $this->dbModel->read(['key' => $key]);
        if (empty($result)) return null;

        if ($result['expiry'] && time() > $result['expiry']) {
            $this->delete($key);
            return null;
        }

        $data = json_decode($result['value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->static[$key] = $data;
            return $data;
        } else {
            $this->static[$key] = $result['value'];
            return $result['value'];
        }
    }

    public function set(string $key, $value, int $ttl = 0): bool
    {
        $key = $this->getCacheKey($key);
        if (empty($value)) return false;
        is_array($value) && $value =  json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = [
            'key' => $key,
            'value' => $value
        ];
        $ttl && $data['expiry'] = time() + $ttl;

        $result = $this->dbModel->read(['key' => $key]);
        if ($result) {
            unset($data['key']);
            $data['updated_at'] = time();
            return $this->dbModel->update(['key' => $key], $data) > 0;
        } else {
            return $this->dbModel->insert($data) > 0;
        }
    }

    public function delete(string $key): bool
    {
        $key = $this->getCacheKey($key);
        return $this->dbModel->delete(['key' => $key]) > 0;
    }

    public function increment(string $key, int $step = 1, int $ttl = 0)
    {
        $key = $this->getCacheKey($key);
        $result = $this->get($key);
        if (empty($result)) {
            $this->set($key, 1, $ttl);
            return $step;
        }

        $step += $result;
        $expiry = $ttl ? $ttl : 0;
        $this->set($key, $step, $expiry);
        return $step;
    }

    /**
     * @param string $key
     * @return void
     */
    public function lock($key, int $expire = 30)
    {
        $key = $this->getCacheKey($key);
        return (string)\Framework\Utils\FileHelper::lock($key, $expire);
    }

    /**
     * @param string $key
     * @return void
     */
    public function unlock($key, string $token = '')
    {
        $key = $this->getCacheKey($key);
        return (string)\Framework\Utils\FileHelper::unlock($key);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function isLocked($key)
    {
        $key = $this->getCacheKey($key);
        return (bool)\Framework\Utils\FileHelper::isLocked($key);
    }

    public function clear(): bool
    {
        return (bool)$this->dbModel->flushAll();
    }

    public function allow(string $key, int $cap, int $rate, array $only = []): bool
    {
        return false;
    }

    public function original(string $only = '')
    {
        return $this->dbModel;
    }

    public function cacheWithLock(string $key, string $lockKey, callable $cacheGetter, int $maxAttempts = 5, int $cacheTtl = 0, int $lockTtl = 3): string{
        return '';
    }
}
