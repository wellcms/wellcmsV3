<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

class TempCleanupService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var array */
    private $config;       // temp_cleanup 配置块
    /** @var string */
    private $tempRoot;     // upload_temp 绝对路径
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var \App\Services\Content\TempContentService */
    private $tempContentService;
    /** @var \App\Services\Storage\Support\FileSystemHelper */
    private $fs;
    /** @var \Framework\Core\Container */
    protected $container;

    // hook app_Services_Storage_TempCleanupService_construct_start.php

    public function __construct(
        array $uploadConfig,
        \Framework\Logger\LoggerInterface $logger,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Services\Content\TempContentService $tempContentService,
        \Framework\Core\Container $container
    ) {
        // 铁律 #25：不使用 ?? 兜底，config 缺失时 PHP Warning 自然暴露
        $this->config = $uploadConfig['temp_cleanup'];
        $this->logger = $logger;
        $this->cache = $cache;
        $this->tempContentService = $tempContentService;
        $this->container = $container;
        // FileSystemHelper 遵循现有模式（UploadService/FileStorageService）内联 new，
        // 不走容器注入（该类无接口，纯工具性质）
        $this->fs = new \App\Services\Storage\Support\FileSystemHelper($uploadConfig);
        $this->tempRoot = $this->fs->normalizePath($uploadConfig['upload_temp']);

        // 注：ul_* 目录删除使用 $this->fs->cleanupDir()（scandir + unlink + rmdir），
        //     单个文件删除使用 @unlink()。
        //     若需要 StorageManager 抽象层，可通过 $this->container->get(StorageManager::class)
        //     ->disk('local')->deleteDir()，但 FileSystemHelper 已满足需求且更轻量。
    }

    // hook app_Services_Storage_TempCleanupService_construct_end.php

    /**
     * 完整清理（Scheduler Job + 管理员手动触发）
     *
     * 执行两阶段扫描（见 §五 决策矩阵）。
     *
     * 防御：首行检查 $this->tempRoot 目录是否存在（兼容首次安装/误删场景）。
     * 批量删除后调用 clearstatcache() 避免 PHP stat 缓存返回过期信息。
     *
     * @param int $batchSize 单次最大处理条目数。
     *   0 表示从 $this->config['scheduler_batch_size'] 读取（Scheduler Job 路径）
     *   >0 表示显式覆盖（管理员手动触发路径）
     * @return array{
     *   deleted_dirs: int,           // 删除的 ul_* 目录数
     *   deleted_files: int,          // 删除的文件数
     *   freed_bytes: int,            // 释放的字节数
     *   protected_by_namespace: int, // 策略 A 保护的文件数
     *   protected_by_ref: int,       // 策略 B 保护的文件数
     *   empty_dirs_removed: int,     // 清理的空目录数
     *   errors: int,                 // 异常数
     *   execution_ms: float          // 执行耗时
     * }
     */
    public function clean(int $batchSize = 0): array
    {
        $startTime = microtime(true);
        $emptyStats = array(
            'deleted_dirs' => 0,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'protected_by_namespace' => 0,
            'protected_by_ref' => 0,
            'empty_dirs_removed' => 0,
            'errors' => 0,
            'execution_ms' => 0,
        );

        if ($batchSize <= 0) {
            $batchSize = $this->config['scheduler_batch_size'];
        }

        // 1) tempRoot 存在性检查
        if (!is_dir($this->tempRoot)) {
            $this->fs->ensureDir($this->tempRoot);
            return $emptyStats;
        }

        // 2) 获取原子锁
        $token = $this->cache->lock('temp_cleanup_lock', 30);
        if ($token === null) {
            return $emptyStats;
        }
        try {
            // 3) 构建保护集（策略 A + B）
            $namespaceTtlMap = $this->buildNamespaceTtlMap();
            $protectedSet = $this->buildProtectedPathSet();

            // 4) 扫描 tempRoot 第一层
            $entries = scandir($this->tempRoot);
            $processed = 0;
            $stats = $emptyStats;

            // 5) 遍历
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if ($processed >= $batchSize) break;

                $fullPath = $this->tempRoot . $entry;

                // ul_* 目录
                if (strpos($entry, 'ul_') === 0 && is_dir($fullPath)) {
                    $result = $this->processChunkDir($fullPath, $entry);
                    $processed += $result['processed'];
                    $stats = array_merge($stats, $result['stats']);
                    continue;
                }

                // namespace 目录（白名单）
                $nsTtl = $namespaceTtlMap[$entry] ?? null;
                if ($nsTtl !== null && is_dir($fullPath)) {
                    $result = $this->processNsDir($fullPath, $entry, $nsTtl, $batchSize - $processed, $protectedSet);
                    $processed += $result['processed'];
                    $stats = array_merge($stats, $result['stats']);
                    continue;
                }

                // YYYYMM/ 目录（默认 namespace）
                if (preg_match('/^\d{6}$/', $entry) && is_dir($fullPath)) {
                    $result = $this->processDefaultDir($fullPath, $entry, $batchSize - $processed, $protectedSet);
                    $processed += $result['processed'];
                    $stats = array_merge($stats, $result['stats']);
                    continue;
                }
            }

            // 6) 清理文件系统 stat 缓存
            clearstatcache();

            $stats['execution_ms'] = round((microtime(true) - $startTime) * 1000, 2);
            return $stats;
        } catch (\Throwable $e) {
            $this->logger->error('Cleanup failed', array('error' => $e->getMessage()));
            return array_merge($emptyStats, array('errors' => 1));
        } finally {
            $this->cache->unlock('temp_cleanup_lock', $token);
        }
    }

    /**
     * 轻量清理（FPM 概率 GC 专用）
     *
     * 仅处理明确超时的文件，保守跳过灰色区：
     *   - ul_* 目录 > chunk_max_age → 删除
     *   - 默认 namespace 文件 > whitelist_max_age (24h) → 删除
     *   - 白名单 namespace 文件 > ns_ttl → 删除
     *   - 灰色区（6h-24h 默认文件、1h-ns_ttl namespace 文件）→ 跳过
     *
     * 不查询 TempContent（保持请求路径轻量）。
     * 灰色区文件由每日 Scheduler Job 做精确判定。
     *
     * 防御：首行检查 $this->tempRoot 目录是否存在。
     *
     * @param int $batchSize 单次上限。
     *   0 表示从 $this->config['gc_batch_size'] 读取（概率 GC 路径）
     *   >0 表示显式覆盖
     * @return array 同 clean() 结构
     */
    public function cleanLight(int $batchSize = 0): array
    {
        $startTime = microtime(true);
        $emptyStats = array(
            'deleted_dirs' => 0,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'protected_by_namespace' => 0,
            'protected_by_ref' => 0,
            'empty_dirs_removed' => 0,
            'errors' => 0,
            'execution_ms' => 0,
        );

        if ($batchSize <= 0) {
            $batchSize = $this->config['gc_batch_size'];
        }

        // 1) tempRoot 存在性检查
        if (!is_dir($this->tempRoot)) {
            $this->fs->ensureDir($this->tempRoot);
            return $emptyStats;
        }

        // 2) 获取原子锁
        $token = $this->cache->lock('temp_cleanup_lock', 10);
        if ($token === null) {
            return $emptyStats;
        }
        try {
            // 3) 仅策略 A：构建 namespace TTL 映射（不查 TempContent）
            $namespaceTtlMap = $this->buildNamespaceTtlMap();

            // 4) 扫描 tempRoot 第一层
            $entries = scandir($this->tempRoot);
            $processed = 0;
            $stats = $emptyStats;

            // 5) 遍历
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if ($processed >= $batchSize) break;

                $fullPath = $this->tempRoot . $entry;

                // ul_* 目录
                if (strpos($entry, 'ul_') === 0 && is_dir($fullPath)) {
                    $mtime = filemtime($fullPath);
                    $age = time() - $mtime;
                    if ($age > $this->config['chunk_max_age']) {
                        $this->fs->cleanupDir($fullPath . '/');
                        $stats['deleted_dirs']++;
                        $processed++;
                    }
                    continue;
                }

                // namespace 目录（白名单）
                $nsTtl = $namespaceTtlMap[$entry] ?? null;
                if ($nsTtl !== null && is_dir($fullPath)) {
                    $result = $this->processNsDirLight($fullPath, $entry, $nsTtl, $batchSize - $processed);
                    $processed += $result['processed'];
                    $stats = array_merge($stats, $result['stats']);
                    continue;
                }

                // YYYYMM/ 目录（默认 namespace）：cleanLight 仅处理 > whitelist_max_age
                if (preg_match('/^\d{6}$/', $entry) && is_dir($fullPath)) {
                    $inner = scandir($fullPath);
                    foreach ($inner as $sub) {
                        if ($sub === '.' || $sub === '..') continue;
                        if ($processed >= $batchSize) break;
                        $filePath = $fullPath . '/' . $sub;
                        if (!is_file($filePath)) continue;
                        $mtime = filemtime($filePath);
                        $age = time() - $mtime;
                        if ($age < $this->config['min_age']) continue;
                        // cleanLight：仅清理 > whitelist_max_age 的明确孤儿
                        if ($age > $this->config['whitelist_max_age']) {
                            $size = filesize($filePath);
                            @unlink($filePath);
                            $stats['deleted_files']++;
                            $stats['freed_bytes'] += $size;
                            $processed++;
                        }
                    }
                    // 目录空 → 删目录
                    if (is_dir($fullPath)) {
                        $remaining = scandir($fullPath);
                        if (count($remaining) === 2) {
                            @rmdir($fullPath);
                            $stats['empty_dirs_removed']++;
                        }
                    }
                }
            }

            clearstatcache();
            $stats['execution_ms'] = round((microtime(true) - $startTime) * 1000, 2);
            return $stats;
        } catch (\Throwable $e) {
            $this->logger->warning('CleanLight failed', array('error' => $e->getMessage()));
            return array_merge($emptyStats, array('errors' => 1));
        } finally {
            $this->cache->unlock('temp_cleanup_lock', $token);
        }
    }

    /**
     * 概率 GC 入口
     *
     * 供 UploadController::init() 首行调用。
     * 内置防抖：两次 GC 间隔 ≥ gc_min_interval。
     * 概率命中后调用 cleanLight()。
     */
    public function maybeTriggerGC(): void
    {
        try {
            // 容错：config 缺失时不阻断（无缓存或无配置也可正常使用）
            if (empty($this->config)) return;
            if (!$this->shouldClean()) return;
            $probability = $this->config['gc_probability'];
            if (mt_rand(1, 100) > ($probability * 100)) return;
            $this->cache->set('temp_cleanup_last_gc', time());
            $this->cleanLight();
        } catch (\Throwable $e) {
            // 铁律 #25：异常不静默
            $this->logger->warning('maybeTriggerGC failed', array(
                'error' => $e->getMessage(),
            ));
        }
    }

    /**
     * 策略 B：构建 TempContent 保护路径集
     *
     * 查询 well_temp_content（最近 draft_lookup_days 天，LIMIT draft_lookup_limit）
     * → 逐行提取 data_fmt['tmp_files'][*]['path']
     * → normalizePathForLookup() → 返回 ['normalized_path' => true, ...]
     *
     * 异常处理（铁律 #25）：
     *   - JSON 解析失败 → warning 日志 → 跳过该行
     *   - 路径非 temp 目录 → 跳过（正式库路径不加入保护集）
     *
     * @return array<string, true>
     */
    private function buildProtectedPathSet(): array
    {
        $protected = array();
        if (empty($this->tempContentService)) {
            return $protected;
        }
        try {
            $drafts = $this->tempContentService->find(
                array('created_at' => array('>=' => time() - $this->config['draft_lookup_days'] * 86400)),
                array('created_at' => -1),
                1,
                $this->config['draft_lookup_limit']
            );
            foreach ($drafts as $row) {
                $dataFmt = $row['data_fmt'];
                $tmpFiles = $dataFmt['tmp_files'] ?? array();
                foreach ($tmpFiles as $meta) {
                    $path = $meta['path'] ?? '';
                    if ($path === '') continue;
                    if (strpos($path, 'upload/temp') === false) continue;
                    $protected[$this->normalizePathForLookup($path)] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('buildProtectedPathSet failed', array('error' => $e->getMessage()));
        }
        return $protected;
    }

    /**
     * 策略 A：构建 namespace → TTL 映射
     *
     * namespace 由 config/Upload.php 中的 namespace_whitelist 定义。
     * 插件通过 install.php 在安装时将自身 namespace 写入此配置，
     * uninstall.php 在卸载时移除。
     *
     * @return array<string, int>  ['well_article' => 86400, ...]
     */
    private function buildNamespaceTtlMap(): array
    {
        return $this->config['namespace_whitelist'] ?? array();
    }

    /**
     * 路径归一化（§15.5 详细分析了两种输入格式的一致性）
     *
     * TempContent 中存储的路径（APP_PATH 相对路径）与 scandir 产出的路径（绝对路径）
     * 必须归一化到相同格式才能做 O(1) isset 查找。
     *
     * 算法：
     *   1. 若以 APP_PATH 开头 → 去除 APP_PATH 前缀
     *   2. 统一 DIRECTORY_SEPARATOR 为 '/'
     *   3. 去除首尾 '/'
     *   4. 返回如 'storage/upload/temp/202606/x.jpg' 的归一化格式
     *
     * 验证（两种输入 → 同一输出）：
     *   输入 A: '/storage/upload/temp/202606/x.jpg'（TempContent 存储值）
     *     → 去 APP_PATH（无此前缀，不变）→ 去 '/' → 'storage/upload/temp/202606/x.jpg'
     *   输入 B: '/www/wwwroot/wellcms/storage/upload/temp/202606/x.jpg'（realpath）
     *     → 去 APP_PATH → '/storage/upload/temp/202606/x.jpg' → 去 '/' → 同上
     */
    private function normalizePathForLookup(string $path): string
    {
        // 1) 去 APP_PATH 前缀
        if (strpos($path, APP_PATH) === 0) {
            $path = substr($path, strlen(APP_PATH));
        }
        // 2) 统一分隔符
        $path = str_replace('\\', '/', $path);
        // 3) 去首尾 /
        return trim($path, '/');
    }

    /**
     * namespace 安全校验
     *
     * @return string 合法的 namespace；无效时返回 ''（降级到默认）
     */
    private function validateNamespace(string $namespace): string
    {
        if ($namespace === '' || strlen($namespace) > 32) return '';
        if (preg_match('/^[a-z0-9_]+$/', $namespace) !== 1) return '';
        $whitelist = $this->config['namespace_whitelist'] ?? array();
        if (!isset($whitelist[$namespace])) return '';
        return $namespace;
    }

    /**
     * 防抖判断
     */
    private function shouldClean(): bool
    {
        $lastGc = $this->cache->get('temp_cleanup_last_gc');
        if ($lastGc !== null && time() - (int)$lastGc < $this->config['gc_min_interval']) {
            return false;
        }
        return true;
    }

    /**
     * 处理 ul_* 分片目录
     */
    private function processChunkDir(string $fullPath, string $entry): array
    {
        $mtime = filemtime($fullPath);
        $age = time() - $mtime;
        if ($age > $this->config['chunk_max_age']) {
            $this->fs->cleanupDir($fullPath . '/');
            return array(
                'processed' => 1,
                'stats' => array('deleted_dirs' => 1, 'deleted_files' => 0, 'freed_bytes' => 0, 'protected_by_namespace' => 0, 'protected_by_ref' => 0, 'empty_dirs_removed' => 0, 'errors' => 0),
            );
        }
        return array('processed' => 0, 'stats' => array());
    }

    /**
     * 处理白名单 namespace 目录
     */
    private function processNsDir(string $fullPath, string $entry, int $nsTtl, int $batchLimit, array $protectedSet): array
    {
        $inner = scandir($fullPath);
        $processed = 0;
        $stat = array(
            'deleted_dirs' => 0,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'protected_by_namespace' => 0,
            'protected_by_ref' => 0,
            'empty_dirs_removed' => 0,
            'errors' => 0,
        );
        foreach ($inner as $sub) {
            if ($sub === '.' || $sub === '..') continue;
            if ($processed >= $batchLimit) break;
            $filePath = $fullPath . '/' . $sub;
            if (!is_file($filePath)) continue;
            $mtime = filemtime($filePath);
            $age = time() - $mtime;
            if ($age < $this->config['min_age']) continue;
            if ($age > $nsTtl) {
                $size = filesize($filePath);
                @unlink($filePath);
                $stat['deleted_files']++;
                $stat['freed_bytes'] += $size;
                $processed++;
            } else {
                $stat['protected_by_namespace']++;
            }
        }
        // 目录空 → 删目录
        if (is_dir($fullPath)) {
            $remaining = scandir($fullPath);
            if (count($remaining) === 2) {
                @rmdir($fullPath);
                $stat['empty_dirs_removed']++;
            }
        }
        return array('processed' => $processed, 'stats' => $stat);
    }

    /**
     * 处理白名单 namespace 目录（轻量版，不查 TempContent）
     */
    private function processNsDirLight(string $fullPath, string $entry, int $nsTtl, int $batchLimit): array
    {
        $inner = scandir($fullPath);
        $processed = 0;
        $stat = array(
            'deleted_dirs' => 0,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'protected_by_namespace' => 0,
            'protected_by_ref' => 0,
            'empty_dirs_removed' => 0,
            'errors' => 0,
        );
        foreach ($inner as $sub) {
            if ($sub === '.' || $sub === '..') continue;
            if ($processed >= $batchLimit) break;
            $filePath = $fullPath . '/' . $sub;
            if (!is_file($filePath)) continue;
            $mtime = filemtime($filePath);
            $age = time() - $mtime;
            if ($age < $this->config['min_age']) continue;
            if ($age > $nsTtl) {
                $size = filesize($filePath);
                @unlink($filePath);
                $stat['deleted_files']++;
                $stat['freed_bytes'] += $size;
                $processed++;
            } else {
                $stat['protected_by_namespace']++;
            }
        }
        // 目录空 → 删目录
        if (is_dir($fullPath)) {
            $remaining = scandir($fullPath);
            if (count($remaining) === 2) {
                @rmdir($fullPath);
                $stat['empty_dirs_removed']++;
            }
        }
        return array('processed' => $processed, 'stats' => $stat);
    }

    /**
     * 处理默认 namespace 目录（YYYYMM）— 含灰色区 + 策略 B
     */
    private function processDefaultDir(string $fullPath, string $entry, int $batchLimit, array $protectedSet): array
    {
        $inner = scandir($fullPath);
        $processed = 0;
        $stat = array(
            'deleted_dirs' => 0,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'protected_by_namespace' => 0,
            'protected_by_ref' => 0,
            'empty_dirs_removed' => 0,
            'errors' => 0,
        );
        foreach ($inner as $sub) {
            if ($sub === '.' || $sub === '..') continue;
            if ($processed >= $batchLimit) break;
            $filePath = $fullPath . '/' . $sub;
            if (!is_file($filePath)) continue;
            $mtime = filemtime($filePath);
            $age = time() - $mtime;
            if ($age < $this->config['min_age']) continue;
            // > whitelist_max_age → 无条件删除
            if ($age > $this->config['whitelist_max_age']) {
                $size = filesize($filePath);
                @unlink($filePath);
                $stat['deleted_files']++;
                $stat['freed_bytes'] += $size;
                $processed++;
                continue;
            }
            // > default_max_age 且 < whitelist_max_age → 灰色区，检查策略 B
            if ($age > $this->config['default_max_age']) {
                $lookupKey = $this->normalizePathForLookup($filePath);
                if (isset($protectedSet[$lookupKey])) {
                    $stat['protected_by_ref']++;
                    continue;
                }
                // 无引用 → 删除
                $size = filesize($filePath);
                @unlink($filePath);
                $stat['deleted_files']++;
                $stat['freed_bytes'] += $size;
                $processed++;
                continue;
            }
            // min_age ~ default_max_age → 保留
        }
        // 目录空 → 删目录
        if (is_dir($fullPath)) {
            $remaining = scandir($fullPath);
            if (count($remaining) === 2) {
                @rmdir($fullPath);
                $stat['empty_dirs_removed']++;
            }
        }
        return array('processed' => $processed, 'stats' => $stat);
    }

    // hook app_Services_Storage_TempCleanupService_end.php
}
