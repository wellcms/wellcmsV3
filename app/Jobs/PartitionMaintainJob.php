<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Jobs;

use Framework\Database\Partition\PartitionManager;
use Framework\Logger\LoggerInterface;
use Framework\Scheduler\TaskManage;

/**
 * 分区维护定时任务。
 *
 * 调度机制（单任务自循环）：
 *   1. 首次由 PartitionController::postMaintain() 或 bin/migrate-partitions-prod 启动
 *   2. 执行完成后自动投递下一次任务（+24 小时），保持链条不断
 *   3. 每日 dedupeKey 确保同一自然日最多只有一个任务实例
 *   4. 链条断裂后，管理员在后台点"执行维护"即可重建
 *
 * 防重入：
 *   - dedupeKey 基于日期：'job:partition:maintain:daily:20260605'
 *   - maintain() 内部有 Redis 分布式锁
 *   - 多 Worker 下只有一个执行实例
 *
 * PHP 7.2 兼容。
 */
class PartitionMaintainJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var PartitionManager */
    private $partitionManager;

    /** @var LoggerInterface */
    private $logger;

    /** @var TaskManage */
    private $taskManage;

    /**
     * @param PartitionManager $partitionManager
     * @param LoggerInterface  $logger
     * @param TaskManage       $taskManage
     */
    public function __construct(PartitionManager $partitionManager, LoggerInterface $logger, TaskManage $taskManage)
    {
        $this->partitionManager = $partitionManager;
        $this->logger = $logger;
        $this->taskManage = $taskManage;
    }

    /**
     * 执行全量分区维护，成功后投递下一次任务。
     *
     * @param string $_task_id 系统注入
     * @return bool true 表示成功，false 触发重试
     */
    public function handle(?string $_task_id = null): bool
    {
        // hook app_Jobs_PartitionMaintainJob_handle_start.php
        if (defined('DEBUG') && \DEBUG > 0) {
            $this->logger->info('PartitionMaintainJob started', array(
                'task_id' => $_task_id,
            ));
        }

        try {
            $result = $this->partitionManager->maintain();
            $success = $result->errors === 0;

            $this->logger->info('PartitionMaintainJob completed', array(
                'success'            => $success,
                'tables_scanned'     => $result->tablesScanned,
                'partitions_created' => $result->partitionsCreated,
                'partitions_dropped' => $result->partitionsDropped,
                'errors'             => $result->errors,
                'trigger'            => $result->trigger,
                'execution_ms'       => round($result->executionMs, 2),
            ));

            // 投递下一次任务（每日单任务自循环）
            if ($success) {
                $this->scheduleNext();
            }

            // hook app_Jobs_PartitionMaintainJob_handle_end.php
            return $success;
        } catch (\Throwable $e) {
            // 铁律 #25：异常不静默
            $this->logger->error('PartitionMaintainJob failed', array(
                'error'   => $e->getMessage(),
                'task_id' => $_task_id,
            ));
            return false;
        }
    }

    /**
     * 投递下一次分区维护任务。
     *
     * 使用基于日期的 dedupeKey（如 job:partition:maintain:daily:20260605），
     * 确保同一自然日最多只有一个等待中的任务。
     * 次日 03:00 执行，避让业务高峰期。
     *
     * @return void
     */
    private function scheduleNext()
    {
        try {
            $now = time();
            $tomorrow = strtotime('tomorrow 03:00');
            // 如果当前已过凌晨 3 点，推到次日
            $scheduledAt = $tomorrow !== false && $tomorrow > $now ? $tomorrow : strtotime('+1 day 03:00');

            // dedupeKey 按日聚合：同一天多次调用只保留第一个
            $dedupeKey = 'job:partition:maintain:daily:' . gmdate('Ymd');

            $this->taskManage->createTask(array(
                'className'   => self::class,
                'methodName'  => 'handle',
                'args'        => array(),
                'priority'    => 5,
                'scheduledAt' => $scheduledAt,
                'dedupeKey'   => $dedupeKey,
                'timeout'     => 300,
                'maxRetries'  => 2,
                'retryDelay'  => 60,
            ));

            $this->logger->info('PartitionMaintainJob scheduled next', array(
                'scheduled_at' => date('Y-m-d H:i:s', $scheduledAt),
                'dedupe_key'   => $dedupeKey,
            ));
        } catch (\Throwable $e) {
            // TaskManage 不可用时（无 Redis）不抛异常，仅记录
            $this->logger->warning('PartitionMaintainJob scheduleNext failed', array(
                'error' => $e->getMessage(),
            ));
        }
    }
}