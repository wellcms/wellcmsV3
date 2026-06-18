<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\System;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class IpListService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\IpListModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var array */
    protected $cacheConfig;
    /** @var int */
    protected $ttl;

    public function __construct(
        \App\Models\IpListModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        array $cacheConfig
    ) {
        // hook app_Services_IpListService_construct_start.php
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->ttl = $this->cacheConfig['ip_ttl'] ?? 3600;
        // hook app_Services_IpListService_construct_end.php
    }

    /**
     * 捕获请求上下文
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $this->setState('capturedIp', \Framework\Utils\IpHelper::ip($request->getServerParams()));
        $user = $request->getAttribute('user');
        if ($user) {
            $this->setState('capturedUid', (int)($user['id'] ?? 0));
        }
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        if (empty($data['ip'])) return 0;

        if (isset($data['ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['ip'] ?? '');
            $data['ip'] = $ip2bin;
        }

        // 针对 IP 级别加锁，防止并发下的“读-判-写”冲突 (Check-and-Insert)
        $lockKey = 'lock:ip_list:insert:' . $ip;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            // 锁内二次检查，防止数据库 Duplicate Entry 报错
            $exists = $this->dbModel->read(['ip' => $ip2bin]);
            if (!empty($exists)) {
                return (int)$exists['id'];
            }

            $result = $this->dbModel->insert($data);
            if (!$result) throw new BusinessException('IpListService -> insert(): Data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('readByIp:' . $ip);
                !empty($data['user_id']) && $this->cache->delete('readByUserId:' . $data['user_id']);
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

        // hook app_Services_IpListService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('IpListService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_IpListService_bulkInsert_end.php

        return $result;
    }

    /**
     * @param int $id
     */
    public function update($id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required', 'update' => 'required|array']);

        $lockKey = 'lock:ip_list:update:' . $id;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        if (isset($update['ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['ip'] ?? '');
            $update['ip'] = $ip2bin;
        }

        try {
            // 先读取旧数据用于缓存清除
            $oldData = $this->dbModel->read(['id' => $id]);
            if (empty($oldData)) return 0;

            $result = $this->dbModel->update(['id' => $id], $update);
            if ($result === 0) throw new BusinessException('IpListService -> update() Update failed : ' . json_encode(['id' => $id, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (!empty($this->cacheConfig['stores'])) {
                // 清除旧数据的缓存键
                $this->cache->delete('readByIp:' . $oldData['ip']);
                $oldData['user_id'] && $this->cache->delete('readByUserId:' . $oldData['user_id']);

                // 如果 IP 或 UserID 发生了变化，清除新数据的缓存键
                if (isset($update['ip']) && $update['ip'] != $oldData['ip']) {
                    $this->cache->delete('readByIp:' . $ip);
                }
                if (isset($update['user_id']) && $update['user_id'] != $oldData['user_id']) {
                    $this->cache->delete('readByUserId:' . $update['user_id']);
                }
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * @param int $id
     */
    public function read($id, array $orderby = [], array $fields = ['*']): array
    {
        $id = intval($id);
        if (empty($id)) return [];

        // hook app_Services_IpListService_read_start.php

        $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_IpListService_read_middle.php

        $this->format($data);

        // hook app_Services_IpListService_read_end.php

        return $data;
    }

    public function readByIp(string $ip, array $orderby = [], array $fields = ['*']): array
    {
        list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($ip);

        $static = $this->getState('static', []);
        if (isset($static[$ip])) return $static[$ip];

        $data = $this->dbModel->read(['ip' => $ip2bin], $orderby, $fields);
        if (empty($data)) return [];

        $data && $this->format($data);

        $static[$ip] = $data;
        $this->setState('static', $static);
        return $data;
    }

    // 传入正常IP，如192.168.1.1，建议使用 readByCacheIp 方法以获得更好的性能和并发保护
    public function readByCacheIp(string $ip, array $orderby = [], array $fields = ['*']): array
    {
        if (empty($ip)) return [];

        $static = $this->getState('static', []);
        if (isset($static[$ip])) {
            $data = $static[$ip];
            return $data;
        }

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->readByIp($ip, $orderby, $fields);
            return $data;
        }

        $key = 'readByIp:' . $ip;
        // 使用 cacheWithLock 进行工业级热点击穿保护
        $data = $this->cache->cacheWithLock(
            $key,
            'lock:readByIp:' . $ip,
            function () use ($ip, $orderby, $fields) {
                return $this->readByIp($ip, $orderby, $fields);
            },
            3,
            $this->ttl
        );

        if (empty($data)) return [];

        $static[$ip] = $data;
        $this->setState('static', $static);
        return $data;
    }

    public function readByUserId(int $userId, array $orderby = [], array $fields = ['*']): array
    {
        $staticUserId = $this->getState('staticUserId', []);
        if (isset($staticUserId[$userId])) return $staticUserId[$userId];
        $data = $this->dbModel->read(['user_id' => $userId], $orderby, $fields);
        if (empty($data)) return [];
        //$data && $this->format($data);
        $staticUserId[$userId] = $data;
        $this->setState('staticUserId', $staticUserId);
        return $data;
    }

    public function readByCacheUserId(int $userId, array $orderby = [], array $fields = ['*']): array
    {
        if (empty($userId)) return [];

        $staticUserId = $this->getState('staticUserId', []);
        if (isset($staticUserId[$userId])) {
            $data = $staticUserId[$userId];
            $this->format($data);
            return $data;
        }

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->dbModel->read(['user_id' => $userId], $orderby, $fields);
            $this->format($data);
            return $data;
        }

        $key = 'readByUserId:' . $userId;
        // 使用 cacheWithLock 进行工业级热点击穿保护
        $data = $this->cache->cacheWithLock(
            $key,
            'lock:readByUserId:' . $userId,
            function () use ($userId, $orderby, $fields) {
                return $this->dbModel->read(['user_id' => $userId], $orderby, $fields);
            },
            3,
            $this->ttl
        );

        if (empty($data)) return [];

        $staticUserId[$userId] = $data;
        $this->setState('staticUserId', $staticUserId);
        $this->format($data);
        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['ip'] ?? '');
            $condition['ip'] = $ip2bin;
        }

        // hook app_Services_IpListService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_IpListService_find_end.php

        return $datalist;
    }

    public function findByIp(?string $ip, bool $desc = true, int $page = 1, int $pageSize = 10, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;
        return $this->find(['ip' => $ip], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
    }

    /**
     * @param int $id
     */
    public function delete($id): int
    {
        // hook app_Services_IpListService_delete_start.php
        $result = $this->dbModel->delete(['id' => $id]);
        // hook app_Services_IpListService_delete_end.php
        return $result;
    }

    public function count( array $condition= []): int
    {
        return $this->dbModel->count($condition);
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_IpListService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_IpListService_maxid_end.php
        return $maxId;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        // hook app_Services_IpListService_format_start.php
        $data['ip'] = isset($data['ip']) ? \Framework\Utils\IpHelper::bin2ip($data['ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $this->i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        // hook app_Services_IpListService_format_end.php
    }

    /**
     * @param array $data
     * @return void
     */
    public function formatData($data)
    {
        // hook app_Services_IpListService_formatData_start.php
        if ($data) {
            foreach ($data as &$item) {
                $this->format($item);
            }
            unset($item);
        }
        return $data;
        // hook app_Services_IpListService_formatData_end.php
    }
}
