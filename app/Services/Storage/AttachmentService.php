<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

use Framework\Exception\BusinessException;
use Framework\Exception\Infra\DatabaseException;
use Framework\Utils\Validator;

/*
 * 上传链路保护（用户要求，2026-10-10；见根目录 AGENTS.md）：
 * 未经用户明确授权，AI 禁止修改附件模型加载、临时附件绑定、入库、引用计数及回滚清理行为。
 * 此服务由所有插件共用；审计、重构、优化或修复测试均不构成授权，必须保留此备注。
 */
class AttachmentService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Models\AttachmentModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var \App\Services\System\LogService */
    protected $logService;
    /** @var \App\Services\Auth\UserService */
    protected $userService;
    /** @var \App\Services\Storage\FileStorageService */
    protected $fileStorageService;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    protected $language;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var array */
    protected $cacheConfig;
    /** @var int */
    protected $ttl;

    public function __construct(
        array $cacheConfig,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        \App\Services\System\LogService $logService,
        \App\Services\Auth\UserService $userService,
        \App\Services\Storage\FileStorageService $fileStorageService,
        \Framework\Core\Container $container,
        ?\App\Interfaces\LanguageLoaderInterface $language = null
    ) {
        // hook app_Services_AttachmentService_construct_start.php
        $this->cacheConfig = $cacheConfig;
        $this->cache = $cache;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->logService = $logService;
        $this->userService = $userService;
        $this->fileStorageService = $fileStorageService;
        $this->container = $container;
        $this->language = $language;
        $this->ttl = $this->cacheConfig['attach_ttl'] ?? 3600;
        // hook app_Services_AttachmentService_construct_end.php
    }

    private function getModel()
    {
        if ($this->dbModel !== null) {
            return $this->dbModel;
        }
        $resolved = $this->container->get(\App\Models\AttachmentModel::class);
        // 首次获取可能让出协程；保留已经建立的模型引用。
        return $this->dbModel = $this->dbModel ?? $resolved;
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // 上传保护：用户授权修复（2026-10-31），关联行与哈希行共用同一 IP 二进制入库边界。
        if (isset($data['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
            $data['create_ip'] = $ip2bin;
        }

        $filehash = $data['filehash'] ?? '';
        if (empty($filehash)) {
            $result = $this->getModel()->insert($data);
            if (!$result) throw new DatabaseException('AttachmentService -> insert(): data writing failed');
            return $result;
        }

        // 基于 filehash 的原子锁
        $lockKey = 'lock:attach:insert:' . $filehash;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $exists = $this->getModel()->read(['filehash' => $filehash]);
            if (!empty($exists)) return (int)$exists['id'];

            $result = $this->getModel()->insert($data);
            if (!$result) throw new DatabaseException('AttachmentService -> insert(): data writing failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('attach_hash:' . $filehash);
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

        // hook app_Services_AttachmentService_bulkInsert_start.php

        $result = $this->getModel()->bulkInsert($data);
        if (!$result) throw new DatabaseException('AttachmentService -> bulkInsert(): data writing failed');

        // hook app_Services_AttachmentService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        $lockKey = 'lock:attach:update:' . $id;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        if (isset($update['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
            $update['create_ip'] = $ip2bin;
        }

        try {
            $oldData = $this->getModel()->read(['id' => $id]);
            if (empty($oldData)) return 0;

            $result = $this->getModel()->update(['id' => $id], $update);
            if ($result === 0) throw new BusinessException('AttachmentService -> update() Update failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->cache->delete('attach:' . $id);
                $this->cache->delete('attach_hash:' . $oldData['filehash']);
                if (isset($update['filehash']) && $update['filehash'] !== $oldData['filehash']) {
                    $this->cache->delete('attach_hash:' . $update['filehash']);
                }
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function read(int $id, array $orderby = [], array $fields = ['*']): array
    {
        if (!$id) return [];

        if (empty($this->cacheConfig['stores'])) {
            $data = $this->getModel()->read(['id' => $id], $orderby, $fields);
        } else {
            $data = $this->cache->cacheWithLock(
                'attach:' . $id,
                'lock:attach:read:' . $id,
                function () use ($id, $orderby, $fields) {
                    $data = $this->getModel()->read(['id' => $id], $orderby, $fields);
                    if (empty($data)) return [];
                    // 必须先 format 处理掉二进制字段
                    $this->format($data);
                    return $data;
                },
                3,
                $this->ttl
            );
        }

        if (empty($data)) {
            // 如果 cacheWithLock 返回 null (可能是锁超时或未命中)，兜底查库
            if (!empty($this->cacheConfig['stores'])) {
                $data = $this->getModel()->read(['id' => $id], $orderby, $fields);
                if ($data) $this->format($data);
            }
            return $data ?: [];
        }

        // 如果是从缓存拿到的，可能已经 format 过了，但 format 方法本身是幂等的
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

        // hook app_Services_AttachmentService_find_start.php

        $datalist = $this->getModel()->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_AttachmentService_find_end.php

        return $datalist;
    }

    public function delete(array $condition = []): int
    {
        // hook app_Services_AttachmentService_delete_start.php
        $result = $this->getModel()->delete($condition);
        // hook app_Services_AttachmentService_delete_end.php
        return $result;
    }

    public function count( array $condition= []): int
    {
        return $this->getModel()->count($condition);
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_AttachmentService_maxid_start.php
        $maxId = $this->getModel()->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_AttachmentService_maxid_end.php
        return $maxId;
    }

    public function findByIds(array $ids, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_AttachmentService_findByIds_start.php

        $datalist = $this->find(['id' => $ids], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);

        // hook app_Services_AttachmentService_findByIds_end.php

        return $datalist;
    }

    public function findByUserId(int $userId, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {

        // hook app_Services_AttachmentService_findByUserId_start.php

        $datalist = $this->find(['user_id' => $userId], ['id' => -1], $page, $pageSize, $indexKey, $fields);

        // hook app_Services_AttachmentService_findByUserId_end.php

        return $datalist;
    }

    public function findByTargetId(int $targetId, int $module, int $limit = 0): array
    {
        return $this->find(['module' => $module, 'target_id' => $targetId], [], 1, $limit);
    }

    public function findByReplyId(int $replyId, int $module, int $limit = 0): array
    {
        return $this->find(['module' => $module, 'reply_id' => $replyId], [], 1, $limit);
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_AttachmentService_findPaged_start.php

        $datalist = $this->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);

        // hook app_Services_AttachmentService_findPaged_end.php

        return $datalist;
    }

    public function findByUserIdPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_AttachmentService_findByUserIdPaged_start.php

        $datalist = $this->find(['user_id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);

        // hook app_Services_AttachmentService_findByUserIdPaged_end.php

        return $datalist;
    }

    public function findByReviewed(int $isReviewed, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_AttachmentService_findByReviewed_start.php

        $datalist = $this->find(['is_reviewed' => $isReviewed], ['id' => -1], $page, $pageSize, $indexKey, $fields);

        // hook app_Services_AttachmentService_findByReviewed_end.php

        return $datalist;
    }

    /**
     * @param int $id
     */
    public function deleteById($id): bool
    {
        return $this->delete(['id' => $id]) > 0;
    }

    public function deleteByTargetAndStorage(int $targetId, int $storageId, int $module): bool
    {
        return $this->delete(['module' => $module, 'target_id' => $targetId, 'storage_id' => $storageId]) > 0;
    }

    public function deleteByTargetId($targetId, int $module): bool
    {
        return $this->delete(['module' => $module, 'target_id' => $targetId]) > 0;
    }

    /**
     * 深度删除附件：包含数据库记录、物理文件、日志审计及用户上传统计更新
     * @param int|array $ids
     * @param int $operatorUid 执行操作的用户ID
     */
    public function deleteWithFiles($ids, int $operatorUid, array $expectedScope = [], bool $cleanupPhysical = true): bool
    {
        if (empty($ids)) return false;
        $idsArray = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        if (empty($idsArray)) return false;

        $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
        $operation = function () use ($idsArray, $operatorUid, $database, $expectedScope, $cleanupPhysical): bool {
            // 必须在事务内重读，避免事务外快照与真实删除目标偏离。
            $datalist = $this->getModel()->findOnMaster(['id' => $idsArray] + $expectedScope, 0, count($idsArray));
            if (empty($datalist)) return false;
            $protectedIds = array_values(array_unique(array_map('intval', array_column($datalist, 'storage_id'))));
            return $this->fileStorageService->withFileProtections($protectedIds, function () use ($idsArray, $operatorUid, $database, $expectedScope, $protectedIds, $cleanupPhysical): bool {
            $datalist = $this->getModel()->findOnMaster(['id' => $idsArray] + $expectedScope, 0, count($idsArray));
            if (!$datalist) return false;
            foreach ($datalist as $row) {
                if (!in_array((int)$row['storage_id'], $protectedIds, true)) throw new DatabaseException('Attachment identity changed before deletion');
            }
            $userIdsCount = [];
            $deletedIds = [];
            $storageIds = [];
            foreach ($datalist as $item) {
                $id = (int)$item['id'];
                if ($id <= 0) continue;
                $deletedIds[] = $id;
                $storageId = (int)($item['storage_id'] ?? 0);
                if ($storageId > 0) $storageIds[] = $storageId;
                if (!empty($item['user_id'])) {
                    $userId = (int)$item['user_id'];
                    isset($userIdsCount[$userId]) ? $userIdsCount[$userId]++ : $userIdsCount[$userId] = 1;
                }
            }
            if (empty($deletedIds)) return false;

            // 先删关联，只有精确删除全部快照行才允许继续递减引用。
            $deleted = $this->getModel()->delete(['id' => $deletedIds] + $expectedScope);
            if ($deleted !== count($deletedIds)) {
                throw new DatabaseException('AttachmentService -> deleteWithFiles(): partial association deletion');
            }

            foreach ($datalist as $item) {
                $id = (int)$item['id'];
                $this->logService->insert([
                    'type' => 28,
                    'from_user_id' => $operatorUid,
                    'user_id' => $item['user_id'],
                    'target_id' => $item['target_id'],
                    'target_title' => $item['filename'],
                    'remark' => $this->getLang()->get('delete_remark', ['id' => $id, 'filename' => $item['filename'] ?? 'None']),
                    'created_at' => time(),
                    'create_ip' => \Framework\Utils\IpHelper::ip()
                ]);
            }

            if (!empty($userIdsCount)) {
                $updates = [];
                foreach ($userIdsCount as $userId => $count) {
                    $updates[] = ['id' => $userId, 'total_uploads-' => $count];
                }
                $this->userService->bulkUpdate($updates);
            }

            foreach ($storageIds as $storageId) {
                $released = $cleanupPhysical
                    ? $this->fileStorageService->decrementAndCleanup($storageId)
                    : $this->fileStorageService->decrementRefCount($storageId);
                if (!$released) {
                    throw new DatabaseException('AttachmentService -> deleteWithFiles(): ref_count decrement failed');
                }
            }

            // 附件读缓存同样只在 commit 后失效，回滚时旧缓存仍然正确。
            if (!empty($this->cacheConfig['stores'])) {
                $invalidate = function () use ($deletedIds): void {
                    foreach ($deletedIds as $deletedId) $this->cache->delete('attach:' . $deletedId);
                };
                if (method_exists($database, 'afterCommit')) {
                    $database->afterCommit($invalidate);
                } else {
                    $invalidate();
                }
            }

            return true;
            });
        };

        // ProxyDriver 支持嵌套层级，基础 PDO 不支持；已在外层事务时直接复用。
        if ($database->inTransaction()) return $operation();
        return (bool)$database->transaction($operation, 1, '');
    }

    /**
     * 审核附件。
     *
     * 审核状态的权威载体是 well_file_storage（is_reviewed：0待审核 1通过 2拒绝），
     * well_attachment 表无审核字段。此处以附件 id 定位其 storage_id 后更新文件记录，
     * 使 download 门禁、well_article 引用校验、VerifyIntegrityJob 判定共享同一状态。
     *
     * @param int    $id         附件 id（well_attachment）
     * @param int    $status     1=通过 2=拒绝
     * @param string $note       审核备注
     * @param int    $reviewerId 审核人用户 ID
     * @return bool
     */
    public function review(int $id, int $status, string $note, int $reviewerId): bool
    {
        Validator::make(
            ['id' => $id, 'status' => $status],
            ['id' => 'required|int', 'status' => 'required|int']
        );

        $attachment = $this->read($id);
        if (empty($attachment) || empty($attachment['storage_id'])) {
            return false;
        }

        return (bool)$this->fileStorageService->update((int)$attachment['storage_id'], [
            'is_reviewed' => $status,
            'reviewed_at' => time(),
            'reviewed_by' => $reviewerId,
            'review_note' => $note
        ]);
    }

    protected function getLang(): \App\Interfaces\LanguageLoaderInterface
    {
        return $this->language ?? $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
    }

    /**
     * 通过文件hash获取附件（用于文件去重判断）
     * @param string $filehash SHA256哈希值
     * @return array|null
     */
    public function getByFilehash(string $filehash): ?array
    {
        if (empty($filehash)) return null;

        if (empty($this->cacheConfig['stores'])) {
            return $this->getModel()->read(['filehash' => $filehash]);
        }

        return $this->cache->cacheWithLock(
            'attach_hash:' . $filehash,
            'lock:attach_hash:read:' . $filehash,
            function () use ($filehash) {
                return $this->getModel()->read(['filehash' => $filehash]);
            },
            3,
            $this->ttl
        );
    }

    /**
     * 合并并转正附件
     * 只有在点击发布或保存草稿时调用
     *
     * @param int|string $targetId 关联ID (如thread_id)
     * @param int|string $replyId 回复ID (如reply_id)
     * @param int $module 模块ID
     * @param array $fileList Session或Draft中的临时文件列表
     * @param int $userId 用户ID
     * @param string $content 内容文本 (用于提取引用的图片/附件，过滤已删除的)
     * @return array 返回结构化数据: ['attachments'=>JSON, 'image_count'=>int, 'file_count'=>int, 'storage_ids'=>array]
     */
    public function finalizeAttachments($targetId, $replyId, int $module, array $fileList, int $userId, string $content = '', bool $consumeTemporary = true): array
    {
        $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
        $operation = function () use ($targetId, $replyId, $module, $fileList, $userId, $content, $consumeTemporary): array {
            return $this->finalizeBoundAttachments($targetId, $replyId, $module, $fileList, $userId, $content, $consumeTemporary);
        };
        return $this->withPreparedFileList($fileList, function () use ($database, $operation): array {
            return $database->inTransaction() ? $operation() : $database->transaction($operation, 1, '');
        });
    }

    /** 正式秒传缺本地副本时，在业务事务前恢复，并持有同一对象保护直到保存结束。 */
    public function withPreparedFileList(array $files, callable $operation)
    {
        $storage = $this->container->get(FileStorageService::class);
        $ids = array_values(array_filter(array_map('intval', array_column($files, 'id'))));
        return $storage->withFileProtections($ids, function () use ($ids, $operation, $storage) {
            $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
            foreach (array_unique($ids) as $id) {
                $current = $storage->readOnMaster($id);
                if (!$current) throw new DatabaseException('Formal upload source disappeared');
                try { $storage->resolveStoredFilePath((string)$current['path']); continue; }
                catch (\RuntimeException $missingLocal) {}
                $file = $storage->resolveStoredFile($current);
                if ($database->inTransaction()) throw new \RuntimeException('Upload source must be restored before the business transaction');
                $result = $storage->restoreLocalCopy($id, $file['artifact']);
                if ($result['state'] === 'stale') throw new \RuntimeException('Formal upload source changed');
            }
            return $operation();
        });
    }

    /** 上传保护：暂存阶段不消费源文件，只有真实业务提交才清理受管临时文件。 */
    public function consumeTemporaryFilesAfterCommit(array $files): void
    {
        $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
        if (!$database->inTransaction() || !method_exists($database, 'afterCommit')) {
            throw new \LogicException('Temporary files must be consumed with the business transaction');
        }
        $storage = $this->container->get(FileStorageService::class);
        $paths = array_unique(array_column($files, 'path'));
        $database->afterCommit(function () use ($paths, $storage): void {
            foreach ($paths as $path) {
                try { $temporary = $storage->resolveTempFilePath((string)$path); }
                catch (\RuntimeException $notTemporary) { continue; }
                if (!unlink($temporary)) throw new \RuntimeException('Cannot clean committed temporary attachment');
            }
        });
    }

    private function finalizeBoundAttachments($targetId, $replyId, int $module, array $fileList, int $userId, string $content, bool $consumeTemporary): array
    {
        $uploadService = $this->container->get(\App\Services\Storage\UploadService::class);
        // 恢复从 Session/Cache 读取的 Hex 数据为二进制，确保后续 Hash 匹配和入库正确
        $fileList = $uploadService->restoreMetadata($fileList);

        $fileStorageService = $this->container->get(\App\Services\Storage\FileStorageService::class);

        // 1. [内容即真理性扫描] 提取内容中的所有相对路径 URL
        $content = $this->normalizeFileReferences($content, $fileList)['content'];
        $contentUrls = $this->extractUrls($content);

        // 保留正文补漏，但补回内容必须属于当前 tmp_files，绝不从任意 URL 猜物理路径。
        foreach ($contentUrls as $url) {
            $sessionFile = $uploadService->sessionFileForUrl($url);
            if (!$sessionFile) continue;
            $hashKey = bin2hex((string)$sessionFile['filehash']);
            if (isset($fileList[$hashKey])) continue;
            $sessionFile['is_scan'] = 1;
            $fileList[$hashKey] = $sessionFile;
        }

        $referencedUrls = array_fill_keys($contentUrls, true);

        // 保存旧内容也保护其现有文件，避免清理检查后被无保护地切回本地模式。
        $protectedIds = [];
        $afterAssociation = 0;
        do {
            $existingAssociations = $this->findOnMaster(['module' => $module, 'target_id' => (int)$targetId, 'reply_id' => (int)$replyId], $afterAssociation, 100);
            foreach ($existingAssociations as $association) {
                $protectedIds[] = (int)$association['storage_id'];
                $afterAssociation = (int)$association['id'];
            }
        } while (count($existingAssociations) === 100);
        if (!$fileList) {
            return $fileStorageService->withFileProtections($protectedIds, function () use ($content): array {
                return ['content' => $content, 'attachments' => [], 'image_count' => 0, 'file_count' => 0, 'storage_ids' => [], 'total_uploads' => 0];
            });
        }

        $attachments = [];
        $storageIds = [];
        $imageCount = 0;
        $fileCount = 0;
        $finalNewCount = 0;
        $processedHashes = []; // 用于正文引用的图片统计去重
        $preparedFiles = [];

        foreach ($fileList as $key => $data) {
            $fileUrl = $data['url'] ?? '';
            $isImage = (int)($data['is_image'] ?? 0);
            $isAttachment = (int)($data['is_attachment'] ?? 0);
            $fileHashBin = $data['filehash']; // 二进制字符串

            // 只接受节点中已核实的本方地址，不按 host/path 子串猜测同一文件。
            $isReferenced = isset($referencedUrls[$fileUrl]);

            // A. 尚未绑定的正文图片已被移除：保留临时生命周期，不建立孤儿正式关联。
            if (!$isReferenced && !$isAttachment && $isImage) continue;

            // B. 深度去重校验：在物理搬运前先查库
            $existing = $fileStorageService->getByFilehash($fileHashBin);
            $alreadyStored = false;
            $promotedPath = '';
            $newPath = $data['path'];
            $newUrl = $data['url'];

            $existingPath = '';
            if ($existing && !empty($existing['path'])) {
                try {
                    $existingPath = $fileStorageService->resolveStoredFilePath((string)$existing['path']);
                } catch (\Throwable $e) {
                    $existingPath = '';
                }
            }
            if ($existingPath !== '') {
                // 库中已存在且物理文件完好
                $storageId = $existing['id'];
                $newUrl = $existing['url'];
                $newPath = $existingPath;
                $alreadyStored = true;


            }

            // C. 如果库中不存在，则进行物理搬运与新记录入库
            if (!$alreadyStored) {
                try {
                    // 新 storage 只能来自受管临时根；可信秒传必须先命中已有 storage。
                    list($newPath, $newUrl) = $fileStorageService->promoteFile((string)($data['path'] ?? ''), true);
                    $promotedPath = $fileStorageService->resolveStoredFilePath($newPath);
                    $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
                    if (!method_exists($database, 'afterRollback')) throw new \RuntimeException('Promotion rollback callback is unavailable');
                    $database->afterRollback(function () use ($fileStorageService, $promotedPath): void {
                        if (is_file($promotedPath) && !unlink($fileStorageService->resolveStoredFilePath($promotedPath))) {
                            throw new \RuntimeException('Cannot clean rolled-back promoted attachment');
                        }
                    });
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Cannot promote attachment source', 0, $e);
                }

                $storageData = [
                    'filehash' => $fileHashBin,
                    'filesize' => $data['filesize'],
                    'width' => $data['width'] ?? 0,
                    'height' => $data['height'] ?? 0,
                    // 审核状态透传：待审文件（is_reviewed=0）不写审核时间/人，等待管理员 review()
                    'is_reviewed' => $data['is_reviewed'] ?? 1,
                    'reviewed_at' => (int)($data['is_reviewed'] ?? 1) === 1 ? time() : 0,
                    'reviewed_by' => (int)($data['is_reviewed'] ?? 1) === 1 ? 1 : 0,
                    // 上传链路保护：缺省不能当作已清洗；依据全链路方案获授权修正。
                    'exif_cleaned' => $data['exif_cleaned'] ?? 0,
                    'is_image' => $isImage,
                    'ref_count' => 0, // 下面关联时统一增加
                    'create_ip' => $data['create_ip'] ?? 0,
                    'created_at' => time(),
                    'updated_at' => time(),
                    'filename' => $data['filename'],
                    'orgfilename' => $data['orgfilename'],
                    'mime' => $data['mime'],
                    'path' => str_replace(APP_PATH, '', $newPath),
                    'url' => $newUrl,
                    'exif_data' => $data['exif_data'] ?? '',
                ];
                $storageId = $fileStorageService->insert($storageData);
            }

            if (empty($storageId)) throw new DatabaseException('Cannot persist attachment storage');
            $winningStorage = $fileStorageService->readOnMaster((int)$storageId);
            if (!$winningStorage) throw new DatabaseException('Attachment storage disappeared before binding');
            $newUrl = (string)$winningStorage['url'];
            $newPath = (string)$winningStorage['path'];
            $database = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
            if (!method_exists($database, 'afterCommit') || !method_exists($database, 'afterRollback')) {
                throw new \RuntimeException('Attachment promotion requires transaction completion callbacks');
            }
            if ($consumeTemporary) $this->consumeTemporaryFilesAfterCommit([$data]);
            if ($promotedPath !== '') {
                $cleanupOwnedCopy = function () use ($fileStorageService, $storageId, $promotedPath): void {
                    $current = $fileStorageService->readOnMaster((int)$storageId);
                    if ($current && $fileStorageService->resolveStoredFile($current)['localPath'] === $promotedPath) return;
                    if (is_file($promotedPath) && !unlink($fileStorageService->resolveStoredFilePath($promotedPath))) {
                        throw new \RuntimeException('Cannot clean unused promoted attachment copy');
                    }
                };
                $database->afterCommit($cleanupOwnedCopy);

            }


            // 仅替换已核实文件的图片属性/附件链接，正文文字和代码不改写。
            if ($content !== '' && $fileUrl !== '' && $fileUrl !== $newUrl) {
                $mapping = $data;
                $mapping['url'] = $newUrl;
                $mapping['knownUrls'] = [$fileUrl];
                $content = $this->normalizeFileReferences($content, [$mapping])['content'];
            }

            $preparedFiles[] = compact('storageId', 'data', 'isImage', 'isAttachment', 'fileHashBin', 'isReferenced', 'newUrl');
        }
        foreach ($preparedFiles as $prepared) $protectedIds[] = (int)$prepared['storageId'];
        return $fileStorageService->withFileProtections($protectedIds, function () use (
            $preparedFiles, $fileStorageService, $targetId, $replyId, $module, $userId, $content,
            &$attachments, &$storageIds, &$imageCount, &$fileCount, &$finalNewCount, &$processedHashes
        ): array {
        foreach ($preparedFiles as $prepared) {
            $storageId = (int)$prepared['storageId'];
            $data = $prepared['data'];
            $isImage = $prepared['isImage'];
            $isAttachment = $prepared['isAttachment'];
            $fileHashBin = $prepared['fileHashBin'];
            $isReferenced = $prepared['isReferenced'];
            $newUrl = $prepared['newUrl'];
            $attachmentId = $fileStorageService->withFileProtection($storageId, function () use (
                $fileStorageService, $storageId, $targetId, $replyId, $module, $isAttachment, $userId, $data, &$finalNewCount
            ): int {
                $current = $fileStorageService->readOnMaster($storageId);
                if (!$current) throw new DatabaseException('Attachment storage disappeared before binding');
                try {
                    $fileStorageService->resolveStoredFilePath((string)$current['path']);
                } catch (\RuntimeException $missingCopy) {
                    $resolved = $fileStorageService->resolveStoredFile($current);
                    $restored = $fileStorageService->restoreLocalCopy($storageId, $resolved['artifact']);
                    if ($restored['state'] === 'stale') throw new \RuntimeException('Attachment source changed before binding');
                }

            // D. 写入 well_attachment 关联表 (增加冲突检测，防止重复关联导致的引用计数错误)
            if ($replyId) {
                // 评论附件
                $condition = ['module' => $module, 'reply_id' => $replyId, 'storage_id' => $storageId];
            } else {
                // 主题附件
                $condition = ['module' => $module, 'target_id' => $targetId, 'storage_id' => $storageId];
            }

            $existingRows = $this->getModel()->findOnMaster($condition, 0, 1);
            $existsAssociation = $existingRows[0] ?? [];

            if (!$existsAssociation) {
                $time = time();
                $attachmentId = $this->insert([
                    'storage_id' => $storageId,
                    'target_id' => $targetId,
                    'reply_id' => $replyId,
                    'module' => $module,
                    'is_attachment' => $isAttachment,
                    'user_id' => $userId,
                    'create_ip' => $data['create_ip'],
                    'created_at' => $time,
                    'updated_at' => $time,
                    'filename' => $data['filename'],
                    'orgfilename' => $data['orgfilename']
                ]);

                // 仅在首次建立关联时增加引用计数
                $fileStorageService->incrementRefCount($storageId);
                $finalNewCount++;
            } else {
                $attachmentId = (int)$existsAssociation['id'];
            }

                return $attachmentId;
            });

            // E. 归类与精细统计
            $storageIds[] = $storageId;
            $filehashHex = bin2hex($fileHashBin);

            // 归类到内容附件列表 (非图，或者是显式指定的附件，或者是未在正文引用的图)
            if ($isImage === 0 || $isAttachment || !$isReferenced) {
                $attachments[$attachmentId] = [
                    'attachment_id' => $attachmentId,
                    'storage_id' => $storageId,
                    'hash' => $filehashHex,
                    'filename' => $data['filename'],
                    'orgfilename' => $data['orgfilename'],
                    'filesize' => (int)$data['filesize'],
                    'url' => $newUrl,
                    'mime' => $data['mime']
                ];
                $fileCount++;
            }

            // 正文引用统计 (仅限有效引用的图片，且按 Hash 去重)
            if ($isImage && $isReferenced && !isset($processedHashes[$filehashHex])) {
                $imageCount++;
                $processedHashes[$filehashHex] = true;
            }
        }

        return [
            'content' => $content,
            'attachments' => $attachments,
            'image_count' => $imageCount,
            'file_count' => $fileCount,
            'storage_ids' => $storageIds,
            'total_uploads' => $finalNewCount // 用户这次操作实际上新增占用
        ];
        });
    }

    /**
     * 从内容中提取所有可能的 URL (支持 HTML 和 Markdown)
     */
    public function extractUrls(string $content): array
    {
        $urls = [];
        $this->mapFileReferences($content, function (string $url) use (&$urls): string {
            $urls[$url] = true;
            return $url;
        });
        return array_keys($urls);
    }

    /** 精确映射已授权文件；普通文字、代码和外站同路径保持原义。 */
    public function normalizeFileReferences(string $content, array $authorizedFiles): array
    {
        $aliases = [];
        $cloudAliases = [];
        $scopedAliases = [];
        $downloads = null;
        $uploadConfig = $this->container->get('uploadConfig');
        $appConfig = $this->container->get('appConfig');
        $domain = rtrim((string)($appConfig['domain'] ?? ''), '/');
        foreach ($authorizedFiles as $file) {
            $stable = (string)($file['url'] ?? '');
            if ($stable === '') continue;
            $aliases[$stable] = $file;
            if ($domain !== '' && isset($stable[0]) && $stable[0] === '/') $aliases[$domain . $stable] = $file;
            // 上传链路保护：直接索引业务已授权附件 ID，不再要求生成下载 URL 作为引用凭据。
            $aid = (int)($file['attachment_id'] ?? 0);
            if ($aid > 0) {
                $scopedAliases['attachment:' . $aid] = $file;
                if ($downloads === null) $downloads = $this->container->get(DownloadService::class);
            }
            foreach (($file['knownUrls'] ?? []) as $known) {
                if (!is_string($known) || $known === '') continue;
                $aliases[$known] = $file;
                // 上传链路保护：显式 knownUrls 保留自身准确 query，不扩充普通存储 URL。
                if ($downloads === null) $downloads = $this->container->get(DownloadService::class);
                $referenceKey = $downloads->referenceKey($known);
                if ($referenceKey !== '') $scopedAliases[$referenceKey] = $file;
            }
            $diskKey = (string)($file['disk_key'] ?? '');
            $key = (string)($file['cloud_url'] ?? '');
            if ((int)($file['cloud_type'] ?? 0) === 1 && $diskKey !== 'local' && $key !== '') {
                // 上传链路保护：只扩充本次已授权对象的准确别名，保存仍使用原稳定地址。
                $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
                foreach (['url', 'signed_url'] as $baseName) {
                    $base = (string)($uploadConfig['disks'][$diskKey][$baseName] ?? '');
                    if ($base === '') continue;
                    $cloudAliases[rtrim($base, '/') . '/' . $encodedKey] = $file;
                }
            }
        }
        $attachmentIds = [];
        $storageIds = [];
        $content = $this->mapFileReferences($content, function (string $url) use ($aliases, $cloudAliases, $scopedAliases, $downloads, &$attachmentIds, &$storageIds): string {
            $file = $aliases[$url] ?? null;
            if ($file === null && $scopedAliases) {
                $referenceKey = $downloads->referenceKey($url);
                if ($referenceKey !== '') $file = $scopedAliases[$referenceKey] ?? null;
            }
            if ($file === null) {
                // 仅已知云 host/key 可以忽略签名参数，不泛化删掉外站 host/query。
                $cloudUrl = preg_replace('/[?#].*$/s', '', $url);
                $file = $cloudAliases[$cloudUrl] ?? null;
            }
            if ($file === null) return $url;
            $aid = (int)($file['attachment_id'] ?? 0);
            $sid = (int)($file['storage_id'] ?? ($file['id'] ?? 0));
            if ($aid > 0) $attachmentIds[$aid] = true;
            if ($sid > 0) $storageIds[$sid] = true;
            return (string)$file['url'];
        });
        return ['content' => $content, 'attachmentIds' => array_keys($attachmentIds), 'storageIds' => array_keys($storageIds)];
    }

    /** 提取、绑定和输出复用同一节点规则；不重新序列化整篇 HTML。 */
    public function mapFileReferences(string $content, callable $map): string
    {
        if ($content === '') return '';
        $pattern = '~<(pre|code|script|style)\b[^>]*>.*?</\1\s*>|```[^\r\n]*[\s\S]*?```|`[^`\r\n]*`|<(?:img|a)\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>|!?\[[^\]\r\n]*\]\([^\r\n)]*\)~i';
        $result = preg_replace_callback($pattern, function (array $token) use ($map): string {
            $text = $token[0];
            if ($text[0] === '`' || preg_match('~^<(?:pre|code|script|style)\b~i', $text)) return $text;
            if ($text[0] === '<') {
                $attribute = preg_match('~^<img\b~i', $text) ? 'src' : 'href';
                return preg_replace_callback('~(\s' . $attribute . '\s*=\s*)(["\x27])(.*?)\2~is', function (array $match) use ($map, $attribute): string {
                    $url = htmlspecialchars_decode($match[3], ENT_QUOTES);
                    $mapped = $map($url, $attribute === 'src' ? 'image' : 'link');
                    if ($mapped === $url) return $match[0];
                    return $match[1] . $match[2] . htmlspecialchars($mapped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $match[2];
                }, $text);
            }
            return preg_replace_callback('~^(!?\[[^\]]*\]\()([^\s)]+)(.*\))$~s', function (array $match) use ($map): string {
                return $match[1] . $map(htmlspecialchars_decode($match[2], ENT_QUOTES), $match[1][0] === '!' ? 'image' : 'link') . $match[3];
            }, $text);
        }, $content);
        if ($result === null) throw new \RuntimeException('Cannot parse content file references');
        return $result;
    }

    public function findOnMaster(array $condition, int $afterId = 0, int $limit = 100): array
    {
        return $this->getModel()->findOnMaster($condition, $afterId, $limit);
    }

    public function findStorageReferences(int $storageId, int $afterId = 0, int $limit = 100): array
    {
        return $this->getModel()->findOnMaster(['storage_id' => $storageId], $afterId, $limit);
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_AttachmentService_format_start.php
        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';
        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        // hook app_Services_AttachmentService_format_end.php
    }
}
