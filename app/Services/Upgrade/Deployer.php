<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * Deployer
 * 
 * 处理升级包的解压缩与文件覆盖
 */
class Deployer
{
    /** @var array */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * 解压并覆盖文件
     * 
     * @param string $zipPath 压缩包绝对路径
     * @param string $destPath 覆盖目标根目录（通常是 APP_PATH）
     * @throws \RuntimeException
     */
    public function extractAndOverwrite(string $zipPath, string $destPath): void
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('Migration zip file not found: ' . $zipPath);
        }

        $tempExtractPath = rtrim($destPath, '/') . '/' . ltrim($this->appConfig['tmp_path'], './') . 'upgrade_extract_' . time() . '/';

        if (!is_dir($tempExtractPath)) {
            mkdir($tempExtractPath, 0755, true);
        }

        try {
            $zip = new \Framework\Utils\ZipUtility();

            // 1. 解压到临时目录
            $zip->unzip($zipPath, $tempExtractPath);

            // 2. 探测根目录：逐层剥离嵌套目录，直到找到真实内容根
            //    兼容 wellcms/ 或 wellcms/wellcms/ 等多层嵌套结构
            //    排除 macOS 系统产物 __MACOSX 及隐藏目录
            $sourcePath = $tempExtractPath;
            while (true) {
                $entries = array_diff(scandir($sourcePath), ['.', '..']);
                $dirs = [];
                $hasFile = false;
                foreach ($entries as $entry) {
                    $fullPath = rtrim($sourcePath, '/') . '/' . $entry;
                    if (is_dir($fullPath)) {
                        if ($entry !== '__MACOSX' && $entry[0] !== '.') {
                            $dirs[] = $fullPath;
                        }
                    } else {
                        $hasFile = true;
                    }
                }
                // 顶层有文件 或 有效目录不止一个 → 当前路径就是根，停止剥离
                if ($hasFile || count($dirs) !== 1) {
                    break;
                }
                // 唯一有效目录 → 向上提一级
                $sourcePath = rtrim($dirs[0], '/') . '/';
            }

            // 3. 递归复制解压后的文件到目标目录（覆盖）
            $this->copyRecursive($sourcePath, $destPath);
        } finally {
            // 清理临时解压目录
            \Framework\Utils\DirectoryHelper::rmdirRecursive($tempExtractPath);
        }
    }

    /**
     * 递归覆盖复制
     * 
     * @param string $src
     * @param string $dst
     */
    private function copyRecursive(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!$dir) return;

        @mkdir($dst, 0755, true);

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $srcFile = $src . $file;
            $dstFile = $dst . $file;

            // 关键安全增强：严禁覆盖敏感配置文件
            $excludedFiles = [
                'config/Database.php',
                'config/App.php', // App.php 由 UpgradeService 逻辑更新版本号，不直接物理覆盖
            ];

            foreach ($excludedFiles as $excluded) {
                if (strpos(strtr($dstFile, '\\', '/'), $excluded) !== false) {
                    continue 2;
                }
            }

            if (is_dir($srcFile)) {
                $this->copyRecursive($srcFile . '/', $dstFile . '/');
            } else {
                copy($srcFile, $dstFile);
            }
        }
        closedir($dir);
    }
}
