<?php

declare(strict_types=1);

namespace Framework\Scheduler;

class Logger
{
    /**
     * Summary of logPath
     * @var string
     */
    private $logPath;
    /**
     * Summary of logFile
     * @var string
     */
    private $logFile;
    /**
     * Summary of buffer
     * @var array
     */
    private $buffer = [];
    /**
     * Summary of bufferSize
     * @var int
     */
    private $bufferSize = 100; // 缓冲100条后写入
    /**
     * Summary of lastFlush
     * @var int
     */
    private $lastFlush = 0;
    /** @var int */
    private $flushInterval = 5; // 5秒强制刷新

    public function __construct(?string $basePath = null)
    {
        // __DIR__ 在 Compile 缓存后指向 storage/tmp/classes/src/Scheduler/，
        // ../../ 的跳跃路径偏移一层导致路径错误。APP_PATH 是入口定义的安全锚点。
        $root = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $this->logPath = $basePath ?: $root . 'storage/logs/' . date('Ym');
        $this->logFile = $this->logPath . '/scheduler.log';

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }

        // 注册关闭函数，确保缓冲数据被写入
        register_shutdown_function([$this, 'flush']);
    }

    public function log(string $message, string $level = 'INFO'): bool
    {
        // 生产环境（DEBUG=0）过滤 INFO 及以下，仅保留 WARNING/ERROR
        if (defined('DEBUG') && (int)DEBUG === 0 && in_array($level, ['INFO', 'DEBUG'], true)) {
            return true;
        }

        $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";

        $this->buffer[] = $line;

        // 缓冲达到阈值或超时，立即写入
        if (
            count($this->buffer) >= $this->bufferSize ||
            (time() - $this->lastFlush) >= $this->flushInterval
        ) {
            return $this->flush();
        }

        return true;
    }

    public function flush(): bool
    {
        if (empty($this->buffer)) {
            $this->lastFlush = time();
            return true;
        }

        $content = implode('', $this->buffer);
        $this->buffer = [];

        // 使用原子文件写入
        $result = file_put_contents(
            $this->logFile,
            $content,
            FILE_APPEND | LOCK_EX
        );

        $this->lastFlush = time();
        return $result !== false;
    }
}