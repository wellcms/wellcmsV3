<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

class UploadService
{
    use \Framework\Core\Traits\StatefulTrait;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var \App\Services\Storage\Support\UploadSessionStore */
    private $chunkStore;
    /** @var \Framework\Session\SessionInterface */
    private $userSession;
    /** @var \App\Services\Storage\Support\FileSystemHelper */
    public $fs;
    /** @var \App\Services\Storage\StorageManager */
    private $storage;
    /** @var \App\Services\Storage\Interfaces\StorageInterface */
    private $localDisk; // [快捷引用] 本地磁盘驱动

    /** @var \App\Services\Auth\GroupService */
    private $groupService;
    // 依赖的服务
    /** @var \App\Services\Storage\FileStorageService */
    private $fileStorageService;
    /** @var \App\Services\Storage\AttachmentService */
    private $attachmentService;
    /** @var \App\Services\Content\TempContentService */
    private $tempContentService;
    /** @var \Framework\Core\Container */
    private $container;
    /** @var array 配置对象或数组 */
    private $cfg;
    /** @var string */
    private $uploadNamespace = '';

    public function __construct(
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Services\Auth\GroupService $groupService,
        \App\Services\Storage\FileStorageService $fileStorageService,
        \App\Services\Storage\AttachmentService $attachmentService,
        \App\Services\Content\TempContentService $tempContentService,
        \App\Services\Storage\StorageManager $storage,
        array $uploadConfig,
        \Framework\Core\Container $container
    ) {
        // 获取基础依赖
        $this->cfg = $uploadConfig;
        $this->cache = $cache;

        // 业务服务
        $this->groupService = $groupService;
        $this->fileStorageService = $fileStorageService;
        $this->attachmentService = $attachmentService;
        $this->tempContentService = $tempContentService;
        $this->storage = $storage;
        $this->container = $container;

        // 初始化 Helper (保持原 Controller 逻辑)
        $this->chunkStore = new \App\Services\Storage\Support\UploadSessionStore($this->cache, (array)$this->cfg);
        $this->fs = new \App\Services\Storage\Support\FileSystemHelper((array)$this->cfg);

        // 获取本地磁盘用于初始落盘，确保高性能和可用性
        $this->localDisk = $this->storage->disk('local');
    }

