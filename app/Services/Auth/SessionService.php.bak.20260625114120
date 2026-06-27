<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Auth;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;

class SessionService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\SessionModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var array 应用配置 */
    protected $cacheConfig;
    /** @var array 应用配置 */
    protected $sessionConfig;
    /** @var int 在线保持时间 (秒) */
    protected $sessionsExpire;

    public function __construct(
        \App\Models\SessionModel $dbModel,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        array $cacheConfig,
        array $sessionConfig
    ) {
        // hook app_Services_SessionService_construct_start.php
        $this->dbModel = $dbModel;
        $this->cache = $cache;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->cacheConfig = $cacheConfig;
        $this->sessionConfig = $sessionConfig;
        $this->sessionsExpire = $this->sessionConfig['online_hold_time'] ?? 3600;
        // hook app_Services_SessionService_construct_end.php
    }

    /**
     * 捕获请求上下文
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $sessionId = $request->getCookieParams()[$this->sessionConfig['pre'] . 'session_id'] ?? '';
        if (empty($sessionId)) {
            $session = $request->getAttribute(\Framework\Session\SessionInterface::class);
            if ($session instanceof \Framework\Session\SessionInterface) {
                $sessionId = $session->getId();
            }
        }
        $this->setState('session_id', $sessionId);
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        $sessionId = $data['id'] ?? '';
        if (empty($sessionId)) return 0;

        $lockKey = 'lock:session:insert:' . $sessionId;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $binId = hex2bin($sessionId);
            $exists = $this->dbModel->read(['id' => $binId]);
            if (!empty($exists)) return 1; // 已存在

            if (isset($data['ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['ip'] ?? '');
                $data['ip'] = $ip2bin;
            }

            $data['id'] = $binId;
            $result = $this->dbModel->insert($data);
            if (!$result) throw new BusinessException('SessionService -> insert(): Result:' . $result . ' / Data writing failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('SessionHandler:' . $sessionId);
                if (!empty($data['user_id'])) {
                    $this->cache->delete('session_readByUserId:' . $data['user_id']);
                }
            }

            return 1;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_SessionService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('SessionService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_SessionService_bulkInsert_end.php

        return $result;
    }

    public function update(string $sessionId, array $update = []): int
    {
        Validator::make(['id' => $sessionId, 'update' => $update], ['id' => 'required', 'update' => 'required|array']);

        $lockKey = 'lock:session:update:' . $sessionId;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $binId = hex2bin($sessionId);
            $oldData = $this->dbModel->read(['id' => $binId]);
            if (empty($oldData)) return 0;

            if (isset($update['ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['ip'] ?? '');
                $update['ip'] = $ip2bin;
            }

            if (isset($update['id'])) $update['id'] = hex2bin($update['id']);

            $result = $this->dbModel->update(['id' => $binId], $update);

            // 强一致性要求：清除缓存 (无论是否影响行数，只要没抛异常就执行)
            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('SessionHandler:' . $sessionId);
                $this->cache->delete('session_read_' . $sessionId);

                $uId = $update['user_id'] ?? $oldData['user_id'] ?? 0;
                if ($uId) {
                    $this->cache->delete('session_readByUserId:' . $uId);
                }

                // 如果用户ID发生了变动
                if (isset($update['user_id']) && $update['user_id'] != $oldData['user_id']) {
                    $oldData['user_id'] && $this->cache->delete('session_readByUserId:' . $oldData['user_id']);
                }
            }

            return (int)$result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function read(string $sessionId = '', array $orderby = [], array $fields = ['*']): array
    {
        if (empty($sessionId)) return [];

        $static = $this->getState('static', []);
        if (isset($static[$sessionId])) return $static[$sessionId];

        // hook app_Services_SessionService_read_start.php

        $binId = hex2bin($sessionId);
        $data = $this->dbModel->read(['id' => $binId], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_SessionService_read_middle.php

        if ($data) $this->format($data);

        // hook app_Services_SessionService_read_end.php

        $static[$sessionId] = $data;
        $this->setState('static', $static);
        return $data;
    }

    public function readByCache(string $sessionId = '', array $orderby = [], array $fields = ['*']): array
    {
        !$sessionId && $sessionId = $this->getState('session_id');

        $static = $this->getState('static', []);
        if (isset($static[$sessionId])) {
            $data = $static[$sessionId];
            $this->format($data);
            return $data;
        }

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->read($sessionId, $orderby, $fields);
            $this->format($data);
            return $data;
        }

        // 使用 cacheWithLock 进行工业级热点击穿保护
        $data = $this->cache->cacheWithLock(
            'SessionHandler:' . $sessionId,
            'lock:SessionHandler:' . $sessionId,
            function () use ($sessionId, $orderby, $fields) {
                return $this->read($sessionId, $orderby, $fields);
            },
            3,
            $this->sessionsExpire
        );

        $static[$sessionId] = $data;
        $this->setState('static', $static);
        $this->format($data);
        return $data;
    }

    public function readByUserId(int $userId, array $orderby = ['updated_at' => -1], array $fields = ['*']): array
    {
        $staticUserId = $this->getState('staticUserId', []);
        if (isset($staticUserId[$userId])) return $staticUserId[$userId];
        $data = $this->dbModel->read(['user_id' => $userId], $orderby, $fields);
        if (empty($data)) return [];
        $staticUserId[$userId] = $data;
        $this->setState('staticUserId', $staticUserId);
        return $data;
    }

    public function readByCacheUserId(int $userId, array $orderby = ['updated_at' => -1], array $fields = ['*']): array
    {
        if (!$userId) return [];

        $staticUserId = $this->getState('staticUserId', []);
        if (isset($staticUserId[$userId])) {
            $data = $staticUserId[$userId];
            $this->format($data);
            return $data;
        }

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->readByUserId($userId, $orderby, $fields);
            $this->format($data);
            return $data;
        }

        $key = 'session_readByUserId:' . $userId;
        // 使用 cacheWithLock 进行工业级热点击穿保护
        $data = $this->cache->cacheWithLock(
            $key,
            'lock:session_read_by_UserID:' . $userId,
            function () use ($userId, $orderby, $fields) {
                return $this->dbModel->read(['user_id' => $userId], $orderby, $fields);
            },
            3,
            $this->sessionsExpire
        );

        if (empty($data)) return [];

        $staticUserId[$userId] = $data;
        $this->setState('staticUserId', $staticUserId);
        $this->format($data);
        return $data;
    }

    public function readByIp(string $ip, array $orderby = ['updated_at' => 1], array $fields = ['*']): array
    {
        if ($ip) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($ip);
        }

        $staticIp = $this->getState('staticIp', []);
        if (isset($staticIp[$ip])) return $staticIp[$ip];

        $data = $this->dbModel->read(['ip' => $ip2bin], $orderby, $fields);
        if (empty($data)) return [];

        $staticIp[$ip] = $data;
        $this->setState('staticIp', $staticIp);
        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['orderby' => $orderby], ['orderby' => 'array']);

        if (isset($condition['ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['ip'] ?? '');
            $condition['ip'] = $ip2bin;
        }

        if (isset($condition['id'])) {
            if (is_array($condition['id'])) {
                $condition['id'] = array_map('hex2bin', $condition['id']);
            } else {
                $condition['id'] = hex2bin($condition['id']);
            }
        }

        // hook app_Services_SessionService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_SessionService_find_end.php

        return $datalist;
    }

    public function findByLastdate($updated_at, bool $desc = true, int $page = 1, int $pageSize = 10, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;
        return $this->find(['updated_at' => $updated_at], ['updated_at' => $orderby], $page, $pageSize, $indexKey, $fields);
    }

    public function findByIp($ip, bool $desc = true, int $page = 1, int $pageSize = 10, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;
        return $this->find(['ip' => $ip], ['updated_at' => $orderby], $page, $pageSize, $indexKey, $fields);
    }

    public function delete($sessionId): int
    {
        if (empty($sessionId)) return 0;
        // hook app_Services_SessionService_delete_start.php
        $ids = is_array($sessionId) ? array_map('hex2bin', $sessionId) : hex2bin($sessionId);
        $result = $this->dbModel->delete(['id' => $ids]);
        if ($result === 0) return $result;
        // clear the cache
        if (is_array($sessionId)) {
            foreach ($sessionId as $sid) {
                $this->cache->delete('session_read_' . $sid);
                $this->cache->delete('SessionHandler:' . $sid);
            }
        } else {
            $this->cache->delete('session_read_' . $sessionId);
            $this->cache->delete('SessionHandler:' . $sessionId);
        }
        // hook app_Services_SessionService_delete_end.php
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
        // hook app_Services_SessionService_format_start.php
        if (isset($data['id'])) {
            if (is_resource($data['id'])) $data['id'] = stream_get_contents($data['id']);
            $data['id'] = bin2hex($data['id']);
        }

        $data['ip'] = isset($data['ip']) ? \Framework\Utils\IpHelper::bin2ip($data['ip']) : '0.0.0.0';

        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $this->i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        $data['updated_at_fmt'] = empty($data['updated_at']) ? '' : $this->i18nDateFmt->format((int)$data['updated_at'], 'medium', 'none');
        // hook app_Services_SessionService_format_end.php
    }

    /**
     * @param array $data
     * @return void
     */
    public function formatData($data)
    {
        // hook app_Services_SessionService_formatData_start.php
        if ($data) {
            foreach ($data as &$item) {
                $this->format($item);
            }
            unset($item);
        }
        return $data;
        // hook app_Services_SessionService_formatData_end.php
    }

    /**
     * 标记用户在线 (Industrial Grade)
     * 1. 使用 BitMap 记录“是否在线” (极省空间)
     * 2. 使用 ZSET 记录“活跃时间” (支持排序名单)
     */
    public function markOnline(int $userId): void
    {
        if ($userId <= 0) return;

        // 避免重复打标 (Request-level cache)
        if ($this->getState('alreadyMarked:' . $userId)) return;
        $this->setState('alreadyMarked:' . $userId, true);

        $ttl = $this->sessionsExpire;
        $now = time();

        if (($this->cacheConfig['stores']['redis'] ?? '') === 'redis') {
            // A. 位图打标 (用于高速 isOnline 判断)
            $window = (int)($now / 600);
            $key = 'online:bits:' . $window;
            $this->cache->setBit($key, $userId, 1);
            $this->cache->expire($key, $this->sessionsExpire + 600);

            // B. 有序集合 (用于在线名单展示)
            $zKey = 'online:active_zset';
            $this->cache->zAdd($zKey, [$now => $userId]);
            // 每 100 次打标清理一次过期数据，维持 ZSET 大小
            if (random_int(1, 100) === 1) {
                $this->cache->zRemRangeByScore($zKey, '-inf', (string)($now - $this->sessionsExpire));
            }
        } else {
            // 方案 B 降级：通用缓存后端使用个体独立键
            $this->cache->set('online:u:' . $userId, 1, $this->sessionsExpire);
        }
    }

    /**
     * 判断用户是否在线
     */
    public function isOnline(int $userId): bool
    {
        if ($userId <= 0) return false;

        // 1. 优先从当前请求的批量缓存中获取 (O(1) Memory)
        $preloaded = $this->getState('onlineMasks');
        if (isset($preloaded[$userId])) return (bool)$preloaded[$userId];

        // 2. 实时探测
        if (($this->cacheConfig['stores']['redis'] ?? '') === 'redis') {
            // OR 运算检查最近两个时间窗口
            $window = (int)(time() / 600);
            return (bool)($this->cache->getBit('online:bits:' . $window, $userId)
                || $this->cache->getBit('online:bits:' . ($window - 1), $userId));
        }

        return (bool)$this->cache->get('online:u:' . $userId);
    }

    /**
     * 批量预载在线状态 (解决 N+1 问题)
     */
    public function preloadOnlineStatus(array $userids): void
    {
        if (empty($userids)) return;
        $userids = array_unique(array_filter(array_map('intval', $userids)));
        if (empty($userids)) return;

        $masks = $this->getState('onlineMasks', []);

        if (($this->cacheConfig['stores']['redis'] ?? '') === 'redis') {
            // Redis 环境下，如果 UserID 较多，建议使用逻辑 OR 或 pipeline，此处简化处理
            foreach ($userids as $userid) {
                if (!isset($masks[$userid])) $masks[$userid] = $this->isOnline($userid);
            }
        } else {
            // 非 Redis 环境，使用 MGET 优化
            $keys = array_map(function ($id) {
                return 'online:u:' . $id;
            }, $userids);
            $values = method_exists($this->cache, 'getMulti') ? $this->cache->getMulti($keys) : [];

            foreach ($userids as $index => $userid) {
                $masks[$userid] = !empty($values['online:u:' . $userid] ?? $values[$index] ?? null);
            }
        }

        $this->setState('onlineMasks', $masks);
    }

    /**
     * 获取在线人数统计
     */
    public function onlineCount(): int
    {
        if (method_exists($this->cache, 'zCount')) {
            return $this->cache->zCount('online:active_zset', (string)(time() - $this->sessionsExpire), '+inf');
        }

        return $this->dbModel->count(['updated_at' => ['>' => time() - $this->sessionsExpire]]);
    }

    /**
     * 获取在线列表 (Industrial Grade)
     * 1. 优先从 Redis ZSET 获取最近活跃名单 (O(log N))
     * 2. 降级回退到数据库查询
     */
    public function online_list_cache()
    {
        $key = 'Session:onlineList';
        $sessionList = $this->getState('sessionList', []);
        if (!empty($sessionList[$key])) return $sessionList[$key];

        $list = [];
        if (method_exists($this->cache, 'zRevRange')) {
            // 从 ZSET 获取最近活跃的 100 个用户 UserID
            $userIds = $this->cache->zRevRange('online:active_zset', 0, 99);
            if (!empty($userIds)) {
                // 批量获取用户信息 (这会自动触发 UserService 的 readCache) - 这里的 $list 格式需符合业务预期
                // 注意：这里仅返回 UserID 集合作为索引，或者由于 online_list_cache 通常被用于展示，
                // 我们调用 find 进行数据库补全（但此时走索引且数据量极小）
                $list = $this->find(['user_id' => $userIds], ['updated_at' => -1], 1, 100, 'user_id');
            }
        }

        // 兜底方案：如果 ZSET 为空或不支持，则从数据库有限拉取
        if (empty($list)) {
            $list = $this->find(['user_id' => ['>' => 0]], ['updated_at' => -1], 1, 100, 'user_id');
        }

        $sessionList[$key] = $list;
        $this->setState('sessionList', $sessionList);

        return $list;
    }
}
