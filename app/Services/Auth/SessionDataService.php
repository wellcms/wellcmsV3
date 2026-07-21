<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Auth;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class SessionDataService
{
    /** @var \App\Models\SessionDataModel */
    protected $dbModel;

    public function __construct(\App\Models\SessionDataModel $dbModel)
    {
        // hook app_Services_SessionDataService_construct_start.php
        $this->dbModel = $dbModel;
        // hook app_Services_SessionDataService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_SessionDataService_insert_start.php
        if (isset($data['id'])) $data['id'] = hex2bin($data['id']);

        $result = $this->dbModel->insert($data);
        if (!$result) throw new BusinessException('SessionDataService -> insert(): Data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_SessionDataService_insert_end.php

        if (is_int($result)) {
            return $result;
        }
        if (is_numeric($result)) {
            return (int)$result;
        }
        return 1;
    }

    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_SessionDataService_bulkInsert_start.php
        foreach ($data as &$item) {
            if (isset($item['id'])) $item['id'] = hex2bin($item['id']);
        }
        unset($item);

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('SessionDataService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_SessionDataService_bulkInsert_end.php

        return (int)$result;
    }

    public function update(string $sessionId, array $update = []): int
    {
        Validator::make(['id' => $sessionId, 'update' => $update], ['id' => 'required', 'update' => 'required|array']);

        // hook app_Services_SessionDataService_update_start.php
        $binId = hex2bin($sessionId);
        if (isset($update['id'])) $update['id'] = hex2bin($update['id']);

        $result = $this->dbModel->update(['id' => $binId], $update);
        // hook app_Services_SessionDataService_update_end.php
        return (int)$result;
    }

    public function read(string $sessionId, array $orderby = [], array $fields = ['*']): array
    {
        if (empty($sessionId)) return [];

        // hook app_Services_SessionDataService_read_start.php
        $binId = hex2bin($sessionId);

        $data = $this->dbModel->read(['id' => $binId], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_SessionDataService_read_middle.php

        if ($data) $this->format($data);

        // hook app_Services_SessionDataService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['id'])) {
            if (is_array($condition['id'])) {
                $condition['id'] = array_map('hex2bin', $condition['id']);
            } else {
                $condition['id'] = hex2bin($condition['id']);
            }
        }

        // hook app_Services_SessionDataService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_SessionDataService_find_end.php

        return $datalist;
    }

    public function delete($sessionId): int
    {
        // hook app_Services_SessionDataService_delete_start.php
        $ids = is_array($sessionId) ? array_map('hex2bin', $sessionId) : hex2bin($sessionId);
        $result = $this->dbModel->delete(['id' => $ids]);
        // hook app_Services_SessionDataService_delete_end.php
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
        // hook app_Services_SessionDataService_format_start.php
        if (isset($data['id'])) {
            if (is_resource($data['id'])) $data['id'] = stream_get_contents($data['id']);
            $data['id'] = bin2hex($data['id']);
        }
        // hook app_Services_SessionDataService_format_end.php
    }
}
