<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class UploadLogService
{
    /** @var \App\Models\UploadLogModel */
    protected $dbModel;
    /** @var string */
    protected $timeFormat;
    /** @var int */
    protected $maxId;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;

    public function __construct(
        \App\Models\UploadLogModel $dbModel,
        \App\Utils\I18nDateFormatter $i18nDateFmt
    ) {
        // hook app_Services_UploadLogService_construct_start.php
        $this->dbModel = $dbModel;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->timeFormat = date('Y-m-d H:i:s');
        // hook app_Services_UploadLogService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_UploadLogService_insert_start.php

        $result = $this->dbModel->insert($data);
        if (!$result) throw new BusinessException('UploadLogService - Time:' . $this->timeFormat . ' / insert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UploadLogService_insert_end.php

        return $result;
    }

    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_UploadLogService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('UploadLogService - Time:' . $this->timeFormat . ' / bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UploadLogService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        // hook app_Services_UploadLogService_update_start.php

        $result = $this->dbModel->update(['id' => $id], $update);
        if ($result === 0) throw new BusinessException('UploadLogService - Time:' . $this->timeFormat . ' / update() update failed : ' . json_encode(['id' => $id, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UploadLogService_update_end.php

        return $result;
    }

    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        Validator::make(['update' => $update], ['update' => 'required|array']);

        // hook app_Services_UploadLogService_bulkUpdate_start.php

        $result = $this->dbModel->bulkUpdate($update, $keyColumn, $wheres);
        if ($result === 0) throw new BusinessException('UploadLogService - Time:' . $this->timeFormat . ' / bulkUpdate() update failed : ' . json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UploadLogService_bulkUpdate_end.php

        return $result;
    }

    public function read(int $id, array $orderby = [], array $fields = ['*']): array
    {
        $id = intval($id);
        if (empty($id)) return [];

        // hook app_Services_UploadLogService_read_start.php

        $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_UploadLogService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        // hook app_Services_UploadLogService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_UploadLogService_find_end.php

        return $datalist;
    }

    public function findByUserId($userId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_UploadLogService_findByUserId_start.php

        $datalist = $this->dbModel->find(['user_id' => $userId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_UploadLogService_findByUserId_end.php

        return $datalist;
    }

    // 批量删除，关联 recover 表，在 controller 关联完成
    /**
     * @param int $id
     */
    public function delete($id): int
    {
        // hook app_Services_UploadLogService_delete_start.php
        $result = $this->dbModel->delete(['id' => $id]);
        // hook app_Services_UploadLogService_delete_end.php
        return $result;
    }

    public function count( array $condition= []): int
    {
        return $this->dbModel->count($condition);
    }

    public function maxid(): int
    {
        if (null !== $this->maxId) return $this->maxId;
        // hook app_Services_UploadLogService_maxid_start.php
        $this->maxId = $this->dbModel->maxid();
        // hook app_Services_UploadLogService_maxid_end.php
        return $this->maxId;
    }

    // 非重要数据到控制器格式化
    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_UploadLogService_format_start.php
        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::safeLong2ip($data['create_ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        // hook app_Services_UploadLogService_format_end.php
    }
}