    /**
     * 捕获请求上下文
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $this->setState('language', $request->getAttribute(\App\Interfaces\LanguageLoaderInterface::class));
        $this->setState('session', $request->getAttribute(\Framework\Session\SessionInterface::class));
        $this->setState('ip', \Framework\Utils\IpHelper::ip($request->getServerParams()));
        // 可选: 从 request param 读取 namespace
        $ns = $request->getParsedBody()['namespace'] ?? '';
        if ($ns !== '') {
            $this->uploadNamespace = $ns;
        }
    }

    public function setNamespace(string $namespace): void
    {
        $this->uploadNamespace = $namespace;
    }

    /**
     * 初始化上传
     * 对应原 Controller::init
     */
    public function init(array $user, string $filename, int $filesize, string $mime, string $filehash, int $preferredChunkSize, int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_init_start.php

        if (!$filename || $filesize <= 0) {
            throw new \InvalidArgumentException($this->getLang()->get('parameter_error', ['error' => 'filename/filesize']), 1);
        }

        // 1. 统一配额与权限检查 (包含单文件大小限制、总数、后果及单场限制)
        $quotaCheck = $this->checkUploadQuota($user, $filesize, $filename);
        if (true !== $quotaCheck) {
            throw new \RuntimeException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // hook app_Services_Storage_UploadService_init_before.php

        // 2. 秒传检查
        if ($filehash) {
            $fastData = $this->findHash($filehash);
            if (!empty($fastData)) {
                // [关键修复] 秒传也要写会话，否则发布时将丢失该附件
                $this->saveToSession($filehash, $fastData['data'], $isAttachment);
                return ['is_fast' => true, 'data' => $fastData];
            }
        }

        // hook app_Services_Storage_UploadService_init_center.php

        // 3. 确定上传模式与分片策略
        // 模式分水岭：由 config['upload_size'] (MB) 转换字节决定
        $directThreshold = ($this->cfg['upload_size'] ?? 20) * 1024 * 1024;
        $uploadMode = ($filesize <= $directThreshold) ? 'direct' : 'chunk';

        // 分片大小限制：下限 1MB (保护服务器), 上限取分水岭与 5MB 的较小值 (性能平衡点)
        $minChunk = 1024 * 1024;
        $maxChunk = min($directThreshold, 5 * 1024 * 1024);

        $preferred = $preferredChunkSize > 0 ? $preferredChunkSize : 2 * 1024 * 1024;
        $chunkSize = max($minChunk, min($maxChunk, $preferred));

        $totalChunks = (int)ceil($filesize / $chunkSize);

        // hook app_Services_Storage_UploadService_init_middle.php

        // 4. 写入会话状态
        $meta = [
            'filename'   => $filename,
            'filesize'   => $filesize,
            'mime'       => $mime,
            'filehash'   => $filehash ?: '',
            'chunkSize'  => $chunkSize,
            'total'      => $totalChunks,
            'status'     => 'init',
            'created_at' => time()
        ];

        // hook app_Services_Storage_UploadService_init_after.php

        $uploadId = $this->chunkStore->create($meta);

        // hook app_Services_Storage_UploadService_init_end.php

        return ['is_fast' => false, 'data' => [
            'code' => 0,
            'status' => 'init',
            'uploadId' => $uploadId,
            'chunkSize' => $chunkSize,
            'totalChunks' => $totalChunks,
            'uploaded' => [],
            'uploadMode' => $uploadMode
        ]];
    }

    /**
     * 保存分片
     * 对应原 Controller::uploadChunk
     */
    public function saveChunk(array $user, string $uploadId, int $chunkIdx, int $chunks, \Framework\Http\Interfaces\UploadedFileInterface $file): array
    {
        // hook app_Services_Storage_UploadService_saveChunk_start.php

        // 基础参数校验
        if (!$uploadId || $chunkIdx <= 0 || $chunks <= 0) {
            throw new \InvalidArgumentException('Missing uploadId/chunk/chunks', 1);
        }

        // 配额与会话检查
        // 注意：此处仅检查是否超标，不扣费。分片阶段不重复检查扩展名
        $quotaCheck = $this->checkUploadQuota($user, $file->getSize());
        if (true !== $quotaCheck) {
            throw new \RuntimeException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // hook app_Services_Storage_UploadService_saveChunk_before.php

        $meta = $this->chunkStore->getMeta($uploadId);
        if (!$meta) {
            throw new \RuntimeException($this->getLang()->get('the_session_does_not_exist_or_has_expired'), 1);
        }

        // hook app_Services_Storage_UploadService_saveChunk_center.php

        // 修正总分片数
        $total = (int)($meta['total'] ?? 0);
        if ($total > 0 && $total !== $chunks) {
            $chunks = $total;
        }

        // 1. 获取存储路径 (相对路径)
        $chunkDir = $this->fs->chunkDir($uploadId);
        $partPath = $chunkDir . str_pad((string)$chunkIdx, 6, '0', STR_PAD_LEFT) . '.part';

        // 幂等处理：先删后写 (操作本地磁盘)
        if ($this->localDisk->exists($partPath)) {
            $this->localDisk->delete($partPath);
        }

        // hook app_Services_Storage_UploadService_saveChunk_middle.php

        try {
            // [关键修复] 使用 moveTo 确保原始临时文件被清理
            $file->moveTo($partPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to move chunk: ' . $e->getMessage(), 1);
        }

        // 安全检查 (基于已保存的文件)
        $mime = $this->localDisk->mimeType($partPath);
        if ($this->fs->blockDangerous($mime)) {
            $this->localDisk->delete($partPath);
            throw new \RuntimeException('Illegal shard types detected', 16);
        }

        // hook app_Services_Storage_UploadService_saveChunk_end.php

        // 更新会话
        $this->chunkStore->addPart($uploadId, $chunkIdx);

        return [
            'code' => 0,
            'message' => $this->getLang()->get('upload_successfully'),
            'part' => 'ok',
            'chunkIndex' => $chunkIdx
        ];
    }

    /**
     * 合并分片
     * 对应原 Controller::complete
     * [策略] 本地读取 -> 本地合并 -> 落盘本地 -> 用户提交 -> 关联附件表 -> 触发任务
     */
    public function complete(array $user, string $uploadId, string $filehash = '', string $tempId = '', int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_complete_start.php

        // 1. 秒传检查
        if ($filehash && ($fastData = $this->findHash($filehash))) {
            return ['is_fast' => true, 'data' => $fastData];
        }

        // 2. 校验 Session
        $meta = $this->chunkStore->getMeta($uploadId);
        if (!$meta) throw new \RuntimeException($this->getLang()->get('the_session_does_not_exist_or_has_expired'), 1);

        // hook app_Services_Storage_UploadService_complete_before.php

        $total = (int)($meta['total'] ?? 0);
        $uploaded = $this->chunkStore->getUploadedParts($uploadId);
        if (count($uploaded) < $total) {
            throw new \RuntimeException($this->getLang()->get('not_all_segments_have_been_uploaded'), 1);
        }

        $filename = $meta['filename'] ?? 'file';
        $expectSize = (int)($meta['filesize'] ?? 0);

        $chunkDir = $this->fs->chunkDir($uploadId);
        $safeFilename = $this->fs->safeFilename($filename);
        $finalDir = $this->fs->dailyFinalTmpDir($this->uploadNamespace);
        $finalPath = $finalDir . $safeFilename;

        // 确保目标目录存在
        if (!is_dir(dirname($finalPath))) {
            mkdir(dirname($finalPath), 0755, true);
        }

        // 执行合并 (使用 appendStream 且不计算 Hash)
        // 确保目标文件不存在，避免脏数据追加
        if ($this->localDisk->exists($finalPath)) {
            $this->localDisk->delete($finalPath);
        }

        // hook app_Services_Storage_UploadService_complete_center.php

        // 3. 执行合并 (带锁)
        $lock = new \App\Services\Storage\Support\FileLock($chunkDir . '.merge.lock');

        try {
            for ($i = 1; $i <= $total; $i++) {
                $partPath = $chunkDir . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';

                // 读取分片流
                $readStream = $this->localDisk->readStream($partPath);
                if ($readStream === false) {
                    throw new \RuntimeException($this->getLang()->get('fragment_missing', ['fragment' => $i]), 1);
                }

                // 不传递第三个参数 $hashCtx，使其内部走 stream_copy_to_stream 高性能通道
                $success = $this->localDisk->appendStream($finalPath, $readStream);
                fclose($readStream);
                if (!$success) {
                    throw new \RuntimeException('Failed to append chunk ' . $i);
                }

                // 可选：合并完即删分片，减少磁盘占用
                // $this->localDisk->delete($partPath);
            }

            // hook app_Services_Storage_UploadService_complete_middle.php

            // 4. 校验文件大小 (快速校验)，不计算 Hash，仅校验大小，极快
            $realSize = $this->localDisk->size($finalPath);
            if ($expectSize > 0 && $realSize !== $expectSize) {
                // 大小不一致则视为失败，删除文件
                $this->localDisk->delete($finalPath);
                throw new \RuntimeException($this->getLang()->get('file_size_mismatch', ['filesize' => $expectSize, 'realSize' => $realSize]), 1);
            }

            // hook app_Services_Storage_UploadService_complete_after.php

            unset($lock);
        } catch (\Throwable $e) {
            unset($lock);
            // 发生异常清理可能不完整的文件
            if ($this->localDisk->exists($finalPath)) {
                $this->localDisk->delete($finalPath);
            }
            throw $e;
        }

        // hook app_Services_Storage_UploadService_complete_end.php

        // 清理分片和 Session
        $this->localDisk->deleteDir($chunkDir);
        $this->chunkStore->destroy($uploadId);

        // 8. 持久化 (入库、扣费)
        // 传递 filehashHex 避免重复计算
        return $this->persistFile(
            $finalPath,
            $safeFilename,
            $filename,
            $user,
            $tempId,
            $this->getLang()->get('merge_successfully'),
            $filehash,
            1,
            $isAttachment
        );
    }

    /**
     * 小文件直传
     * 对应原 Controller::direct
     * [策略] 流式Hash -> 落盘本地 -> 用户提交 -> 关联附件表 -> 触发任务
     */
    public function direct(array $user, \Framework\Http\Interfaces\UploadedFileInterface $file, string $filename, int $filesize, string $filehash, string $tempId = '', int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_direct_start.php

        // 1. 配额检查
        $quotaCheck = $this->checkUploadQuota($user, $filesize, $filename, $tempId);
        if (true !== $quotaCheck) {
            throw new \RuntimeException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // 校验
        if ($filesize <= 0) throw new \InvalidArgumentException($this->getLang()->get('parameter_error', ['error' => 'filesize']), 1);
        if ($filesize > $this->cfg['max_file_size']) {
            throw new \RuntimeException($this->getLang()->get('filesize_too_large', [
                'maxsize' => round($this->cfg['max_file_size'] / 1024 / 1024, 2) . 'MB',
                'size' => round($filesize / 1024 / 1024, 2) . 'MB'
            ]), 1);
        }

        // hook app_Services_Storage_UploadService_direct_before.php

        // 秒传检查
        if ($filehash && ($fastData = $this->findHash($filehash))) {
            $this->saveToSession($filehash, $fastData['data'], $isAttachment);
            return ['is_fast' => true, 'data' => $fastData];
        }

        // 获取 PSR-7 输入流并计算 Hash
        $srcStream = $file->getStream();
        $hashCtx = hash_init('sha256');

        // 分块读取流，避免大文件内存溢出
        if ($srcStream->isSeekable()) $srcStream->rewind();
        while (!$srcStream->eof()) {
            $buf = $srcStream->read(8192);
            if ($buf === '') break;
            hash_update($hashCtx, $buf);
        }
        if ($srcStream->isSeekable()) $srcStream->rewind();

        $filehashRaw = hash_final($hashCtx, true);
        $filehashHex = bin2hex($filehashRaw);

        // 查重
        $fastData = $this->findBbHash($filehashRaw, $filehashHex);

        if (!empty($fastData)) {
            // 如果是秒传，手动清理 PSR-7 临时文件
            $uri = (string)$srcStream->getMetadata('uri');
            if ($uri && is_file($uri)) @unlink($uri);

            $this->saveToSession($filehashHex, $fastData['data'], $isAttachment);
            return ['is_fast' => true, 'data' => $fastData];
        }

        // [关键修复] 落盘到正式临时目录，使用 moveTo 彻底清理原始临时文件
        $safeFilename = $this->fs->safeFilename($filename);
        $finalDir = $this->fs->dailyFinalTmpDir($this->uploadNamespace);
        $finalPath = $finalDir . $safeFilename;

        try {
            $file->moveTo($finalPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->getLang()->get('upload_failed') . ': ' . $e->getMessage());
        }

        // 持久化 (入库、扣费)
        return $this->persistFile(
            $finalPath,
            $safeFilename,
            $filename,
            $user,
            $tempId,
            $this->getLang()->get('upload_successfully'),
            $filehashHex,
            0,
            $isAttachment
        );
    }

    /**
     * 核心：文件持久化、查重、入库、扣费
     * 整合了原 processFilePersistence 逻辑
     */
    private function persistFile(string $finalPath, string $safeFilename, string $orgFilename, array $user, string $tempId, string $successMsg, ?string $preCalcHashHex = null, int $largefiles = 0, int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_persistFile_start.php

        // 1. 安全检查 (在本地磁盘上进行)
        $realMime = $this->localDisk->mimeType($finalPath);
        if ($this->isDangerousFile($realMime ?: '', $orgFilename)) {
            $this->localDisk->delete($finalPath);
            throw new \RuntimeException($this->getLang()->get('illegal_file_type', ['name' => 'PHP']), 16);
        }

        $realSize = $this->localDisk->size($finalPath);

        // 生成本地 URL (因为此时文件还在本地)
        list($path, $url) = $this->fs->fileUrl($finalPath);

        // hook app_Services_Storage_UploadService_persistFile_before.php

        // 2. Hash 计算与 DB 查重
        $filehashIndex = $preCalcHashHex;
        $filehash32 = null;

        // 从本地磁盘读取补算
        if (null === $filehashIndex) {
            $stream = $this->localDisk->readStream($finalPath);
            if ($stream) {
                $ctx = hash_init('sha256');
                while (!feof($stream)) hash_update($ctx, fread($stream, 8192));
                fclose($stream);
                $filehashRaw = hash_final($ctx, true);
                $filehash32 = $filehashRaw;
                $filehashIndex = bin2hex($filehashRaw); // 将二进制字符串转换成十六进制字符串
            }

            // hook app_Services_Storage_UploadService_persistFile_center.php

            $fastData = $this->findBbHash($filehash32, $filehashIndex);
            if (!empty($fastData)) {
                $this->localDisk->delete($finalPath);
                $this->saveToSession($filehashIndex, $fastData['data'], $isAttachment);
                return ['is_fast' => true, 'data' => $fastData];
            }
        } else {
            // 将十六进制字符串转换成二进制字符串
            $filehash32 = @hex2bin((string)$filehashIndex);
            if ($filehash32 === false) {
                throw new \RuntimeException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
            }
        }

        // hook app_Services_Storage_UploadService_persistFile_middle.php

        // 扣除配额，只有文件是新的（非秒传）且已落盘
        // 最终状态核实：在入库前再次确认单场文件限制，彻底封堵高并发绕过风险
        $quotaCheck = $this->checkUploadQuota($user, (int)$realSize, $orgFilename, $tempId);
        if (true !== $quotaCheck) {
            $this->localDisk->delete($finalPath);
            throw new \RuntimeException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        $this->recordUploadStats((int)($user['id'] ?? 0), $realSize);

        // 3. 准备数据
        $isImage = $this->fs->isImage($finalPath, $this->cfg['allowed_image_mimes']) ? 1 : 0;
        $ip = \Framework\Utils\IpHelper::ip();

        $data = [
            'filehash' => $filehash32,
            'filesize' => $realSize,
            'width' => 0,
            'height' => 0,
            'is_reviewed' => 1,
            'reviewed_at' => time(),
            'reviewed_by' => 1,
            'exif_cleaned' => 0,
            'is_image' => $isImage,
            'create_ip' => $ip,
            'created_at' => time(),
            'filename' => $safeFilename,
            'orgfilename' => basename($orgFilename),
            'mime' => $realMime ?: 'application/octet-stream',
            'path' => $path,
            'url' => $url,
            'exif_data' => '',
            'target_id' => 0,
            'reply_id' => 0,
            'module' => 0,
            'user_id' => (int)($user['id'] ?? 0),
            'large_files' => $largefiles, // 大文件，提交后需推送定时任务重新计算hash和size
            'is_attachment' => $isAttachment
        ];

        // 4. 写入 Cache 索引 (秒传用)
        $this->saveToCacheIndex($filehashIndex, $data);

        // 5. 记录元数据到 Session (不直接入库，待发布或存稿时关联)
        $this->saveToSession($filehashIndex, $data, $isAttachment);

        // hook app_Services_Storage_UploadService_persistFile_after.php

        // Scheduler 异步任务触发
        // 业务流：上传 -> Temp表（文件在upload_temp目录） -> 用户提交 -> 比对图片数据 -> 从 upload/temp/202601/ 目录复制到 upload/202601/ 目录 -> 写入 file_storage 表 & attachment 表，生成一个 file_storage.id -> 删除upload/temp/202601/文件和目录（判断目录下是否还有文件）
        // 业务逻辑“用户提交表单后才正式入库”，则任务触发可能需要移到 AttachmentService 的 post logic
        // TODO: 1. 大文件触发异步完整性校验 (Hash & Size)
        /* if ($this->cacheConfig['default'] === 'redis' && (int)($data['large_files'] ?? 0) > 0) {
            $taskManage = $this->container->has(\Framework\Scheduler\TaskManage::class)
                ? $this->container->get(\Framework\Scheduler\TaskManage::class)
                : null;

            if ($taskManage) {
                // 推送异步校验任务
                $taskManage->createTask([
                    'className' => \App\Jobs\VerifyIntegrityJob::class,
                    'methodName' => 'handle',
                    'args' => [
                        'fileStorageId' => $fileStorageId
                    ],
                    'priority' => 9, // 设置为低优先级，不阻塞核心业务
                    'retryDelay' => 30,
                    'maxRetries' => 3
                ]);
            }
        } */

        // TODO: 2. 触发异步清洗任务，应该判断是否启用redis和Scheduler，如果未启用则跳过清洗
        /* if ($this->cacheConfig['default'] === 'redis' && $isImage && !empty($this->cfg['clean_exif'])) {
            // Temp 阶段不清洗
            // 获取 TaskManage 实例（包含是否启用检查）
            $scheduler = $this->getTaskManage();
            if ($scheduler) {
                // 推送异步清洗任务
                try {
                    $scheduler->createTask([
                        'className' => \App\Jobs\ImageCleanupJob::class,
                        'methodName' => 'handle',
                        'args' => [
                            'fileStorageId' => $fileStorageId,
                            'path' => $finalPath
                        ],
                        'priority' => 5,
                        'scheduledAt' => time() + 20, // 间隔20秒处理
                        'retryDelay' => 30,
                        'maxRetries' => 3
                    ]);
                } catch (\Throwable $e) {
                    // 容错：推送任务失败不应阻断上传流程，改为降级处理或仅记录日志
                    $this->container->get(LoggerInterface::class)->error('Failed to push cleanup task', ['error' => $e->getMessage()]);
                }
            }
        } */

        // TODO: 3. 提交清洗后执行云储存 UploadToCloudJob 任务
        /* if ($this->cacheConfig['default'] === 'redis') {
            $taskManage = $this->container->has(\Framework\Scheduler\TaskManage::class)
                ? $this->container->get(\Framework\Scheduler\TaskManage::class)
                : null;

            if ($taskManage) {
                // 推送异步校验任务
                $taskManage->createTask([
                    'className' => 'App\\Jobs\\UploadToCloudJob',
                    'methodName' => 'handle',
                    'args' => [
                        'fileStorageId' => $fileStorageId,
                        'cfg' => $uploadConfig
                    ],
                    'priority' => 5,
                    'scheduledAt' => time() + 40, // 间隔20秒处理
                    'retryDelay' => 30,
                    'maxRetries' => 3
                ]);
            }
        } */

        $stored = new \Framework\Http\Psr7\StoredFile($finalPath, $safeFilename, (string)$data['mime'], (int)$realSize);

        // hook app_Services_Storage_UploadService_persistFile_end.php

        return ['is_fast' => false, 'data' => [
            'code' => 0,
            'message' => $successMsg,
            'status' => 'complete',
            'file' => [
                'name' => $orgFilename,
                'size' => \Framework\Utils\FormatHelper::humanSize((int)$realSize),
                'url'  => $url,
                'mime' => $data['mime'],
                'hash' => $filehashIndex
            ]
        ]];
    }

    /**
     * 将临时文件记录到 Session
     */
    private function saveToSession(string $hash, array $data, int $isAttachment = 0): void
    {
        $key = 'tmp_files';
        $session = $this->getState('session');
        if ($session instanceof \Framework\Session\SessionInterface) {
            $tmpFiles = $session->get($key, []);

            // 确保数据中不含非 UTF-8 字符（如二进制 Hash），防止 PostgreSQL 写入报错
            $data = $this->sanitizeMetadataForSession($data);

            $data['is_attachment'] = $isAttachment;
            $tmpFiles[$hash] = $data;
            $session->set($key, $tmpFiles);
        }
    }

    /**
     * 清洗元数据，确保所有二进制字段转为 Hex 字符串
     */
    public function sanitizeMetadataForSession(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->sanitizeMetadataForSession($v);
            } elseif (is_string($v)) {
                // 如果是 32 字节的二进制 Hash，转为 Hex
                if (strlen($v) === 32 && !mb_check_encoding($v, 'UTF-8')) {
                    $data[$k] = bin2hex($v);
                }
            }
        }
        return $data;
    }

    /**
     * 恢复元数据，将 Hex 字符串转回二进制
     */
    public function restoreMetadata(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->restoreMetadata($v);
            } elseif (is_string($v)) {
                // 如果长度为 64 的 Hex 字符串且 Key 是 filehash，恢复为 32 字节二进制
                if ($k === 'filehash' && strlen($v) === 64 && ctype_xdigit($v)) {
                    $data[$k] = hex2bin($v);
                }
            }
        }
        return $data;
    }

    /**
     * 根据 Hash 删除临时文件并清理 Session
     */
    public function deleteTempByHash(string $hash): bool
    {
        $key = 'tmp_files';
        $session = $this->getState('session');
        if ($session instanceof \Framework\Session\SessionInterface) {
            $tmpFiles = $session->get($key, []);
            if (isset($tmpFiles[$hash])) {
                $path = $tmpFiles[$hash]['path'] ?? '';
                if ($path && strpos($path, 'temp') !== false) {
                    $fullPath = $this->fs->normalizePath($path);
                    if (is_file($fullPath)) {
                        @unlink($fullPath);
                    }
                }
                unset($tmpFiles[$hash]);
                $session->set($key, $tmpFiles);
                return true;
            }
        }
        return false;
    }

    // 查缓存和DB
    public function findHash( string $filehash= ''): array
    {
        if ($filehash) {
            $filehash = \App\Services\Storage\Support\Util::normalizeHash($filehash);
            if ($filehash) {
                // 查 Cache 索引
                $key = "hash:index:{$filehash}";
                $hit = $this->cache->get($key); // 对应原 findByHash

                if (empty($hit) || empty($hit['path'])) {
                    // 查库
                    $filehash32 = @hex2bin($filehash);
                    if ($filehash32 === false) return [];
                    $hit = $this->fileStorageService->getByFilehash($filehash32);
                }

                if ($hit && !empty($hit['path']) && $this->localDisk->exists($hit['path'])) {
                    // 同步 Cache 索引
                    $this->saveToCacheIndex($filehash, $hit);

                    // [关键修复] 清洗元数据防止二进制字符进入 Session
                    $sanitizedHit = $this->sanitizeMetadataForSession($hit);
                    return [
                        'code' => 0,
                        'message' => $this->getLang()->get('upload_successfully'),
                        'status' => 'complete',
                        'data' => $sanitizedHit,
                        'file' => [
                            'name' => isset($hit['orgfilename']) ? $hit['orgfilename'] : (isset($hit['filename']) ? $hit['filename'] : basename($hit['path'])),
                            'size' => isset($hit['filesize']) ? (int)$hit['filesize'] : 0,
                            'url'  => isset($hit['url']) ? $hit['url'] : '',
                            'mime' => isset($hit['mime']) ? $hit['mime'] : 'application/octet-stream',
                        ]
                    ];
                }
            }
        }
        return [];
    }

    private function findBbHash(string $filehash32, string $filehashIndex): array
    {
        // 1. 优先查 Cache 索引 (可能在 temp 中)
        $key = "hash:index:{$filehashIndex}";
        $hit = $this->cache->get($key);

        if (empty($hit) || empty($hit['path'])) {
            // 2. 查 DB (正式库)
            $hit = $this->fileStorageService->getByFilehash($filehash32);
        }

        if ($hit && !empty($hit['path'])) {
            // 无论从哪里查到，都更新/同步 Cache 索引
            $this->saveToCacheIndex($filehashIndex, $hit);

            // [关键修复] 清洗元数据防止二进制字符进入 Session
            $sanitizedHit = $this->sanitizeMetadataForSession($hit);

            return [
                'code' => 0,
                'message' => $this->getLang()->get('upload_successfully'),
                'status' => 'complete',
                'data' => $sanitizedHit,
                'file' => [
                    'name' => $hit['orgfilename'] ?? ($hit['filename'] ?? basename($hit['path'])),
                    'size' => (int)($hit['filesize'] ?? 0),
                    'url'  => $hit['url'] ?? '',
                    'mime' => $hit['mime'] ?? 'application/octet-stream',
                    'hash' => $filehashIndex
                ]
            ];
        }
        return [];
    }

    private function saveToCacheIndex(string $filehash, array $meta): void{
        if (!$filehash) return;
        $key = "hash:index:{$filehash}";
        // 关键：在存入 Cache/Redis 前，必须清洗二进制数据（如 filehash 二进制串），否则 json_encode 会失败
        $meta = $this->sanitizeMetadataForSession($meta);
        $this->cache->set($key, $meta); // 长期索引
    }

    /**
     * 检查配额 (只读，不扣费)
     * 补齐方案：增加单场数量限制和基于数字ID的后缀权限控制
     * @return bool
     */
    private function checkUploadQuota(array $user, int $filesize, string $filename = '', string $tempId = '')
    {
        $userId = (int)($user['id'] ?? 0);
        $groupId = (int)($user['group_id'] ?? 0);

        // 1. 管理员策略：保留最高权限，仅受物理配置限制
        if ($groupId === 1) return true;

        // 2. 获取用户组实时动态配置 (由 GroupService 实现缓存)
        $group = $this->groupService->read($groupId);
        if (empty($group)) {
            return ['msg' => $this->getLang()->get('user_group_insufficient_privileges'), 'code' => 15];
        }

        // 3. 基础权限检查
        if (empty($group['upload'])) {
            return ['msg' => $this->getLang()->get('user_group_insufficient_privileges'), 'code' => 15];
        }

        // 4. 解析配额指标 (DB 字段优先，若为 0 则降级到全局 Config)
        $limitSingle = (int)($group['quota_single_size'] ?: ($this->cfg['limit_defaults']['quota_single_size_default'] ?? ($this->cfg['max_file_size'] ?? 0)));
        $limitDailySize = (int)($group['quota_daily_size'] ?: ($this->cfg['limit_defaults']['quota_daily_size_default'] ?? 0));
        $limitDailyCount = (int)($group['upload_daily_quota'] ?: ($this->cfg['limit_defaults']['upload_daily_quota'] ?? 50));
        $limitPerPost = (int)($group['upload_per_post'] ?: ($this->cfg['limit_defaults']['upload_per_post'] ?? 10));

        // 5. 后缀权限校验 (基于数字 ID 重排)
        if ($filename !== '') {
            $allowedId = (int)($group['allowed_file_types'] ?? 0);
            $presetList = [];
            // 如果 ID 为 0，FileSystemHelper 会自动 fallback 到全局 allowed_ext
            if ($allowedId > 0 && !empty($this->cfg['type_presets'][$allowedId])) {
                $presetList = $this->cfg['type_presets'][$allowedId];
            }

            if (!$this->fs->allowedExt($filename, $presetList)) {
                return ['msg' => $this->getLang()->get('illegal_file_type', ['name' => pathinfo($filename, PATHINFO_EXTENSION)]), 'code' => 16];
            }
        }

        // 6. 单文件大小拦截
        if ($limitSingle > 0 && $filesize > $limitSingle) {
            return [
                'msg' => $this->getLang()->get('file_size_mismatch', [
                    'filesize' => round($filesize / 1024 / 1024, 2) . 'MB',
                    'realSize' => round($limitSingle / 1024 / 1024, 2) . 'MB'
                ]),
                'code' => 1
            ];
        }

        // 7. 单场发布数量限制 (upload_per_post)
        // 核心点：检查 Session 或已有 TempContent 中的文件数
        $currentTempCount = 0;
        $key = 'tmp_files';
        if ($this->userSession) {
            $currentTempCount = count($this->userSession->get($key, []));
        }

        if ($tempId) {
            $tempContent = $this->tempContentService->read($tempId);
            if ($tempContent && !empty($tempContent['data_fmt'][$key])) {
                $currentTempCount = max($currentTempCount, count($tempContent['data_fmt'][$key]));
            }
        }

        if ($limitPerPost > 0 && ($currentTempCount + 1) > $limitPerPost) {
            return ['msg' => $this->getLang()->get('upload_limit_exceeded', ['n' => $limitPerPost]), 'code' => 15];
        }

        // 8. 每日累计指标检查 (基于原子锁的读取与判定，防止高并发绕过)
        $lockKey = "lock:upload_quota:{$userId}";
        $todayKey = "user_upload_today:{$userId}:" . date('Ymd');

        // 为了保持逻辑内聚且严谨，防止高并发绕过，对于“配额判定”采取手动原子锁闭环。
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) {
            return ['msg' => $this->getLang()->get('server_busy_try_later'), 'code' => 503];
        }

        try {
            $todayStats = $this->cache->get($todayKey) ?: ['files' => 0, 'size' => 0];

            // 数量限制判断
            if ($limitDailyCount > 0 && $todayStats['files'] >= $limitDailyCount) {
                return ['msg' => $this->getLang()->get('daily_files_limit', ['daily_files' => $limitDailyCount]), 'code' => 15];
            }

            // 容量限制判断
            if ($limitDailySize > 0 && ($todayStats['size'] + $filesize) > $limitDailySize) {
                $remainingMb = round(max(0, $limitDailySize - $todayStats['size']) / 1024 / 1024, 2);
                return [
                    'msg' => $this->getLang()->get('daily_storage_limit_reached', ['remaining' => $remainingMb]),
                    'code' => 1
                ];
            }

            return true;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * 检查文件是否需要审核或是否为危险文件
     */
    public function isDangerousFile(string $mimetype, string $filename): bool
    {
        // 从配置获取需要审核的文件类型
        $requireReview = $this->cfg['require_review'] ?? [
            'application/x-msdownload', // exe
            'application/x-sh',         // sh
            'application/x-bat',        // bat
            'application/x-php',        // php
            'text/html',                // html
            'text/javascript',          // js
        ];

        // 1. 检查MIME类型
        if (in_array($mimetype, $requireReview)) {
            return true;
        }

        // 2. 检查文件扩展名
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $dangerousExts = ['exe', 'bat', 'sh', 'php', 'js', 'html', 'htm'];
        if (in_array($ext, $dangerousExts)) {
            return true;
        }

        return false;
    }

    protected function getLang(): \App\Interfaces\LanguageLoaderInterface
    {
        return $this->getState('language') ?? $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
    }

    /**
     * 记录统计 (扣费) - 引入原子锁防止统计丢失
     */
    private function recordUploadStats(int $userId, int $filesize): void
    {
        $lockKey = "lock:upload_quota:{$userId}";
        $todayKey = "user_upload_today:{$userId}:" . date('Ymd');

        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return; // 容错：即使没抢到锁也继续（因为持久化已完成），避免阻断用户，但会有极端概率统计偏差

        try {
            $todayStats = $this->cache->get($todayKey) ?: ['files' => 0, 'size' => 0];
            $todayStats['files']++;
            $todayStats['size'] += $filesize;

            $tomorrow4am = strtotime('tomorrow 4:00') - time();
            $this->cache->set($todayKey, $todayStats, $tomorrow4am);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }
}
