<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Content;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class TempContentService
{
    /** @var \App\Models\TempContentModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var array */
    protected $cacheConfig;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var int */
    protected $maxId;
    /** @var int */
    protected $ttl;

    public function __construct(
        \App\Models\TempContentModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        array $cacheConfig
    ) {
        // hook app_Services_TempContentService_construct_start.php
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->ttl = $this->cacheConfig['temp_ttl'] ?? 3600;
        // hook app_Services_TempContentService_construct_end.php
    }

    public function insert(array $data): string
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);
        !is_array($data) && $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!isset($data['id'])) {
            $data['id'] = \Framework\Utils\UuidHelper::generate(true);
        } else {
            $data['id'] = \Framework\Utils\UuidHelper::toBinary((string)$data['id']);
        }
        if (empty($data['id'])) {
            throw new BusinessException('Invalid UUID format');
        }
        $id = $data['id'];

        if (isset($data['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
            $data['create_ip'] = $ip2bin;
        }

        // hook app_Services_TempContentService_insert_start.php

        $result = $this->dbModel->insert($data);
        if (!$result) throw new BusinessException('TempContentService -> insert(): Data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_TempContentService_insert_end.php

        return \Framework\Utils\UuidHelper::fromBinary($id);
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_TempContentService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('TempContentService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_TempContentService_bulkInsert_end.php

        return $result;
    }

    public function update(string $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required', 'update' => 'required|array']);

        $lockKey = 'lock:temp_content:update:' . $id;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        if (isset($update['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
            $update['create_ip'] = $ip2bin;
        }

        try {
            $binId = \Framework\Utils\UuidHelper::toBinary($id);
            $result = $this->dbModel->update(['id' => $binId], $update);
            if ($result === 0) throw new BusinessException('TempContentService -> update() Update failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('temp:' . bin2hex($binId));
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function read(string $id, array $orderby = [], array $fields = ['*']): array
    {
        if (!$id) return [];

        $binId = \Framework\Utils\UuidHelper::toBinary($id);
        $hexId = bin2hex($binId);

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->dbModel->read(['id' => $binId], $orderby, $fields);
        } else {
            $data = $this->cache->cacheWithLock(
                'temp:' . $hexId,
                'lock:temp:read:' . $hexId,
                function () use ($binId, $orderby, $fields) {
                    return $this->dbModel->read(['id' => $binId], $orderby, $fields);
                },
                3,
                $this->ttl
            );
        }

        if (empty($data)) return [];

        $this->format($data);
        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['id'])) {
            $condition['id'] = is_array($condition['id'])
                ? array_filter(array_map([\Framework\Utils\UuidHelper::class, 'toBinary'], $condition['id']))
                : \Framework\Utils\UuidHelper::toBinary((string)$condition['id']);
        }

        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_TempContentService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_TempContentService_find_end.php

        return $datalist;
    }

    public function findByIds(array $ids, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;
        $binIds = array_filter(array_map([\Framework\Utils\UuidHelper::class, 'toBinary'], $ids));

        // hook app_Services_TempContentService_findByIds_start.php

        $datalist = $this->dbModel->find(['id' => $binIds], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_TempContentService_findByIds_end.php

        return $datalist;
    }

    public function findByUserId(int $userId, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {

        // hook app_Services_TempContentService_findByUserId_start.php

        $datalist = $this->dbModel->find(['user_id' => $userId], ['created_at' => -1], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_TempContentService_findByUserId_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        if (isset($condition['id'])) {
            $condition['id'] = is_array($condition['id'])
                ? array_filter(array_map([\Framework\Utils\UuidHelper::class, 'toBinary'], $condition['id']))
                : \Framework\Utils\UuidHelper::toBinary((string)$condition['id']);
        }
        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_TempContentService_findPaged_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_TempContentService_findPaged_before.php

        foreach ($datalist as &$data) {
            $this->format($data);
        }

        // hook app_Services_TempContentService_findPaged_end.php

        return $datalist;
    }

    public function findByUserIdPaged(array $condition = [], array $orderby = ['created_at' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_TempContentService_findByUserIdPaged_start.php

        $datalist = $this->dbModel->find(['user_id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_TempContentService_findByUserIdPaged_before.php

        foreach ($datalist as &$data) {
            $this->format($data);
        }

        // hook app_Services_TempContentService_findByUserIdPaged_end.php

        return $datalist;
    }

    /**
     * @param int $id
     */
    public function delete($id): int
    {
        $binId = is_array($id)
            ? array_filter(array_map([\Framework\Utils\UuidHelper::class, 'toBinary'], $id))
            : \Framework\Utils\UuidHelper::toBinary((string)$id);

        // hook app_Services_TempContentService_delete_start.php
        $result = $this->dbModel->delete(['id' => $binId]);
        // hook app_Services_TempContentService_delete_end.php
        return $result;
    }

    public function count( array $condition= []): int
    {
        return $this->dbModel->count($condition);
    }

    public function maxid(): int
    {
        if (null !== $this->maxId) return $this->maxId;
        // hook app_Services_TempContentService_maxid_start.php
        $this->maxId = $this->dbModel->maxid();
        // hook app_Services_TempContentService_maxid_end.php
        return $this->maxId;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;

        // hook app_Services_TempContentService_format_start.php
        // 处理二进制ID
        if (isset($data['id'])) {
            $data['id'] = \Framework\Utils\UuidHelper::fromBinary($data['id']);
        }

        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        $data['data_fmt'] = isset($data['data']) ? json_decode($data['data'], true) : [];

        // hook app_Services_TempContentService_format_before.php

        switch ((int)$data['module']) {
            /* case 1:
                $data['module_fmt'] = 'forum'; // 对于语言包
                break;
            case 2:
                $data['module_fmt'] = 'acticle';
                break;
            case 3:
                $data['module_fmt'] = 'avatar';
                break;
            case 4:
                $data['module_fmt'] = 'download';
                break;
            case 5:
                $data['module_fmt'] = 'tools';
                break; */
            // hook app_Services_TempContentService_format_after.php
            default:
                $data['module_fmt'] = 'unknown';
                break;
        }

        // hook app_Services_TempContentService_format_end.php
    }
}
