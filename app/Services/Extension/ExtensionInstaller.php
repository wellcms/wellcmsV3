<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Framework\Utils\DirectoryHelper;

/**
 * ExtensionInstaller - 扩展物理安装器 (工业级实现)
 * 核心特性：流式下载、哈希校验、影子解压、原子覆盖、自动热重载
 */
class ExtensionInstaller
{
    /** @var \App\Services\Market\MarketClient */
    private $market;
    /** @var \App\Services\Upgrade\Downloader */
    private $downloader;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    private $language;
    /** @var array */
    private $appConfig;
    /** @var string */
    private $pluginPath;
    /** @var string */
    private $themePath;
    /** @var string */
    private $tmpPath;

    public function __construct(
        \App\Services\Market\MarketClient $market,
        \App\Services\Upgrade\Downloader $downloader,
        ?\App\Interfaces\LanguageLoaderInterface $language,
        array $appConfig,
        array $pluginConfig,
        array $viewConfig
    ) {
        $this->market = $market;
        $this->downloader = $downloader;
        $this->language = $language;
        $this->appConfig = $appConfig;
        $this->pluginPath = rtrim($pluginConfig['plugins_path'], '/\\') . DIRECTORY_SEPARATOR;
        $this->themePath = rtrim($viewConfig['themes_path'], '/\\') . DIRECTORY_SEPARATOR;
        $this->tmpPath = $appConfig['tmp_path'];
    }

    /**
     * 执行安装/升级任务
     */
    public function execute(string $dir, string $type, int $storeId, string $expectedHash = ''): array
    {
        $lockAcquired = false;
        try {
            // 1. 目录互斥锁 (防止并发写冲突)
            \Framework\Utils\FileHelper::lock($dir);
            $lockAcquired = true;

            // 2. 获取加密下载直链
            $downloadInfo = $this->getDownloadUrl($storeId);
            if ($downloadInfo['status'] !== 'success') return $downloadInfo;

            $url = $downloadInfo['url'];
            $hash = $expectedHash ?: ($downloadInfo['hash'] ?? '');

            // 3. 流式下载并同步校验 (SHA256)
            $zipFile = $this->tmpPath . $dir . '_' . random_int(100000, 999999) . '.zip';
            $this->ensureTmp();

            $this->downloader->download($url, $zipFile, $hash);

            // 4. "影子解压"策略：先行解压到临时路径
            $shadowPath = $this->tmpPath . 'shadow_' . $dir . '/';
            DirectoryHelper::rmdirRecursive($shadowPath);
            if (!is_dir($shadowPath)) {
                mkdir($shadowPath, 0755, true);
            }

            $zip = new \Framework\Utils\ZipUtility();
            $zip->unzip($zipFile, $shadowPath);
            @unlink($zipFile);

            // 5. 校验提取结果 (Integrity Check)
            $extractedDir = $shadowPath . $dir . '/';
            // 如果只有一层子目录且不是 $dir，尝试自动探测
            if (!is_dir($extractedDir)) {
                $dirs = glob($shadowPath . '*', GLOB_ONLYDIR);
                if (count($dirs) === 1) $extractedDir = $dirs[0] . '/';
            }

            $configFile = $extractedDir . 'config.json';
            if (!file_exists($configFile)) {
                DirectoryHelper::rmdirRecursive($shadowPath);
                return ['status' => 'error', 'message' => $this->language->get('plugin_format_error') . ' (config.json empty)'];
            }

            // 6. 原子性覆盖 (Atomic Swap/Overwrite)
            $targetPath = ($type === 'theme' ? $this->themePath : $this->pluginPath) . $dir;

            // 如果目标已存在，先行备份（简单重命名）
            if (is_dir($targetPath)) {
                $backupPath = $this->tmpPath . 'backup_' . $dir . '_' . date('His');
                rename($targetPath, $backupPath);
            }

            // 执行移动
            if (!rename(rtrim($extractedDir, '/'), $targetPath)) {
                // FIX: 原子覆盖失败时恢复备份，防止原扩展丢失
                if (isset($backupPath) && is_dir($backupPath)) {
                    @rename($backupPath, $targetPath);
                }
                return ['status' => 'error', 'message' => 'FileSystem I/O Error: failed to rename shadow directory, backup restored'];
            }

            // 移动成功，清理备份（可选：保留一定时间用于回滚）
            if (isset($backupPath) && is_dir($backupPath)) {
                DirectoryHelper::rmdirRecursive($backupPath);
            }

            // 7. 环境刷新
            DirectoryHelper::rmdirRecursive($shadowPath);
            \Framework\Utils\Runtime::reload(); // 触发 Swoole 热重载

            return ['status' => 'success', 'dir' => $dir];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            if ($lockAcquired) \Framework\Utils\FileHelper::unlock($dir);
            $this->cleanTmp($dir);
        }
    }

    /**
     * 向商店请求下载地址
     */
    private function getDownloadUrl(int $storeId): array
    {
        $params = $this->market->getCommonParams();
        $params['storeid'] = $storeId;

        // 向官方请求下载权证
        $res = $this->market->request('download.html', $params);
        $result = \Framework\Utils\SecurityHelper::jsonDecode($res);

        if (isset($result['status']) && 'success' === $result['status'] && !empty($result['data']['url'])) {
            return ['status' => 'success', 'url' => $result['data']['url'], 'hash' => $result['data']['hash'] ?? ''];
        }

        $msg = $result['data']['message'] ?? $result['message'] ?? $this->language->get('plugin_download_failed');
        return ['status' => 'error', 'message' => $msg];
    }

    private function ensureTmp(): void
    {
        if (!is_dir($this->tmpPath)) mkdir($this->tmpPath, 0755, true);
    }

    private function cleanTmp(string $dir): void
    {
        // 清理当前任务产生的碎片
        $shadow = $this->tmpPath . 'shadow_' . $dir;
        if (is_dir($shadow)) DirectoryHelper::rmdirRecursive($shadow, true);

        // 清理残留压缩包
        $zips = glob($this->tmpPath . $dir . '_*.zip'); 
        if (!empty($zips)) {
            foreach ($zips as $zip) @unlink($zip);
        }
        
        // 清理残留备份目录
        $backups = glob($this->tmpPath . 'backup_' . $dir . '_*');
        if (!empty($backups)) {
            foreach ($backups as $backup) {
                if (is_dir($backup)) DirectoryHelper::rmdirRecursive($backup, true);
            } 
        }    
    }


}