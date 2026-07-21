<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

class TempUploadCleanupJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Services\Storage\TempCleanupService */
    private $cleanupService;
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;
    /** @var \Framework\Scheduler\TaskManage */
    private $taskManage;

    public function __construct(
        \App\Services\Storage\TempCleanupService $cleanupService,
        \Framework\Logger\LoggerInterface $logger,
        \Framework\Scheduler\TaskManage $taskManage
    ) {
        $this->cleanupService = $cleanupService;
        $this->logger = $logger;
        $this->taskManage = $taskManage;
    }

    /**
     * @param string|null $_task_id 系统注入
     * @return bool true=成功，false=触发重试
     */
    public function handle(?string $_task_id = null): bool
    {
        // hook app_Jobs_TempUploadCleanupJob_handle_start.php

        if (defined('DEBUG') && \DEBUG > 0) {
            $this->logger->info('TempUploadCleanupJob started', array(
                'task_id' => $_task_id,
            ));
        }

        try {
            // clean() 内部从 $this->config 读取 scheduler_batch_size
            $result = $this->cleanupService->clean();

            $this->logger->info('TempUploadCleanupJob completed', array(
                'task_id'                => $_task_id,
                'deleted_dirs'           => $result['deleted_dirs'],
                'deleted_files'          => $result['deleted_files'],
                'freed_bytes'            => $result['freed_bytes'],
                'protected_by_namespace' => $result['protected_by_namespace'],
                'protected_by_ref'       => $result['protected_by_ref'],
                'empty_dirs_removed'     => $result['empty_dirs_removed'],
                'errors'                 => $result['errors'],
                'execution_ms'           => round($result['execution_ms'], 2),
            ));

            // hook app_Jobs_TempUploadCleanupJob_handle_end.php

            $this->scheduleNext();
            return $result['errors'] === 0;
        } catch (\Throwable $e) {
            // 异常不静默
            $this->logger->error('TempUploadCleanupJob failed', array(
                'error'   => $e->getMessage(),
                'task_id' => $_task_id,
            ));
            return false;
        }
    }

    /**
     * 投递下一次任务（自循环，对标 PartitionMaintainJob）
     */
    private function scheduleNext(): void
    {
        try {
            $now = time();
            $tomorrow = strtotime('tomorrow 04:00');
            if ($tomorrow !== false && $tomorrow > $now) {
                $scheduledAt = $tomorrow;
            } else {
                $scheduledAt = strtotime('+1 day 04:00');
            }

            $this->taskManage->createTask(array(
                'className'   => self::class,
                'methodName'  => 'handle',
                'args'        => array(),
                'priority'    => 10,
                'scheduledAt' => $scheduledAt,
                'dedupeKey'   => 'job:temp_upload:cleanup:daily:' . gmdate('Ymd', $scheduledAt),
                'timeout'     => 120,
                'maxRetries'  => 2,
                'retryDelay'  => 60,
            ));

            $this->logger->info('TempUploadCleanupJob scheduled next', array(
                'scheduled_at' => date('Y-m-d H:i:s', $scheduledAt),
            ));
        } catch (\Throwable $e) {
            // TaskManage 不可用时（无 Redis）不抛异常，仅记录
            $this->logger->warning('TempUploadCleanupJob scheduleNext failed', array(
                'error' => $e->getMessage(),
            ));
        }
    }
}