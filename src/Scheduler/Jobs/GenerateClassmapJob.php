<?php

declare(strict_types=1);

namespace Framework\Scheduler\Jobs;

/**
 * 自动生成类映射表任务 (Generate Classmap Job)
 * 
 * 职责：
 * 扫描 app/ src/ plugins/ 目录，生成 storage/tmp/classmap.php
 * 目的是加速 app/Core/Autoload.php 的加载性能，将动态磁盘扫描转为内存数组查找。
 */
class GenerateClassmapJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \Framework\Scheduler\TaskManage */
    private $taskManage;

    public function __construct(\Framework\Scheduler\TaskManage $taskManage)
    {
        $this->taskManage = $taskManage;
    }

    /**
     * 执行生成逻辑 (自愈监控模式)
     * 
     * @param string|null $_task_id 系统自动注入的任务ID
     * @return array
     */
    public function handle(?string $_task_id = null): array
    {
        $basePath = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        $targetFile = $basePath . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'classmap.php';

        // 1. [关键逻辑] 检查是否需要执行扫描
        // 如果文件存在且最后修改时间在 24 小时内，则跳过扫描，仅维持心跳
        $needsScan = !file_exists($targetFile) || (time() - filemtime($targetFile) > 86400);

        $result = [
            'status' => 'skipped',
            'msg' => 'Classmap is up to date',
            'path' => $targetFile
        ];

        if ($needsScan) {
            $result = $this->generate($basePath, $targetFile);
        }

        // 2. [自循环] 5 分钟后再次检查 (保证目录被清空后能快速发现)
        // 使用 dedupeKey 确保队列中永远只有一个监控任务
        $this->taskManage->createTask([
            'className'  => self::class,
            'methodName' => 'handle',
            'args'       => [],
            'priority'   => 9,
            'scheduledAt' => time() + 3600, // 1 小时后
            'dedupeKey'  => 'system:classmap_monitor'
        ]);

        return $result;
    }

    /**
     * 实际的扫描与生成逻辑
     */
    private function generate(string $basePath, string $targetFile): array
    {
        $classmap = [];
        $scanConfig = [
            ['dir' => $basePath . 'app', 'prefix' => 'App\\', 'exclude' => ['/Tests/']],
            ['dir' => $basePath . 'src', 'prefix' => 'Framework\\', 'exclude' => ['/Tests/']],
            ['dir' => $basePath . 'plugins', 'prefix' => 'Plugins\\', 'depth' => 3]
        ];

        foreach ($scanConfig as $config) {
            if (!is_dir($config['dir'])) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($config['dir'], \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            if (isset($config['depth'])) $iterator->setMaxDepth($config['depth']);

            foreach ($iterator as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') continue;
                $fullPath = $file->getRealPath();
                if (!empty($config['exclude'])) {
                    foreach ($config['exclude'] as $pattern) {
                        if (strpos($fullPath, $pattern) !== false) continue 2;
                    }
                }
                $relativePath = ltrim(str_replace($config['dir'], '', $fullPath), '/');
                $className = $config['prefix'] . strtr(substr($relativePath, 0, -4), '/', '\\');
                $classmap[$className] = $fullPath;
            }
        }

        $tmpDir = dirname($targetFile);
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $content = "<?php\n/**\n * WellCMS Auto-generated Classmap\n * Generated at: " . date('Y-m-d H:i:s') . "\n */\nreturn " . var_export($classmap, true) . ";\n";
        file_put_contents($targetFile, $content);

        return [
            'status' => 'success',
            'count' => count($classmap),
            'path' => $targetFile
        ];
    }
}
