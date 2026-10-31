<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\System;

class KeyValueService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \Framework\Core\Container */
    protected $container;
    /** @var \App\Models\KeyValueModel */
    private $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;

    public function __construct(
        \Framework\Core\Container $container,
        \Framework\Cache\Interfaces\CacheInterface $cache
    ) {
        $this->container = $container;
        $this->cache = $cache;
    }

    private function getModel()
    {
        if ($this->dbModel !== null) {
            return $this->dbModel;
        }
        $resolved = $this->container->get(\App\Models\KeyValueModel::class);
        // 首次获取可能让出协程；保留已经建立的模型引用。
        return $this->dbModel = $this->dbModel ?? $resolved;
    }

    private function normalizeKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    /**
     * @return array
     */
    public function get(string $key)
    {
        $key = $this->normalizeKey($key);
        $static = $this->getState('static', []);
        if (!isset($static[$key])) {
            $result = $this->getModel()->read(['key' => $key]);
            if (empty($result)) return null;
            $static[$key] = json_decode($result['value'], true);
            $this->setState('static', $static);
        }
        return $static[$key];
    }

    public function set(string $key, $value): bool
    {
        $key = $this->normalizeKey($key);
        $lockKey = 'lock:kv:set:' . $key;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            $current = $this->getModel()->readFromMaster(['key' => $key]);
            return $this->persistValue($key, $value, $current);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /** 调用方持有对应写锁；共享唯一的编码、幂等写入及请求内缓存发布规则。 */
    private function persistValue(string $key, $value, array $current): bool
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode KV value: ' . json_last_error_msg());
        }
        if ($current && $current['value'] === $encoded) {
            $written = true;
        } elseif (!$current) {
            $written = $this->getModel()->insert(['key' => $key, 'value' => $encoded]) > 0;
        } else {
            $written = $this->getModel()->update(['key' => $key], ['value' => $encoded]) > 0;
        }
        if ($written) {
            $static = $this->getState('static', []);
            $static[$key] = $value;
            $this->setState('static', $static);
        }
        return $written;
    }

    public function delete(string $key): int
    {
        $key = $this->normalizeKey($key);
        $deleted = $this->getModel()->delete(['key' => $key]);
        $static = $this->getState('static', []);
        unset($static[$key]);
        $this->setState('static', $static);
        if ($key === 'setting') $this->unsetState('setting');
        return $deleted;
    }

    // ---- kv + cache ----

    public function cacheGet(string $key)
    {
        $key = $this->normalizeKey($key);
        // 使用 cacheWithLock 防止缓存击穿 (Cache Stampede)
        return $this->cache->cacheWithLock(
            $key,
            'lock:kv:' . $key,
            function () use ($key) {
                return $this->get($key);
            }
        );
    }

    public function cacheSet(string $key, $value, int $expire = 0): bool
    {
        $key = $this->normalizeKey($key);
        // KV 保护：用户授权修复写入一致性；不改变上传元数据、二进制或序列化合同。
        // 数据库提交后缓存发布失败，必须失效旧缓存，允许调用方安全重试。
        if (!$this->set($key, $value)) return false;
        return $this->publishCache($key, $value, $expire);
    }

    /** 持久化已成功后才调用；发布失败必须失效旧缓存，允许安全重试。 */
    private function publishCache(string $key, $value, int $expire = 0): bool
    {
        if ($key === 'setting') $this->unsetState('setting');
        $published = false;
        try {
            $published = $this->cache->set($key, $value, $expire);
            return $published;
        } finally {
            if (!$published && !$this->cache->delete($key)) {
                throw new \RuntimeException('Unable to invalidate KV cache after publication failure');
            }
        }
    }

    public function cacheDelete(string $key): bool
    {
        $key = $this->normalizeKey($key);
        // delete() 的 0 表示已经不存在；数据库异常会原样上抛。
        $this->delete($key);
        return $this->cache->delete($key);
    }

    // ---- setting ----

    /**
     * @return void
     */
    public function settingGet(string $key)
    {
        $setting = $this->getState('setting');
        if (null === $setting) {
            $setting = $this->cacheGet('setting') ?? [];
            $this->setState('setting', $setting);
        }
        return $setting[$key] ?? null;
    }

    /** 仅在 setting 写锁内读取主库，避免过期缓存覆盖其他进程刚提交的配置域。 */
    private function readSettingsFromMaster(): array
    {
        $row = $this->getModel()->readFromMaster(['key' => 'setting']);
        if (!$row) return [[], []];
        $setting = json_decode($row['value'], true);
        if (!is_array($setting)) {
            throw new \RuntimeException('Invalid persistent settings');
        }
        return [$row, $setting];
    }

    public function settingSet(string $key, $value): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            list($row, $setting) = $this->readSettingsFromMaster();
            $setting[$key] = $value;
            if (!$this->persistValue('setting', $setting, $row)) return false;
            if (!$this->publishCache('setting', $setting)) return false;
            $this->setState('setting', $setting);
            return true;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * 在全局 setting 锁内原子合并指定配置域。
     */
    public function settingMerge(string $key, array $changes): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            list($row, $setting) = $this->readSettingsFromMaster();
            $current = isset($setting[$key]) && is_array($setting[$key]) ? $setting[$key] : [];
            $setting[$key] = array_merge($current, $changes);
            if (!$this->persistValue('setting', $setting, $row)) return false;
            if (!$this->publishCache('setting', $setting)) return false;
            $this->setState('setting', $setting);
            return true;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function settingDelete(string $key): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            list($row, $setting) = $this->readSettingsFromMaster();
            // unset 对不存在及 null 字段均幂等；仍修复本地与共享缓存中的旧快照。
            unset($setting[$key]);
            if (!$this->persistValue('setting', $setting, $row)) return false;
            if (!$this->publishCache('setting', $setting)) return false;
            $this->setState('setting', $setting);
            return true;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }
}
