<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class FileStorageService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\FileStorageModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var array */
    protected $cacheConfig;
    /** @var array */
    protected $uploadConfig;
    /** @var \App\Services\Storage\Support\FileSystemHelper */
    protected $fsHelper;
    /** @var \App\Services\Storage\StorageManager */
    protected $storageManager;
    /** @var int */
    protected $ttl;

    public function __construct(
        \App\Models\FileStorageModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        array $cacheConfig,
        array $uploadConfig,
        \App\Services\Storage\StorageManager $storageManager
    ) {
        // hook app_Services_FileStorageService_construct_start.php
        $this->cacheConfig = $cacheConfig;
        $this->uploadConfig = $uploadConfig;
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->storageManager = $storageManager;
        $this->ttl = $this->cacheConfig['file_ttl'] ?? 3600;
        $this->fsHelper = new \App\Services\Storage\Support\FileSystemHelper($this->uploadConfig);
        // hook app_Services_FileStorageService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        $filehash = $data['filehash'] ?? '';
        if (empty($filehash)) return $this->dbModel->insert($data);

        // 基于 filehash 的原子锁，实现 Check-and-Insert
        $lockKey = 'lock:file_storage:insert:' . $filehash;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $exists = $this->dbModel->read(['filehash' => $filehash]);
            if (!empty($exists)) return (int)$exists['id'];

            if (isset($data['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
                $data['create_ip'] = $ip2bin;
            }

            $result = $this->dbModel->insert($data);
            if (!$result) throw new BusinessException('FileStorageService -> insert(): Data writing failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('filehash:' . $filehash);
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_FileStorageService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('FileStorageService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_FileStorageService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        $lockKey = 'lock:file_storage:update:' . $id;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $oldData = $this->dbModel->read(['id' => $id]);
            if (empty($oldData)) return 0;

            if (isset($update['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
                $update['create_ip'] = $ip2bin;
            }

            $result = $this->dbModel->update(['id' => $id], $update);
            if ($result === 0) throw new BusinessException('FileStorageService -> update() Update failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('file:' . $id);
                $this->cache->delete('filehash:' . $oldData['filehash']);
                if (isset($update['filehash']) && $update['filehash'] !== $oldData['filehash']) {
                    $this->cache->delete('filehash:' . $update['filehash']);
                }
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        Validator::make(['update' => $update], ['update' => 'required|array']);

        $result = $this->dbModel->bulkUpdate($update, $keyColumn, $wheres);
        if ($result === 0) throw new BusinessException('FileStorageService -> bulkUpdate() update failed : ' . json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /**
     * 增加引用计数
     */
    public function incrementRefCount($storageId): bool
    {
        return $this->dbModel->update(['id' => $storageId], ['ref_count+' => 1]) > 0;
    }

    /**
     * 减少引用计数
     */
    public function decrementRefCount($storageId): bool
    {
        return $this->dbModel->update(['id' => $storageId], ['ref_count-' => 1]) > 0;
    }

    public function read(array $condition = [], array $orderby = [], array $fields = ['*']): array
    {
        $data = $this->dbModel->read($condition, $orderby, $fields);
        if (!$data) return [];
        $this->format($data);
        return $data;
    }

    public function readByCache(int $id, array $orderby = [], array $fields = ['*']): array
    {
        if (!$id) return [];

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->read(['id' => $id], $orderby, $fields);
        } else {
            $data = $this->cache->cacheWithLock(
                'file:' . $id,
                'lock:file:read:' . $id,
                function () use ($id, $orderby, $fields) {
                    $data = $this->read(['id' => $id], $orderby, $fields);
                    return empty($data) ? null : $data;
                },
                3,
                $this->ttl
            );
        }

        if (empty($data)) {
            if (!empty($this->cacheConfig['stores'])) {
                $data = $this->read(['id' => $id], $orderby, $fields);
            }
            return $data ?: [];
        }

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_FileStorageService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_find_end.php

        return $datalist;
    }

    public function findByIds(array $ids, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_FileStorageService_findByIds_start.php

        $datalist = $this->dbModel->find(['id' => $ids], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_findByIds_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_FileStorageService_findPaged_start.php

        $datalist = $this->dbModel->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_FileStorageService_findPaged_before.php

        foreach ($datalist as &$data) {
            $this->format($data);
        }

        // hook app_Services_FileStorageService_findPaged_end.php

        return $datalist;
    }

    public function findByReviewed(int $isReviewed, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_FileStorageService_findByReviewed_start.php

        $datalist = $this->dbModel->find(['is_reviewed' => $isReviewed], ['id' => -1], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_findByReviewed_end.php

        return $datalist;
    }

    // 存在附件和图片以及云储存，在 controller 关联完成
    public function delete(array $condition = []): int
    {
        // hook app_Services_FileStorageService_delete_start.php
        $result = $this->dbModel->delete($condition);
        // hook app_Services_FileStorageService_delete_end.php
        return $result;
    }

    public function deleteWithFileBulk(array $ids): bool
    {
        $datalist = $this->findByIds($ids, false, 1, count($ids));
        if (empty($datalist)) return false;

        foreach ($datalist as $data) {
            $this->deleteWithFile($data);
        }

        return true;
    }

    /**
     * 原子递减引用计数并在为0时清理物理文件
     */
    public function decrementAndCleanup(int $storageId): bool
    {
        if (!$storageId) return false;

        // 1. 原子递减引用计数
        $this->decrementRefCount($storageId);

        // 2. 检查当前计数
        $data = $this->dbModel->read(['id' => $storageId]);
        if (empty($data)) return false;

        if ((int)$data['ref_count'] <= 0) {
            // 真正清理
            return $this->deleteWithFile($data);
        }

        // 计数未归零，仅清理单项缓存
        if (!empty($this->cacheConfig['stores'])) {
            $this->cache->delete('file:' . $storageId);
        }

        return true;
    }

    /**
     * 物理删除文件及其数据库记录 (支持云端清理)
     * @param array $data
     */
    public function deleteWithFile(array $data = []): bool
    {
        $id = (int)$data['id'];

        // 1. 物理删除 (透过 StorageManager 实现本地+云端闭环)
        if (!empty($data['path'])) {
            $this->storageManager->deleteFile(
                (string)$data['path'],
                (int)($data['cloud_type'] ?? 0)
            );
        }

        // 2. 数据库物理删除
        $this->dbModel->delete(['id' => $id]);

        // 3. 清理全量缓存
        if (!empty($this->cacheConfig['stores'])) {
            $this->cache->delete('file:' . $id);

            $hash = $data['filehash'] ?? '';
            if ($hash) {
                if (is_resource($hash)) {
                    $hash = stream_get_contents($hash);
                    $hash = bin2hex($hash);
                } elseif (strlen($hash) === 64 && ctype_xdigit($hash)) {
                    // 已是 Hex
                } else {
                    $hash = bin2hex($hash);
                }
                $this->cache->delete('filehash:' . $hash);
            }
        }

        return true;
    }

    public function count( array $condition= []): int
    {
        return $this->dbModel->count($condition);
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_FileStorageService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_FileStorageService_maxid_end.php
        return $maxId;
    }

    /**
     * 将临时文件转正
     * @param string $tempPath
     * @return array [newPath, newUrl]
     */
    public function promoteFile(string $tempPath): array
    {
        return $this->fsHelper->promoteFile($tempPath);
    }

    /**
     * 通过文件hash获取附件（用于文件去重判断）
     * @param string $filehash SHA256哈希值
     * @return array|null
     */
    public function getByFilehash(string $filehash): ?array
    {
        if (empty($filehash)) return null;

        if (empty($this->cacheConfig['stores'])) {
            return $this->dbModel->read(['filehash' => $filehash]);
        }

        $filehashHex = bin2hex($filehash);
        return $this->cache->cacheWithLock(
            'filehash:' . $filehashHex,
            'lock:filehash:read:' . $filehashHex,
            function () use ($filehash) {
                return $this->dbModel->read(['filehash' => $filehash]);
            },
            3,
            $this->ttl
        );
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_FileStorageService_format_start.php

        // 处理二进制哈希
        if (isset($data['filehash'])) {
            if (is_resource($data['filehash'])) {
                $data['filehash'] = stream_get_contents($data['filehash']);
            }
            $data['filehash'] = bin2hex($data['filehash']);
        }
        if (isset($data['newhash'])) {
            if (is_resource($data['newhash'])) {
                $data['newhash'] = stream_get_contents($data['newhash']);
            }
            $data['newhash'] = bin2hex($data['newhash']);
        }

        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';

        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        $data['is_image_fmt'] = !empty($data['is_image']) ? '✓' : '✗';
        $data['filesize_fmt'] = $this->formatBytes($data['filesize'] ?? 0);
        // hook app_Services_FileStorageService_format_end.php
    }

    /**
     * 格式化字节大小
     * @param int $bytes 字节数
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];

        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1 << (10 * $i)), 2) . ' ' . $units[$i];
    }

    /**
     * @param int $id
     */
    public function deleteById($id): bool
    {
        return (bool)$this->delete(['id' => $id]);
    }
}
