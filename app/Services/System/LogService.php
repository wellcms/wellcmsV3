<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\System;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class LogService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\LogModel */
    protected $dbModel;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;

    public function __construct(
        \App\Models\LogModel $dbModel,
        \App\Utils\I18nDateFormatter $i18nDateFmt
    ) {
        // hook app_Services_LogService_construct_start.php
        $this->dbModel = $dbModel;
        $this->i18nDateFmt = $i18nDateFmt;
        // hook app_Services_LogService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);
        if (isset($data['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
            $data['create_ip'] = $ip2bin;
        }
        // hook app_Services_LogService_insert_start.php

        $result = $this->dbModel->insert($data);
        if (!$result) {
            $timeFormat = date('Y-m-d H:i:s');
            throw new BusinessException('LogService - Time:' . $timeFormat . ' / insert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // hook app_Services_LogService_insert_end.php

        return $result;
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_LogService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) {
            $timeFormat = date('Y-m-d H:i:s');
            throw new BusinessException('LogService - Time:' . $timeFormat . ' / bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // hook app_Services_LogService_bulkInsert_end.php

        return $result;
    }

    public function update(int $logid, array $update = []): int
    {
        Validator::make(['id' => $logid, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);
        if (isset($update['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
            $update['create_ip'] = $ip2bin;
        }
        // hook app_Services_LogService_update_start.php

        $result = $this->dbModel->update(['id' => $logid], $update);
        if ($result === 0) {
            $timeFormat = date('Y-m-d H:i:s');
            throw new BusinessException('LogService - Time:' . $timeFormat . ' / update() update failed : ' . json_encode(['id' => $logid, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // hook app_Services_LogService_update_end.php

        return $result;
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        Validator::make(['update' => $update], ['update' => 'required|array']);

        // hook app_Services_LogService_bulkUpdate_start.php

        $result = $this->dbModel->bulkUpdate($update, $keyColumn, $wheres);
        if ($result === 0) {
            $timeFormat = date('Y-m-d H:i:s');
            throw new BusinessException('LogService - Time:' . $timeFormat . ' / bulkUpdate() update failed : ' . json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // hook app_Services_LogService_bulkUpdate_end.php

        return $result;
    }

    public function read(int $logid, array $orderby = [], array $fields = ['*']): array
    {
        if (empty($logid)) return [];

        // hook app_Services_LogService_read_start.php

        $data = $this->dbModel->read(['id' => $logid], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_LogService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);
        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_LogService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_LogService_find_end.php

        return $datalist;
    }

    public function findByFromUserId($fromUserId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_LogService_findByFromUserId_start.php

        $datalist = $this->find(['from_user_id' => $fromUserId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_LogService_findByFromUserId_end.php

        return $datalist;
    }

    public function findByUserId($userId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_LogService_findByUserId_start.php

        $datalist = $this->find(['user_id' => $userId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_LogService_findByUserId_end.php

        return $datalist;
    }

    public function findByTargetId($targetId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_LogService_findByTid_start.php

        $datalist = $this->find(['target_id' => $targetId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_LogService_findByTid_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_LogService_findPaged_start.php

        $datalist = $this->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_LogService_findPaged_before.php

        foreach ($datalist as &$data) {
            $this->format($data);
        }

        // hook app_Services_UseLogService_findPaged_end.php

        return $datalist;
    }

    // 批量删除，关联 recover 表，在 controller 关联完成
    /**
     * @param int $logid
     */
    public function delete($logid): int
    {
        // hook app_Services_LogService_delete_start.php
        $result = $this->dbModel->delete(['id' => $logid]);
        // hook app_Services_LogService_delete_end.php
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
        // hook app_Services_LogService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_LogService_maxid_end.php
        return $maxId;
    }

    // 非重要数据到控制器格式化
    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_LogService_format_start.php
        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        // hook app_Services_LogService_format_end.php
    }
}
