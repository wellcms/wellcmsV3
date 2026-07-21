<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Content;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class NavigationService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\NavigationModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var array */
    protected $cacheConfig;
    /** @var array */
    protected $navigations = [];
    /** @var array */
    protected $navCount = [];

    public function __construct(
        \App\Models\NavigationModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        array $cacheConfig
    ) {
        // hook app_Services_NavigationService_construct_start.php
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        // hook app_Services_NavigationService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_NavigationService_insert_start.php

        $result = $this->dbModel->insert($data);
        if (!$result) throw new BusinessException('NavigationService -> insert(): Data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_NavigationService_insert_end.php

        return $result;
    }

    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_NavigationService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('NavigationService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_NavigationService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        // hook app_Services_NavigationService_update_start.php

        $result = $this->dbModel->update(['id' => $id], $update);
        if ($result === 0) throw new BusinessException('NavigationService -> update() Update failed : ' . json_encode(['id' => $id, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_NavigationService_update_end.php

        return $result;
    }

    public function read(int $id, array $orderby = [], array $fields = ['*']): array
    {
        $id = intval($id);
        if (empty($id)) return [];

        // hook app_Services_NavigationService_read_start.php

        $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_NavigationService_read_middle.php

        if ($data) $this->format($data);

        // hook app_Services_NavigationService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        // hook app_Services_NavigationService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_NavigationService_find_before.php

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_NavigationService_find_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_NavigationService_findPaged_start.php

        $datalist = $this->dbModel->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_NavigationService_findPaged_before.php

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_NavigationService_findPaged_end.php

        return $datalist;
    }

    /**
     * @param int $id
     */
    public function delete($id): int
    {
        // hook app_Services_NavigationService_delete_start.php
        $result = $this->dbModel->delete(['id' => $id]);
        // hook app_Services_NavigationService_delete_end.php
        return $result;
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_NavigationService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_NavigationService_maxid_end.php
        return $maxId;
    }

    public function count( array $condition= []): int
    {
        $key = md5('navigation:count' . json_encode($condition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $navCount = $this->getState('navCount', []);
        if (isset($navCount[$key])) return $navCount[$key];

        $count = $this->dbModel->count($condition);
        $navCount[$key] = $count;
        $this->setState('navCount', $navCount);
        return $count;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        // hook app_Services_NavigationService_format_start.php

        $data['type'] = (int)($data['type'] ?? 0);
        $data['parent_id'] = (int)($data['parent_id'] ?? 0);
        $data['type_fmt'] = 0 === $data['type'] ? 'Primary' : 'Secondary';

        // hook app_Services_NavigationService_format_end.php
    }

    /**
     * @param array $data
     * @return void
     */
    public function formatData($data)
    {
        // hook app_Services_NavigationService_formatData_start.php
        if ($data) {
            foreach ($data as &$item) {
                $this->format($item);
            }
            unset($item);
        }
        return $data;
        // hook app_Services_NavigationService_formatData_end.php
    }

    /**
     * @return array
     */
    public function findCache()
    {
        $key = 'navigations:findCache';
        $navigations = $this->getState('navigations', []);
        if (isset($navigations[$key])) {
            $data = $navigations[$key];
            return $this->formatData($data);
        }

        // hook app_Services_NavigationService_findCache_start.php
        if (empty($this->cacheConfig['stores'])) {
            $data = $this->find([], [], 1, 1000);
            $navigations[$key] = $data;
            $this->setState('navigations', $navigations);
            return $this->formatData($data);
        }

        $data = $this->cache->cacheWithLock(
            'NavigationList',
            'lock:NavigationList',
            function () {
                return $this->dbModel->find([], [], 1, 1000);
            }
        );

        if ($data) {
            $navigations[$key] = $data;
            $this->setState('navigations', $navigations);
            return $this->formatData($data);
        }

        // hook app_Services_NavigationService_findCache_end.php

        $data = $this->find([], [], 1, 1000);
        $navigations[$key] = $data;
        $this->setState('navigations', $navigations);
        return $this->formatData($data);
    }
}
