<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

use Framework\Exception\BusinessException;
use Framework\Utils\Validator;

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
        \App\Models\AttachmentModel $dbModel,
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
        $this->dbModel = $dbModel;
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

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        $filehash = $data['filehash'] ?? '';
        if (empty($filehash)) return $this->dbModel->insert($data);

        // 基于 filehash 的原子锁
        $lockKey = 'lock:attach:insert:' . $filehash;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        if (isset($data['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
            $data['create_ip'] = $ip2bin;
        }

        try {
            $exists = $this->dbModel->read(['filehash' => $filehash]);
            if (!empty($exists)) return (int)$exists['id'];

            $result = $this->dbModel->insert($data);
            if (!$result) throw new BusinessException('AttachmentService -> insert(): Data writing failed');

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

        $result = $this->dbModel->bulkInsert($data);
        if (!$result) throw new BusinessException('AttachmentService -> bulkInsert() data writing failed : ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
            $oldData = $this->dbModel->read(['id' => $id]);
            if (empty($oldData)) return 0;

            $result = $this->dbModel->update(['id' => $id], $update);
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
            $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
        } else {
            $data = $this->cache->cacheWithLock(
                'attach:' . $id,
                'lock:attach:read:' . $id,
                function () use ($id, $orderby, $fields) {
                    $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
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
                $data = $this->dbModel->read(['id' => $id], $orderby, $fields);
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

        $datalist = $this->dbModel->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_AttachmentService_find_end.php

        return $datalist;
    }

    public function delete(array $condition = []): int
    {
        // hook app_Services_AttachmentService_delete_start.php
        $result = $this->dbModel->delete($condition);
        // hook app_Services_AttachmentService_delete_end.php
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
        // hook app_Services_AttachmentService_maxid_start.php
        $maxId = $this->dbModel->maxid();
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
    public function deleteWithFiles($ids, int $operatorUid): bool
    {
        if (empty($ids)) return false;
        $idsArray = (array)$ids;

        // 1. 查询文件完整信息
        $datalist = $this->dbModel->find(['id' => $idsArray], [], 1, count($idsArray));
        if (empty($datalist)) return false;

        $userIdsCount = [];
        $deletedIds = [];

        foreach ($datalist as $item) {
            $id = (int)$item['id'];
            $storageId = (int)$item['storage_id'];

            // 2. 物理层委托清理 (原子递减引用计数 -> 引用为0时清理磁盘/云端)
            if ($storageId) {
                $this->fileStorageService->decrementAndCleanup($storageId);
            }

            // 3. 记录日志
            $this->logService->insert([
                'type' => 28, // 附件清理
                'from_user_id' => $operatorUid,
                'user_id' => $item['user_id'],
                'target_id' => $item['target_id'],
                'target_title' => $item['filename'],
                'remark' => $this->getLang()->get('delete_remark', ['id' => $id, 'filename' => $item['filename'] ?? 'None']),
                'created_at' => time(),
                'create_ip' => \Framework\Utils\IpHelper::ip()
            ]);

            $deletedIds[] = $id;

            // 4. 统计待更新用户
            if (!empty($item['user_id'])) {
                $userId = (int)$item['user_id'];
                isset($userIdsCount[$userId]) ? $userIdsCount[$userId]++ : $userIdsCount[$userId] = 1;
            }
        }

        // 5. 执行数据库物理删除
        $res = $this->dbModel->delete(['id' => $deletedIds]);
        if ($res === 0) return false;

        // 6. 批量更新用户上传统计
        if (!empty($userIdsCount)) {
            $updates = [];
            foreach ($userIdsCount as $userId => $count) {
                $updates[] = [
                    'id' => $userId,
                    'total_uploads-' => $count
                ];
            }
            $this->userService->bulkUpdate($updates);
        }

        return true;
    }

    /**
     * 审核附件
     */
    public function review(int $id, int $status, string $note, int $reviewerId): bool
    {
        Validator::make(
            ['id' => $id, 'status' => $status],
            ['id' => 'required|int', 'status' => 'required|int']
        );

        $update = [
            'is_reviewed' => $status,
            'reviewed_at' => time(),
            'reviewed_by' => $reviewerId,
            'review_note' => $note
        ];

        return (bool)$this->update($id, $update);
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
            return $this->dbModel->read(['filehash' => $filehash]);
        }

        return $this->cache->cacheWithLock(
            'attach_hash:' . $filehash,
            'lock:attach_hash:read:' . $filehash,
            function () use ($filehash) {
                return $this->dbModel->read(['filehash' => $filehash]);
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
    public function finalizeAttachments($targetId, $replyId, int $module, array $fileList, int $userId, string $content = ''): array
    {
        $uploadService = $this->container->get(\App\Services\Storage\UploadService::class);
        // 恢复从 Session/Cache 读取的 Hex 数据为二进制，确保后续 Hash 匹配和入库正确
        $fileList = $uploadService->restoreMetadata($fileList);

        $fileStorageService = $this->container->get(\App\Services\Storage\FileStorageService::class);

        // 1. [内容即真理性扫描] 提取内容中的所有相对路径 URL
        $contentUrls = $this->extractUrls($content);

        // 捡回漏检：扫描内容中包含 upload/temp/ 的路径，补入待处理列表
        foreach ($contentUrls as $url) {
            if (strpos($url, 'upload/temp/') !== false) {
                // 提取相对路径，排除可能的域名或 ../ 前缀
                $relativePath = '';
                if (preg_match('/(?:storage\/)?upload\/temp\/[^\s"\']+/i', $url, $m)) {
                    $relativePath = $m[0];
                    // 确保路径包含 storage/，因为 config 中定义的 upload_temp 是 /storage/upload/temp/
                    if (strpos($relativePath, 'storage/') !== 0) {
                        $relativePath = 'storage/' . $relativePath;
                    }
                }

                if ($relativePath) {
                    $fullPath = rtrim(APP_PATH, '/\\') . '/' . ltrim($relativePath, '/\\');
                    if (is_file($fullPath)) {

                        $ip = \Framework\Utils\IpHelper::ip();
                        // 使用文件路径作为 md5 临时键，避免冲突
                        $hashKey = md5($fullPath);
                        if (!isset($fileList[$hashKey])) {
                            // 补齐 session 中缺失的基本元数据
                            $fileHashBin = hash_file('sha256', $fullPath, true);
                            $fileList[$hashKey] = [
                                'path' => $fullPath,
                                'url' => $url,
                                'is_image' => 1,
                                'is_attachment' => 0,
                                'filename' => basename($fullPath),
                                'orgfilename' => basename($fullPath),
                                'filesize' => filesize($fullPath),
                                'mime' => 'image/jpeg',
                                'filehash' => $fileHashBin,
                                'create_ip' => $ip,
                                'is_scan' => 1, // 标记为内容扫描发现
                            ];
                        }
                    }
                }
            }
        }

        if (empty($fileList)) return ['content' => $content, 'attachments' => [], 'image_count' => 0, 'file_count' => 0, 'storage_ids' => [], 'total_uploads' => 0];

        $attachments = [];
        $storageIds = [];
        $imageCount = 0;
        $fileCount = 0;
        $finalNewCount = 0;
        $processedHashes = []; // 用于正文引用的图片统计去重

        foreach ($fileList as $key => $data) {
            $fileUrl = $data['url'] ?? '';
            $isImage = (int)($data['is_image'] ?? 0);
            $isAttachment = (int)($data['is_attachment'] ?? 0);
            $fileHashBin = $data['filehash']; // 二进制字符串

            // 提取内容中的引用状态
            $isReferenced = false;
            foreach ($contentUrls as $url) {
                // 忽略域名和协议进行比对，增强鲁棒性
                $cleanUrl = parse_url($url, PHP_URL_PATH) ?: $url;
                $cleanFileUrl = parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl;

                // 去除开头的斜杠进行松散匹配
                if (ltrim($cleanFileUrl, '/') === ltrim($cleanUrl, '/') ||
                    strpos($cleanUrl, $cleanFileUrl) !== false ||
                    strpos($cleanFileUrl, $cleanUrl) !== false) {
                    $isReferenced = true;
                    break;
                }
            }

            // A. 孤儿清理逻辑：如果是图片且不是显式附件，且正文没引用，且是内容扫描出来的（非显式上传），则跳过
            if (empty($data['is_scan']) === false && !$isReferenced && !$isAttachment && $isImage) continue;

            // B. 深度去重校验：在物理搬运前先查库
            $existing = $fileStorageService->getByFilehash($fileHashBin);
            $alreadyStored = false;
            $newPath = $data['path'];
            $newUrl = $data['url'];

            if ($existing && !empty($existing['path']) && is_file($existing['path'])) {
                // 库中已存在且物理文件完好
                $storageId = $existing['id'];
                $newUrl = $existing['url'];
                $newPath = $existing['path'];
                $alreadyStored = true;

                // 如果当前文件在临时目录，说明是重复上传，直接清理临时文件
                if (strpos($data['path'] ?? '', 'upload/temp') !== false && is_file($data['path'])) {
                    @unlink($data['path']);
                }
            }

            // C. 如果库中不存在，则进行物理搬运与新记录入库
            if (!$alreadyStored) {
                $isFast = (strpos($data['path'] ?? '', 'upload/temp') === false);

                if (!$isFast) {
                    try {
                        list($newPath, $newUrl) = $fileStorageService->promoteFile($data['path']);
                    } catch (\Throwable $e) {
                        continue;
                    }
                }

                $storageData = [
                    'filehash' => $fileHashBin,
                    'filesize' => $data['filesize'],
                    'width' => $data['width'] ?? 0,
                    'height' => $data['height'] ?? 0,
                    'is_reviewed' => $data['is_reviewed'] ?? 1,
                    'reviewed_at' => time(),
                    'reviewed_by' => 1,
                    'exif_cleaned' => $data['exif_cleaned'] ?? 1,
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

            if (empty($storageId)) continue;

            // [URL 强同步] 无论是新入库还是去重命中，都确保内容中的 URL 指向最终的永久地址
            if ($content !== '' && !empty($data['url']) && $data['url'] !== $newUrl) {
                // 尝试多种替换方案：全路径、去掉域名后的路径、去掉开头斜杠的路径
                $content = str_replace($data['url'], $newUrl, $content);
                $relUrl = parse_url($data['url'], PHP_URL_PATH) ?: $data['url'];
                $newRelUrl = parse_url($newUrl, PHP_URL_PATH) ?: $newUrl;
                if ($relUrl !== $data['url']) $content = str_replace($relUrl, $newRelUrl, $content);

                // 兜底：处理可能被实体编码的 URL
                $encodedUrl = htmlspecialchars($data['url'], ENT_QUOTES);
                if ($encodedUrl !== $data['url']) $content = str_replace($encodedUrl, htmlspecialchars($newUrl, ENT_QUOTES), $content);
            }

            // D. 写入 well_attachment 关联表 (增加冲突检测，防止重复关联导致的引用计数错误)
            if ($replyId) {
                // 评论附件
                $condition = ['module' => $module, 'reply_id' => $replyId, 'storage_id' => $storageId];
            } else {
                // 主题附件
                $condition = ['module' => $module, 'target_id' => $targetId, 'storage_id' => $storageId];
            }

            $existsAssociation = $this->dbModel->read($condition);

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
    }

    /**
     * 从内容中提取所有可能的 URL (支持 HTML 和 Markdown)
     */
    public function extractUrls(string $content): array
    {
        if ($content === '') return [];

        $urls = [];
        // 1. 匹配 HTML 标签属性 (src, href)
        if (preg_match_all('/(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        // 2. 匹配 Markdown 图片和链接
        if (preg_match_all('/\!\[.*?\]\((.*?)\)/', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }
        if (preg_match_all('/\[.*?\]\((.*?)\)/i', $content, $matches)) {
            $urls = array_merge($urls, $matches[1]);
        }

        // 3. 处理实体编码并去重
        $urls = array_map(function($url) {
            return htmlspecialchars_decode($url, ENT_QUOTES);
        }, $urls);

        return array_unique(array_filter($urls));
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
