<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Auth;

use Framework\Utils\Validator;

class GroupService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\GroupModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var array */
    protected $cacheConfig;
    /** @var int */
    protected $cacheTtl = 7200;

    public function __construct(
        \App\Models\GroupModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        array $cacheConfig
    ) {
        // hook app_Services_GroupService_construct_start.php
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        $this->cacheTtl = $this->cacheConfig['cache_ttl'] ?? 7200;
        // hook app_Services_GroupService_construct_end.php
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_GroupService_insert_start.php

        $result = $this->dbModel->insert($data);
        //if (!$result) throw new \Framework\Exception\BusinessException('GroupService -> / insert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $data['id'] = $result;
        $this->format($data);

        $groups = $this->getState('groups', []);
        $groups[$result] = $data;
        $this->setState('groups', $groups);

        if (!empty($this->cacheConfig['stores'])) {
            $this->cache->delete('GroupList');
        }

        // hook app_Services_GroupService_insert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        //Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        // hook app_Services_GroupService_update_start.php

        $result = $this->dbModel->update(['id' => $id], $update);
        if ($result === 0) throw new \Framework\Exception\BusinessException('GroupService -> / update() update failed : ' . json_encode(['id' => $id, 'update' => $update], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $groups = $this->getState('groups', []);
        if (isset($groups[$id])) {
            $groups[$id] = array_merge($groups[$id], $update);
            $this->setState('groups', $groups);
        }

        if (!empty($this->cacheConfig['stores'])) {
            $this->cache->delete('GroupList');
        }

        // hook app_Services_GroupService_update_end.php

        return $result;
    }

    public function read(int $id, array $orderby = [], array $fields = ['*']): array
    {
        // hook app_Services_GroupService_read_start.php
        $groups = $this->getState('groups', []);
        if (isset($groups[$id])) return $groups[$id];

        $groups = $this->findCacheList();
        $data = $groups[$id] ?? [];

        // hook app_Services_GroupService_read_end.php

        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_GroupService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        $groups = $this->getState('groups', []);
        foreach ($datalist as &$data) {
            $this->format($data);
            $groups[$data['id']] = $data;
        }
        unset($data);
        $this->setState('groups', $groups);

        // hook app_Services_GroupService_find_end.php

        return $datalist;
    }

    public function findCacheList(): array
    {
        $groups = (array)$this->getState('groups', []);
        if (!empty($groups)) {
            return (array)$this->formatData($groups);
        }
        // hook app_Services_GroupService_findCacheList_start.php
        if (empty($this->cacheConfig['stores'])) {
            $groups = $this->find([], [], 1, 1000);
            return (array)$this->formatData($groups);
        }

        $groups = (array)$this->cache->cacheWithLock(
            'GroupList',
            'lock:GroupList',
            function () {
                return $this->find([], [], 1, 1000);
            }
        );
        $this->setState('groups', $groups);

        //$groups = $this->getState('groups', []);
        if (!empty($groups)) {
            return (array)$this->formatData($groups);
        }

        // hook app_Services_GroupService_findCacheList_end.php

        $data = (array)$this->dbModel->find([], [], 1, 1000);
        return (array)$this->formatData($data);
    }

    /**
     * @param int $id
     */
    public function name($id): string
    {
        $groups = $this->getState('groups', $this->findCacheList());
        return $groups[$id]['name'] ?? '';
    }

    // 不支持批量删除
    /**
     * @param int $id
     */
    public function delete($id): int
    {
        // hook app_Services_GroupService_delete_start.php

        $data = $this->read($id);
        if (empty($data)) return 0;

        // hook app_Services_GroupService_delete_before.php

        $result = $this->dbModel->delete(['id' => $id]);
        if ($result === 0) return 0;

        // hook app_Services_GroupService_delete_center.php

        if (!empty($this->cacheConfig['stores'])) {
            $groups = $this->getState('groups', $this->findCacheList());

            if (isset($groups[$id])) {
                unset($groups[$id]);
            }
            $this->setState('groups', $groups);

            $groupsData = $groups ?: '';
            $this->cache->set('GroupList', $groupsData);
        }

        // hook app_Services_GroupService_delete_end.php

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
        // hook app_Services_GroupService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_GroupService_maxid_end.php
        return $maxId;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        // hook app_Services_GroupService_format_start.php
        // hook app_Services_GroupService_format_end.php
    }

    /**
     * @param array $data
     * @return array
     */
    public function formatData(array $data): array
    {
        // hook app_Services_GroupService_formatData_start.php
        if ($data) {
            foreach ($data as &$item) {
                $this->format($item);
            }
            unset($item);
        }
        // hook app_Services_GroupService_formatData_end.php
        return $data;
    }

    /**
     * @param int $id
     * @param string $field
     * @return bool
     */
    public function access(int $id, string $field): bool
    {
        if (empty($field)) return false;

        // hook app_Services_GroupService_access_start.php

        $key = 'groupAccess_' . $id . '-' . $field;
        $staticAccess = $this->getState('staticAccess', []);
        if (isset($staticAccess[$key])) return $staticAccess[$key];
        if (1 === $id || 3 === (int)\DEBUG) return true;

        $groups = $this->getState('groups', $this->findCacheList());
        if (!isset($groups[$id])) return false;

        $group = $groups[$id];
        if (!isset($group[$field]) || empty($group[$field])) return false;

        $staticAccess[$key] = true;
        $this->setState('staticAccess', $staticAccess);

        // hook app_Services_GroupService_access_end.php

        return true;
    }
}
