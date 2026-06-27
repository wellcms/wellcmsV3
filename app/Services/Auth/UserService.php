<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Auth;

use Framework\Exception\BusinessException;
use Framework\Utils\{IpHelper, Validator};

class UserService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\UserModel */
    protected $dbModel;
    /** @var \App\Services\Auth\SessionService */
    protected $sessionService;
    /** @var \App\Services\Auth\GroupService */
    protected $groupService;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \Framework\Http\Routing\UrlGeneratorInterface */
    protected $urlGenerator;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var \App\Services\Storage\StorageManager */
    protected $storageManager;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var array */
    protected $appConfig;
    /** @var array */
    protected $cacheConfig;
    /** @var array */
    protected $sessionConfig;
    /** @var int */
    protected $cacheTtl;
    /** @var array */
    protected $guest;

    public function __construct(\Framework\Core\Container $container)
    {
        // hook app_Services_UserService_construct_start.php

        $this->container = $container;

        $this->dbModel        = $container->get(\App\Models\UserModel::class);
        $this->sessionService = $container->get(\App\Services\Auth\SessionService::class);
        $this->groupService   = $container->get(\App\Services\Auth\GroupService::class);
        $this->cache          = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
        $this->appConfig      = $container->get('appConfig');
        $this->cacheConfig    = $container->get('cacheConfig');
        $this->sessionConfig  = $container->get('sessionConfig');
        $this->urlGenerator   = $container->get(\Framework\Http\Routing\UrlGeneratorInterface::class);
        $this->i18nDateFmt    = $container->get(\App\Utils\I18nDateFormatter::class);
        $this->storageManager = $container->get(\App\Services\Storage\StorageManager::class);

        $this->cacheTtl = $this->cacheConfig['user_ttl'] ?? 7200;

        // hook app_Services_UserService_construct_end.php
    }

    /**
     * 快照捕获：从当前请求中提取必要信息
     * 消除对 RequestUtils 的依赖
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $this->setState('capturedToken', $request->getCookieParams()[$this->sessionConfig['pre'] . 'token'] ?? '');
        $this->setState('capturedUa', $request->getServerParams()['HTTP_USER_AGENT'] ?? '');
        $this->setState('capturedIp', IpHelper::ip($request->getServerParams()));
        $this->setState('session', $request->getAttribute(\Framework\Session\SessionInterface::class));
        $this->setState('language', $request->getAttribute(\App\Interfaces\LanguageLoaderInterface::class));
    }

    protected function getLanguage(): ?\App\Interfaces\LanguageLoaderInterface
    {
        return $this->getState('language')
            ?? $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
    }

    /**
     * 更新用户头像 (封装上传、存储、云同步、清理旧头像)
     * 工业级约定：后端强制重编码为 PNG，统一扩展名。
     */
    public function updateAvatar(int $userId, \Framework\Http\Interfaces\UploadedFileInterface $file): string
    {
        $user = $this->read($userId);
        if (empty($user)) throw new BusinessException('User not found');

        $uploadConfig = $this->container->get('uploadConfig');
        $localRoot = $uploadConfig['disks']['local']['root'];
        $language = $this->getLanguage();

        // 1. 准备目录: avatar/YYYYMM/
        $ym = date('Ym');
        $relativeDir = 'avatar' . DIRECTORY_SEPARATOR . $ym . DIRECTORY_SEPARATOR;

        // 2. 以时间戳命名，扩展名强制为 .png
        $timestamp = (string)time();
        $targetFilename = $timestamp . '.png';
        $relativeKey = $relativeDir . $targetFilename;
        $targetPath = $localRoot . $relativeKey;

        // 3. 先将上传文件安全落地到临时目录
        $tempDir = $localRoot . 'temp' . DIRECTORY_SEPARATOR;
        $tmpPath = $tempDir . uniqid('avatar_', true);

        try {
            if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
                $msg = $language ? $language->get('write_to_file_failed') : 'write_to_file_failed';
                throw new BusinessException($msg, 14);
            }

            $file->moveTo($tmpPath);

            // 4. 校验真实图片类型，拒绝伪装文件
            $imageInfo = @getimagesize($tmpPath);
            if (!$imageInfo) {
                $msg = $language ? $language->get('upload_failed') : 'upload_failed';
                throw new BusinessException($msg, 15);
            }

            $allowedTypes = [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG];
            if (defined('IMAGETYPE_BMP')) $allowedTypes[] = IMAGETYPE_BMP;
            if (defined('IMAGETYPE_WEBP')) $allowedTypes[] = IMAGETYPE_WEBP;
            if (!in_array($imageInfo[2], $allowedTypes, true)) {
                $msg = $language ? $language->get('upload_failed') : 'upload_failed';
                throw new BusinessException($msg, 15);
            }

            // 5. 创建目标目录
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $msg = $language ? $language->get('write_to_file_failed') : 'write_to_file_failed';
                throw new BusinessException($msg, 14);
            }

            // 6. 强制重编码为 PNG（优先 Imagick，降级 GD）
            if (extension_loaded('imagick')) {
                $imagick = new \Imagick($tmpPath);
                // 限制最大分辨率，防止超大图消耗内存
                $imagick->thumbnailImage(1024, 1024, true);
                $imagick->setImageFormat('PNG');
                $imagick->setImageCompressionQuality(95);
                $imagick->stripImage(); // 去除 EXIF/ICC/注释等元数据
                $imagick->writeImage($targetPath);
                $imagick->clear();
                $imagick->destroy();
            } else {
                $imageType = $imageInfo[2];
                if ($imageType === IMAGETYPE_GIF) {
                    $src = imagecreatefromgif($tmpPath);
                } elseif ($imageType === IMAGETYPE_JPEG) {
                    $src = imagecreatefromjpeg($tmpPath);
                } elseif ($imageType === IMAGETYPE_PNG) {
                    $src = imagecreatefrompng($tmpPath);
                } elseif (defined('IMAGETYPE_BMP') && $imageType === IMAGETYPE_BMP) {
                    $src = imagecreatefrombmp($tmpPath);
                } elseif (defined('IMAGETYPE_WEBP') && $imageType === IMAGETYPE_WEBP) {
                    $src = imagecreatefromwebp($tmpPath);
                } else {
                    $src = false;
                }
                if (!$src) {
                    $msg = $language ? $language->get('upload_failed') : 'upload_failed';
                    throw new BusinessException($msg, 15);
                }
                imagepng($src, $targetPath, 6);
                imagedestroy($src);
            }
        } finally {
            // 7. 清理临时文件
            if (file_exists($tmpPath) && strpos($tmpPath, $tempDir) === 0) {
                @unlink($tmpPath);
            }
        }

        // 8. 更新数据库 (状态 0 为本地)
        $this->update($userId, [
            'avatar' => $timestamp,
            'avatar_status' => 0
        ]);

        // 9. 云存储同步
        if ($uploadConfig['default'] !== 'local') {
            $taskManage = null;
            if ($this->container->has(\Framework\Scheduler\TaskManage::class)) {
                try {
                    $taskManage = $this->container->get(\Framework\Scheduler\TaskManage::class);
                } catch (\Throwable $e) {
                    // Scheduler/TaskManage 需要 Redis，静默降级（与 PartitionManager 一致）
                }
            }

            if ($taskManage) {
                $localUrl = $uploadConfig['disks']['local']['url'] ?? '/upload/';
                $cloudKey = ltrim($localUrl, '/') . 'avatar/' . $ym . '/' . $targetFilename;
                $taskManage->createTask([
                    'className' => \App\Jobs\SyncAvatarToCloudJob::class,
                    'methodName' => 'handle',
                    'args' => [
                        'userId' => $userId,
                        'localPath' => $targetPath,
                        'cloudKey' => $cloudKey,
                        'uploadConfig' => $uploadConfig
                    ],
                    'priority' => 5,
                    'maxRetries' => 3,
                    'retryDelay' => 10
                ]);
            } else {
                $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
                $logger->info('Avatar cloud sync skipped: scheduler/TaskManage unavailable');
            }
        }

        // 10. 清理旧头像 (支持云端同步删除)
        if (!empty($user['avatar']) && (int)$user['avatar'] !== (int)$timestamp) {
            $this->storageManager->deleteAvatar((int)$user['avatar'], (int)($user['avatar_status'] ?? 0));
        }

        return $this->storageManager->getAvatarUrl((int)$timestamp, 0);
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        $username = $data['username'] ?? '';
        $email = $data['email'] ?? '';
        if (!$username) return 0;

        // 组合锁：防止用户名或邮箱并发重复
        $lockKeys = ['lock:user:reg:name:' . md5($username)];
        if ($email) $lockKeys[] = 'lock:user:reg:email:' . md5($email);

        $tokens = [];
        foreach ($lockKeys as $lk) {
            $t = $this->cache->lock($lk, 10) ?? null;
            if (!$t) {
                // 释放已获取的锁
                foreach ($tokens as $tlk => $tt) $this->cache->unlock($tlk, $tt);
                return 0;
            }
            $tokens[$lk] = $t;
        }

        try {
            // 锁内检查唯一性
            if ($this->dbModel->read(['username' => $username])) throw new BusinessException('Username already exists');
            if ($email && $this->dbModel->read(['email' => $email])) throw new BusinessException('Email already exists');

            if (isset($data['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
                $data['create_ip'] = $ip2bin;
            }

            if (isset($data['login_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['login_ip'] ?? '');
                $data['login_ip'] = $ip2bin;
            }

            $result = $this->dbModel->insert($data);
            if (!$result) throw new BusinessException('UserService -> insert() data writing failed');

            $data['id'] = $result;
            $this->format($data);

            $users = $this->getState('users', []);
            $users[$result] = $data;
            $this->setState('users', $users);

            // 同步冗余统计（失败静默处理，不影响主业务）
            $this->syncUserCount(1);

            return $result;
        } finally {
            foreach ($tokens as $lk => $t) $this->cache->unlock($lk, $t);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_UserService_bulkInsert_start.php

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('UserService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UserService_bulkInsert_end.php

        return (int)$result;
    }

    public function update(int $userId, array $update = []): int
    {
        Validator::make(['id' => $userId, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        $lockKey = 'lock:user:update:' . $userId;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            if (isset($update['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
                $update['create_ip'] = $ip2bin;
            }

            if (isset($update['login_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['login_ip'] ?? '');
                $update['login_ip'] = $ip2bin;
            }

            $result = $this->dbModel->update(['id' => $userId], $update);
            if ($result === 0) throw new BusinessException('UserService->update(' . $userId . ',' . json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ') update failed');

            // 强一致性要求：更新 DB 后必须删除缓存
            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('user-' . $userId);
            }

            $users = $this->getState('users', []);
            if (isset($users[$userId])) {
                // FIX: array_merge 对增量语法（如 store_balance+ / store_balance_frozen+）
                // 的处理有缺陷：会将 'store_balance+' 作为新键插入协程内存，
                // 而不是更新原键 'store_balance' 的值，导致 readByCache() 返回脏数据。
                // 此处手动解析增量语法，确保协程内存与数据库状态一致。
                foreach ($update as $key => $value) {
                    if (is_string($key) && preg_match('/^(.+)([+-])$/', $key, $matches)) {
                        $baseKey = $matches[1];
                        $operator = $matches[2];
                        $current = isset($users[$userId][$baseKey]) ? (float)$users[$userId][$baseKey] : 0.0;
                        $delta = ($operator === '+') ? (float)$value : -(float)$value;
                        $users[$userId][$baseKey] = $current + $delta;
                    } else {
                        $users[$userId][$key] = $value;
                    }
                }

                // 清理可能被之前版本污染的增量键（如 store_balance+）
                foreach (array_keys($users[$userId]) as $stateKey) {
                    if (is_string($stateKey) && preg_match('/[+-]$/', $stateKey)) {
                        unset($users[$userId][$stateKey]);
                    }
                }

                $this->setState('users', $users);
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

        // hook app_Services_UserService_bulkUpdate_start.php

        $result = $this->dbModel->bulkUpdate($update, $keyColumn, $wheres);
        if ($result === 0) throw new BusinessException('UserService -> bulkUpdate() update failed : ' . json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // hook app_Services_UserService_bulkUpdate_end.php

        return $result;
    }

    public function read(int $userId, array $orderby = [], array $fields = ['*']): array
    {
        if (!$userId) return [];

        $users = $this->getState('users', []);
        // 防守状态污染：如果请求全字段但缓存不完整，强制绕过缓存回表
        if (isset($users[$userId]) && (!in_array('*', $fields) || isset($users[$userId]['login_version']))) {
            return $users[$userId];
        }

        // hook app_Services_UserService_read_start.php

        $data = $this->dbModel->read(['id' => $userId], $orderby, $fields);
        if (empty($data)) return [];

        // hook app_Services_UserService_read_middle.php

        if ($data) {
            $this->format($data);
            $users[$userId] = $data;
            $this->setState('users', $users);
        }

        // hook app_Services_UserService_read_end.php

        return $data;
    }

    public function readByCache(int $userId, array $orderby = [], array $fields = ['*']): array
    {
        if (empty($userId)) return [];

        $users = $this->getState('users', []);
        if (isset($users[$userId])) {
            $data = $users[$userId];
            // 防守状态污染：如果请求的是全字段但内存中缺失核心字段，强制绕过内存缓存重新加载
            if (!in_array('*', $fields) || isset($data['login_version'])) {
                $this->format($data);
                return $data;
            }
        }

        // hook app_Services_UserService_readByCache_start.php

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->read($userId, $orderby, $fields);
        } else {
            // 使用 cacheWithLock 进行工业级热点击穿保护
            $data = $this->cache->cacheWithLock(
                'user-' . $userId,
                'lock:user:read:' . $userId,
                function () use ($userId, $orderby, $fields) {
                    return $this->read($userId, $orderby, $fields);
                },
                5,
                $this->cacheTtl
            );
        }

        if (empty($data)) return [];

        // hook app_Services_UserService_readByCache_after.php

        $users[$userId] = $data;
        $this->setState('users', $users);

        // hook app_Services_UserService_readByCache_end.php
        $this->format($data);
        return $data;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        if (isset($condition['login_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['login_ip'] ?? '');
            $condition['login_ip'] = $ip2bin;
        }

        // hook app_Services_UserService_find_start.php

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_UserService_find_before.php

        $this->sessionService->preloadOnlineStatus(array_keys($datalist));
        $users = $this->getState('users', []);
        foreach ($datalist as &$data) {
            $this->format($data);
            //$data = $this->safe($data, 0);
            $users[$data['id']] = $data;
        }
        unset($data);
        $this->setState('users', $users);

        // hook app_Services_UserService_find_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_UserService_findPaged_start.php

        $datalist = $this->dbModel->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_UserService_findPaged_before.php
        $this->sessionService->preloadOnlineStatus(array_keys($datalist));
        $users = $this->getState('users', []);
        foreach ($datalist as &$data) {
            $this->format($data);
            $users[$data['id']] = $data;
        }
        unset($data);
        $this->setState('users', $users);

        // hook app_Services_UserService_findPaged_end.php

        return $datalist;
    }

    public function findByGid(int $groupId, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_UserService_findByGid_start.php

        $orderby = true == $desc ? -1 : 1;
        $datalist = $this->dbModel->find(['group_id' => $groupId], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_UserService_findByGid_before.php
        $this->sessionService->preloadOnlineStatus(array_keys($datalist));
        $users = $this->getState('users', []);
        foreach ($datalist as &$data) {
            $this->format($data);
            $users[$data['id']] = $data;
        }
        unset($data);
        $this->setState('users', $users);

        // hook app_Services_UserService_findByGid_end.php

        return $datalist;
    }

    // 不支持批量删除
    public function delete(int $userId): int
    {
        // hook app_Services_UserService_delete_start.php

        $data = $this->read($userId);
        if (empty($data)) return 0;

        // hook app_Services_UserService_delete_before.php

        // 删除头像 (支持云端同步删除)
        if (!empty($data['avatar'])) {
            $this->storageManager->deleteAvatar((int)$data['avatar'], (int)($data['avatar_status'] ?? 0));
        }

        $result = $this->dbModel->delete(['id' => $userId]);
        if ($result === 0) return 0;

        // hook app_Services_UserService_delete_center.php

        if (!empty($this->cacheConfig['stores'])) {
            $this->cache->delete('user-' . $userId);
        }

        // hook app_Services_UserService_delete_after.php
        // 全站统计
        //runtime_set('users-', 1);

        // hook app_Services_UserService_delete_end.ph
        $users = $this->getState('users', []);
        unset($users[$userId]);
        $this->setState('users', $users);

        // 同步冗余统计（失败静默处理，不影响主业务）
        $this->syncUserCount(-1);

        return $result;
    }

    // 批量删除
    /**
     * @param array $userIds
     */
    public function bulkDelete($userIds): bool
    {
        if (!is_array($userIds)) return false;

        // hook app_Services_UserService_bulkDelete_start.php

        $dataList = $this->find(['id' => $userIds], [], 1, count($userIds));
        if (empty($dataList)) return false;

        // hook app_Services_UserService_bulkDelete_before.php

        if ($dataList) {
            foreach ($dataList as $data) {
                // 删除头像 (支持云端同步删除)
                if (!empty($data['avatar'])) {
                    $this->storageManager->deleteAvatar((int)$data['avatar'], (int)($data['avatar_status'] ?? 0));
                }

                if (!empty($this->cacheConfig['stores'])) {
                    $this->cache->delete('user-' . $data['id']);
                }

                // hook app_Services_UserService_bulkDelete_after.php
                $users = $this->getState('users', []);
                unset($users[$data['id']]);
                $this->setState('users', $users);
            }
        }

        $result = $this->dbModel->delete(['id' => $userIds]);
        if ($result === 0) return false;

        // hook app_Services_UserService_bulkDelete_end.php

        // 同步冗余统计：使用实际查询到的 dataList 数量，确保统计与真实删除量一致
        //（失败静默处理，不影响主业务）
        $this->syncUserCount(-count($dataList));

        return (bool)$result;
    }

    // 严禁哟条件查询，影响性能
    public function count( array $condition= []): int
    {
        // 无条件查询时优先使用冗余统计；有条件查询仍需精确 COUNT
        if (empty($condition) && $this->container->has(\App\Services\Stats\RuntimeStats::class)) {
            try {
                // 防止 RuntimeStats 初始化回调再次进入本方法导致死循环
                if ($this->getState('in_runtime_stats_lookup')) {
                    return $this->dbModel->count($condition);
                }

                $stats = $this->container->get(\App\Services\Stats\RuntimeStats::class);
                $this->setState('in_runtime_stats_lookup', true);
                $result = $stats->getTotal('users');
                $this->unsetState('in_runtime_stats_lookup');
                return $result;
            } catch (\Throwable $e) {
                $this->unsetState('in_runtime_stats_lookup');
                // 静默降级：RuntimeStats 未就绪、缓存故障、统计项未注册时，
                // 无缝回退到数据库查询，确保主业务绝对可用
            }
        }

        return $this->dbModel->count($condition);
    }

    /**
     * 同步用户总量冗余统计
     * 失败静默处理，确保统计故障不阻塞主业务流程
     */
    private function syncUserCount(int $delta): void
    {
        if ($delta === 0) return;
        try {
            $stats = $this->container->get(\App\Services\Stats\RuntimeStats::class);
            $stats->incrementStat('users', $delta);
        } catch (\Throwable $e) {
            // 冗余统计失败静默处理，由 RuntimeStats 自身保证最终一致性
        }
    }

    public function readByEmail(string $email): array
    {
        // hook app_Services_UserService_readByEmail_start.php
        $data = $this->dbModel->read(['email' => $email]);
        if ($data) {
            $this->format($data);
            $users = $this->getState('users', []);
            $users[$data['id']] = $data;
            $this->setState('users', $users);
        }
        // hook app_Services_UserService_readByEmail_end.php
        return $data;
    }

    /**
     * @param string $username
     */
    public function readByUsername($username): array
    {
        // hook app_Services_UserService_readByUsername_start.php
        $data = $this->dbModel->read(['username' => $username]);

        if ($data) {
            $this->format($data);
            $users = $this->getState('users', []);
            $users[$data['id']] = $data;
            $this->setState('users', $users);
        }

        // hook app_Services_UserService_readByUsername_end.php
        return $data;
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_UserService_maxid_start.php
        $maxId = $this->dbModel->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_UserService_maxid_end.php
        return $maxId;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;

        // hook app_Services_UserService_format_start.php
        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');

        $data['login_ip'] = isset($data['login_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['login_ip']) : '0.0.0.0';
        $data['login_date_fmt'] = empty($data['login_date']) ? '' : $i18nDateFmt->format((int)$data['login_date'], 'medium', 'short');

        $data['groupname'] = isset($data['group_id']) ? $this->groupService->name((int)$data['group_id']) : '';

        // hook app_Services_UserService_format_before.php

        // 头像处理 StorageManager 进行中心化路由
        $data['avatar_url'] = $this->storageManager->getAvatarUrl(
            (int)($data['avatar'] ?? 0),
            (int)($data['avatar_status'] ?? 0)
        );

        $data['avatar_path'] = isset($data['avatar']) ? $this->storageManager->getAvatarPath((int)$data['avatar']) : '';

        // hook app_Services_UserService_format_after.php

        $data['online_status'] = $this->sessionService->isOnline((int)$data['id']) ? 1 : 0;

        $data['url'] = $this->urlGenerator->url('user/home/' . $data['id']);

        // hook app_Services_UserService_format_end.php
    }

    /**
     * @param array $data
     * @return void
     */
    public function formatData($data)
    {
        // hook app_Services_UserService_formatData_start.php
        if ($data) {
            foreach ($data as &$item) {
                $this->format($item);
            }
            unset($item);
        }
        return $data;
        // hook app_Services_UserService_formatData_end.php
    }

    public function guest()
    {
        // hook app_Services_UserService_guest_start.php

        $guest = $this->getState('guest');
        if ($guest) return $guest; // 返回引用，节省内存。

        $guest = [
            'id' => 0,
            'group_id' => 0,
            'groupname' => 'Guest Group',
            'username' => 'Guest',
            'avatar_url' => $this->storageManager->getAvatarUrl(0, 0),
            'create_ip' => '',
            'created_at_fmt' => '',
            'login_date_fmt' => '',
            'email' => '',
            'logins' => 0,
            'total_logs' => 0
        ];

        $this->setState('guest', $guest);

        // hook app_Services_UserService_guest_end.php

        return $guest;
    }

    // $filter = 1 安全过滤用户隐私数据
    public function safe(array $user, int $filter = 1): array
    {
        if (empty($user['id'])) return $user;

        $user['salt'] = \Framework\Utils\SecurityHelper::encrypt($user['salt'], $this->appConfig['auth_key']);
        // hook app_Services_UserService_safe_start.php
        unset($user['password'], $user['created_at'], $user['login_date']);
        if ($filter) {
            unset($user['email'], $user['credits'], $user['golds'], $user['money'], $user['create_ip'], $user['created_at_fmt'], $user['login_ip'], $user['logins'], $user['avatar_path'], $user['total_logs']);
        } else {
            $user['email'] = \Framework\Utils\SafeHelper::maskEmail($user['email']);
            $user['create_ip'] = 'IPv4' === IpHelper::getIpVersion($user['create_ip']) ? IpHelper::maskIp($user['create_ip']) : IpHelper::maskIpv6($user['create_ip']);
            $user['login_ip'] = 'IPv4' === IpHelper::getIpVersion($user['login_ip']) ? IpHelper::maskIp($user['login_ip']) : IpHelper::maskIpv6($user['login_ip']);
        }
        // hook app_Services_UserService_safe_end.php
        return $user;
    }

    // 验证用户是否登录，$safe = 1 安全过滤用户隐私数据
    /**
     * @return array
     */
    public function getCurrentUser(int $safe = 1)
    {
        // hook app_Services_UserService_getCurrentUser_start.php
        // 优先 token，其次 session
        $userId = $this->getUserIdByToken();
        if (!$userId) {
            $session = $this->getState('session');
            $userId = (int)($session ? $session->get('user_id', 0) : 0);
            // session 也必须做一次校验
            if ($userId) {
                // session 认证必须和 token 校验一致（如 IP、UA、login_date、密码等）
                if (!$this->verifySession($userId)) {
                    $userId = 0;
                    if ($session) $session->delete('user_id');
                }
            }
        }

        // hook app_Services_UserService_getCurrentUser_before.php

        if (!$userId) return null;

        // hook app_Services_UserService_getCurrentUser_after.php

        $currentUserId = $this->getState('currentUserId', 0);
        if ($currentUserId !== $userId) {
            $user = $this->read($userId);
            $this->setState('currentUserId', $userId);
        } else {
            $user = $this->read($userId);
        }

        if (empty($user)) return null;

        // 标记在线 (Industrial Grade)
        $this->sessionService->markOnline($userId);

        // hook app_Services_UserService_getCurrentUser_end.php

        return $this->safe($user, $safe);
    }

    /**
     * 通过 token 获取 user_id 并校验 token
     * @return array
     */
    public function getUserIdByToken()
    {
        // hook app_Services_UserService_getUserIdByToken_start.php

        $token = $this->getState('capturedToken');
        if (empty($token)) return 0;

        if (empty($token)) return 0;

        $tokenKey = hash_hkdf('sha256', $this->appConfig['auth_key'] ?? '', 32);
        $str = \Framework\Utils\SecurityHelper::decrypt($token, $tokenKey);
        if (empty($str)) return 0;

        // hook app_Services_UserService_getUserIdByToken_before.php

        // [ip]	[timestamp]	[user_id]	[login_version]	[ua_md5]
        $arr = explode("\t", $str);
        if (count($arr) !== 5) return 0;

        list($ip, $time, $userId, $loginVersion, $uaMd5) = $arr;
        $userId = (int)$userId;

        // hook app_Services_UserService_getUserIdByToken_center.php

        // 校验 IP
        $capturedIp = $this->getState('capturedIp');
        if ($this->appConfig['login_ip'] ?? false) {
            if (!$capturedIp) return 0;
            if ($ip !== $capturedIp) return 0;
        }

        // 校验 UA
        $capturedUa = $this->getState('capturedUa');
        if ($this->appConfig['login_ua'] ?? false) {
            if (!$capturedUa) return 0;
            if ($uaMd5 !== md5($capturedUa)) return 0;
        }

        $user = $this->read($userId);
        if (!$user) return 0;

        // hook app_Services_UserService_getUserIdByToken_after.php

        // 单点登录校验（login_date 必须和 token 内一致）
        if (($this->appConfig['login_only'] ?? false) && (int)($user['login_date'] ?? 0) !== (int)$time) return 0;

        // 校验密码未被改动
        if ((int)($user['login_version'] ?? 0) !== (int)$loginVersion) return 0;

        $session = $this->getState('session');
        if ($session) {
            $session->set('user_id', $userId);
            $session->set('login_version', $user['login_version'] ?? 0);
            $session->set('login_date', $user['login_date'] ?? 0);
        }

        $users = $this->getState('users', []);
        $users[$userId] = $user;
        $this->setState('users', $users);
        $this->setState('currentUserId', $userId);

        // hook app_Services_UserService_getUserIdByToken_end.php

        return (int)$userId;
    }

    /**
     * session 登录情况下的安全校验
     * 用与 token 校验相同的逻辑，保证安全一致
     */
    protected function verifySession(int $userId): bool{
        // hook app_Services_UserService_verifySession_start.php
        // 单点登录校验（login_date 必须和 token 内一致）
        $session = $this->getState('session');
        if (!$session) return false;

        $loginDateSess = (int)$session->get('login_date', 0);
        $loginVersionSess = $session->get('login_version', '');

        if (empty($loginDateSess) || empty($loginVersionSess)) return false;

        $user = $this->read($userId);
        if (empty($user)) return false;

        // hook app_Services_UserService_verifySession_before.php

        // 单点登录校验（login_date 必须和 token 内一致）
        if (($this->appConfig['login_only'] ?? false) && (int)($user['login_date'] ?? 0) !== $loginDateSess) return false;

        if ((int)$loginVersionSess !== (int)($user['login_version'] ?? 0)) return false;

        if (($this->appConfig['login_ip'] ?? false) && ($user['login_ip'] ?? '') !== ($this->getState('capturedIp') ?: '')) return false;

        // hook app_Services_UserService_verifySession_after.php

        $users = $this->getState('users', []);
        $users[$userId] = $user;
        $this->setState('users', $users);
        $this->setState('currentUserId', $userId);

        // hook app_Services_UserService_verifySession_end.php

        return true;
    }

    // 设置 token，防止 session_id 过期后被删除
    public function tokenSet(int $userId, int $tokenLifetime = 86400)
    {
        if (empty($userId)) return '';
        // hook app_Services_UserService_tokenSet_start.php
        $token = $this->generateToken($userId);

        $expiry = time() + $tokenLifetime;

        \Framework\Utils\CookieHelper::set('token', $token, $expiry, $this->sessionConfig);

        // hook app_Services_UserService_tokenSet_end.php

        return $token;
    }

    public function tokenClear(): void{
        // hook app_Services_UserService_tokenClear_start.php
        \Framework\Utils\CookieHelper::set('token', '', time() - 864000, $this->sessionConfig);

        // 1. 清理自身上下文状态，防止本轮请求后续逻辑误判
        $this->unsetState('currentUserId');
        $this->unsetState('capturedToken');
        $this->unsetState('guest');
        $this->unsetState('users');

        // 2. 彻底销毁当前 Session 对象内容并重生 ID (SessionManager 会自动物理删除旧 ID 记录)
        $session = $this->getState('session');
        if ($session instanceof \Framework\Session\SessionInterface) {
            $session->destroy();
            $session->regenerate();
        }
        // hook app_Services_UserService_tokenClear_end.php
    }

    public function generateToken(int $userId)
    {
        // hook app_Services_UserService_tokenGen_start.php
        $token = $this->getState('token');
        if ($token) return $token;

        $ip = $this->getState('capturedIp') ?: '';

        $user = $this->read($userId);
        if (empty($user)) return null;

        $loginVersion = $user['login_version'] ?? 0;
        $time = $user['login_date'] ?? time();

        $capturedUa = $this->getState('capturedUa');
        if (empty($capturedUa)) {
            // 在生成 Token 时，如果上下文丢失是严重错误，应记录日志
            return null;
        }
        $uaMd5 = md5($capturedUa);

        $tokenKey = hash_hkdf('sha256', $this->appConfig['auth_key'] ?? '', 32);

        // hook app_Services_UserService_tokenGen_after.php
        $token = \Framework\Utils\SecurityHelper::encrypt("$ip\t$time\t$userId\t$loginVersion\t$uaMd5", $tokenKey);
        $this->setState('token', $token);

        $session = $this->getState('session');
        if ($session) {
            $session->set('user_id', $userId);
            $session->set('login_version', $user['login_version']);
            $session->set('login_date', $user['login_date']);
        }

        // hook app_Services_UserService_tokenGen_end.php

        return $token;
    }

    /**
     * 原子增加积分 (Industrial Grade Assets Implementation)
     * 使用基于 User ID 的分布式锁确保资产变动的串行化，防止竞态条件下的计算错误。
     */
    public function incrementCredits(int $userId, int $credits): bool
    {
        if (!$userId || 0 === $credits) return true;

        $lockKey = "lock:user:assets:{$userId}";
        $token = $this->cache->lock($lockKey, 5) ?? null; // 抢锁
        if (!$token) return false;

        try {
            // 在锁内读取最新数据 (DB)
            $user = $this->read($userId);
            if (empty($user)) return false;

            $newCredits = (int)($user['credits'] ?? 0) + $credits;
            if ($newCredits < 0) return false; // 积分余额不足

            return (bool)$this->update($userId, ['credits' => $newCredits]);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * 原子增加金币
     */
    public function incrementGolds(int $userId, int $golds): bool
    {
        if (!$userId || 0 === $golds) return true;

        $lockKey = "lock:user:assets:{$userId}";
        $token = $this->cache->lock($lockKey, 5) ?? null; // 抢锁
        if (!$token) return false;

        try {
            $user = $this->read($userId);
            if (empty($user)) return false;

            $newGolds = (int)($user['golds'] ?? 0) + $golds;
            if ($newGolds < 0) return false; // 金币余额不足

            return (bool)$this->update($userId, ['golds' => $newGolds]);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }
}
