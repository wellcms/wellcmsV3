<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Content;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class RecycleService
{
    /** @var \App\Models\RecycleModel */
    protected $dbModel;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var array */
    protected $recoverCount = [];

    public function __construct(
        \App\Models\RecycleModel $dbModel,
        \App\Utils\I18nDateFormatter $i18nDateFmt
    ) {
        // hook app_Services_RecycleService_construct_start.php
        $this->dbModel = $dbModel;
        $this->i18nDateFmt = $i18nDateFmt;
        // hook app_Services_RecycleService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_RecycleService_insert_start.php

        $result = $this->dbModel->insert($data);
        if (!$result) throw new BusinessException('RecycleService -> insert(): Data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_RecycleService_insert_end.php

        return $result;
    }

    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_RecycleService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('RecycleService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_RecycleService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        // hook app_Services_RecycleService_update_start.php

        $result = $this->dbModel->update(['id' => $id], $update);
        if ($result === 0) throw new BusinessException('RecycleService -> update() Update failed : ' . json_encode(['id' => $id, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_RecycleService_update_end.php

        return $result;
    }

    public function read(int $id, array $orderby = [], array $fields = ['*']): array
    {
        $id = intval($id);
        if (empty($id)) return [];

        // hook app_Services_RecycleService_read_start.php

        $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_RecycleService_read_middle.php

        if ($data) $this->format($data);

        // hook app_Services_RecycleService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        // hook app_Services_RecycleService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_RecycleService_find_end.php

        return $datalist;
    }

    public function findByIds(array $ids, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_RecycleService_findByIds_start.php

        $datalist = $this->dbModel->find(['id' => $ids], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_RecycleService_findByIds_end.php

        return $datalist;
    }

    public function findByUserId(int $userId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_RecycleService_findByUserId_start.php

        $datalist = $this->dbModel->find(['user_id' => $userId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_RecycleService_findByUserId_end.php

        return $datalist;
    }

    /**
     * 从回收站恢复数据
     * @param int $id 回收站记录ID
     * @return array
     */
    public function restore(int $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|int']);

        // hook app_Services_RecycleService_restore_start.php

        $data = $this->read($id);
        if (empty($data)) throw new BusinessException('RecycleService -> restore() Failed : ' . json_encode(['id' => $id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_RecycleService_restore_end.php

        return $data['content_fmt'] ?? [];
    }

    /**
     * 批量清空回收站（物理删除）
     * @param array $ids 回收站记录ID数组
     * @return bool
     */
    public function batchDelete(array $ids): bool
    {
        Validator::make(['ids' => $ids], ['ids' => 'required|array']);

        if (empty($ids)) return true;

        $result = true;
        foreach ($ids as $id) {
            if ($this->delete($id) === 0) {
                $result = false;
            }
        }

        return $result;
    }

    // 存在附件和图片以及云储存，在 controller 关联完成
    public function delete(int $id): int
    {
        // hook app_Services_RecycleService_delete_start.php
        $result = $this->dbModel->delete(['id' => $id]);
        // hook app_Services_RecycleService_delete_end.php
        return $result;
    }

    public function count( array $condition= []): int
    {
        return $this->dbModel->count($condition);
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_RecycleService_format_start.php
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        $data['data_fmt'] = json_decode($data['data'], true);
        if (empty($data['data'])) return;

        $data['data_fmt']['created_at_fmt'] = empty($data['data_fmt']['created_at']) ? '' : $i18nDateFmt->format((int)$data['data_fmt']['created_at'], 'medium', 'none');
        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::safeLong2ip($data['create_ip']) : '0.0.0.0';

        if ($data['content']) {
            $data['content_fmt'] = json_decode($data['content'], true) ?? [];
        }
        // hook app_Services_RecycleService_format_end.php
    }
}
