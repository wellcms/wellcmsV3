<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Controllers\Admin;

use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};
use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;
use Framework\Database\Partition\PartitionManager;

/**
 * 分区管理后台控制器。
 *
 *   GET  /admin/PartitionStatus         → index()
 *   POST /admin/PostPartitionMaintain   → postMaintain()
 *
 * 权限（路由 meta 声明）：
 *   requiresUserPerm => ['role' => ['administer', 'setting']]
 */
class PartitionController extends BaseController
{
    use AdminTrait;

    /**
     * 分区状态概览页。显示每张表的分区配置状态。
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_PartitionController_index_start.php
        $user = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($user['salt'] ?? '');
        $menu = $this->getAdminMenu();

        $statusList = array();
        $partitionEnabled = false;
        $pm = $this->getPartitionManager();
        if ($pm !== null) {
            // 状态页先按真实结构和完整声明恢复登记，再读取状态；不被残缺旧缓存阻断。
            $pm->adoptExistingTables();
            $statusList = $pm->getStatus();
            $partitionEnabled = true;
        }

        $routeMeta = $request->getAttributes()['_route_meta'] ?? array('layout' => 'partition_status');

        $pageLinkString = 'admin/PartitionStatus';
        $data = array(
            'header' => array(
                'title' => $this->language->get('partition_management'),
                'keywords' => $this->language->get('partition_management'),
                'description' => $this->language->get('partition_management'),
            ),
            'menu' => $menu,
            'menu_fixed' => array('parent' => 'other', 'child' => 'partition'),
            'csrf_token' => $csrfToken,
            'status_list' => $statusList,
            'partition_enabled' => $partitionEnabled,
            'total_tables' => count($statusList),
            'extra' => array(),
            'page_link' => $this->urlGenerator->url($pageLinkString),
            'page_link_string' => $pageLinkString,
            'action' => $this->urlGenerator->url('admin/PostPartitionMaintain'),
            'language' => array(
                'partition_management' => $this->language->get('partition_management'),
                'registered_tables' => $this->language->get('registered_tables'),
                'execute_maintenance' => $this->language->get('execute_maintenance'),
                'dry_run_preview' => $this->language->get('dry_run_preview'),
                'partition_manager_unavailable' => $this->language->get('partition_manager_unavailable'),
                'partition_table' => $this->language->get('partition_table'),
                'partition_column' => $this->language->get('partition_column'),
                'partition_period' => $this->language->get('partition_period'),
                'partition_sub' => $this->language->get('partition_sub'),
                'partition_advance' => $this->language->get('partition_advance'),
                'partition_retention' => $this->language->get('partition_retention'),
            ),
        );

        // hook app_Controllers_Admin_PartitionController_index_end.php
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 手动执行分区维护。支持 ?dry_run=1 参数预览不执行。
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postMaintain(ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_PartitionController_postMaintain_start.php
        $params = $request->getQueryParams();
        $dryRun = isset($params['dry_run']) && $params['dry_run'] === '1';

        $pm = $this->getPartitionManager();
        if ($pm === null) {
            return $this->responseFormatter->jsonResponseFormat(array(
                'status' => 'error',
                'code' => 500,
                'message' => 'PartitionManager not available',
            ));
        }

        try {

            // maintain() 在 DDL 前统一核验和恢复，不先读取可能残缺的旧登记。
            $result = $pm->maintain($dryRun);

            // 非预览模式下成功后，启动每日调度任务（如存在 Scheduler）
            // 使用与 Job 自循环相同的日期 dedupeKey，确保全局唯一
            if (!$dryRun && $result->errors === 0) {
                try {
                    $taskManage = $this->container->get(\Framework\Scheduler\TaskManage::class);
                    $now = time();
                    $scheduledAt = strtotime('tomorrow 03:00');
                    if ($scheduledAt === false || $scheduledAt <= $now) {
                        $scheduledAt = strtotime('+1 day 03:00');
                    }
                    $dedupeKey = 'job:partition:maintain:daily:' . gmdate('Ymd');
                    $dispatch = $taskManage->createTask(array(
                        'className'   => \App\Jobs\PartitionMaintainJob::class,
                        'methodName'  => 'handle',
                        'args'        => array(),
                        'scheduledAt' => $scheduledAt,
                        'dedupeKey'   => $dedupeKey,
                        'timeout'     => 300,
                        'maxRetries'  => 2,
                        'retryDelay'  => 60,
                    ));
                    if (!\Framework\Scheduler\TaskDispatchResult::isAccepted($dispatch)) {
                        try {
                            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
                            $logger->warning('PartitionController maintenance schedule pending', array(
                                'outcome' => \Framework\Scheduler\TaskDispatchResult::outcome($dispatch),
                                'code' => \Framework\Scheduler\TaskDispatchResult::code($dispatch)
                                    ?? 'TASK_DISPATCH_UNKNOWN',
                                'task_id' => \Framework\Scheduler\TaskDispatchResult::taskId($dispatch),
                            ));
                        } catch (\Throwable $logError) {
                            // 最佳努力日志不得反向改变已完成的分区维护结果。
                        }
                    }
                } catch (\Throwable $e) {
                    // Scheduler/TaskManage 不可用时（无 Redis）不阻塞主流程
                    try {
                        $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
                        $logger->warning('PartitionController maintenance schedule unavailable', array(
                            'exception' => get_class($e),
                        ));
                    } catch (\Throwable $logError) {
                        // 最佳努力日志不得反向改变已完成的分区维护结果。
                    }
                }
            }

            return $this->responseFormatter->jsonResponseFormat(array(
                'status' => $result->errors === 0 ? 'success' : 'error',
                'code' => $result->errors === 0 ? 0 : 1,
                'message' => $dryRun
                    ? sprintf('Preview: %d to create, %d to drop', $result->partitionsCreated, $result->partitionsDropped)
                    : sprintf('Done: %d created, %d dropped, %d errors', $result->partitionsCreated, $result->partitionsDropped, $result->errors),
                'data' => array(
                    'tables_scanned'     => $result->tablesScanned,
                    'partitions_created' => $result->partitionsCreated,
                    'partitions_dropped' => $result->partitionsDropped,
                    'errors'             => $result->errors,
                    'execution_ms'       => round($result->executionMs, 2),
                ),
            ));
        } catch (\Throwable $e) {
            // 铁律 #25：异常不静默
            if ($this->container) {
                $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
                $logger->error('PartitionController.postMaintain failed', array(
                    'exception' => $e,
                ));
            }

            throw $e;
        }
        // hook app_Controllers_Admin_PartitionController_postMaintain_end.php
    }

    /**
     * 从容器获取 PartitionManager 实例。
     * 容器不可用时返回 null，避免阻塞页面渲染。
     *
     * @return PartitionManager|null
     */
    private function getPartitionManager()
    {
        if ($this->container === null) {
            return null;
        }
        try {
            return $this->container->get(PartitionManager::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}