<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Scheduler\TaskManage;
use Framework\Utils\SecurityHelper;
use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;

class TaskController extends BaseController
{
    use AdminTrait;

    /** @var TaskManage */
    private $taskManage;

    /** @var string|null 缓存 WELLCMS_SITE_ID 检查结果 */
    private $siteIsolationChecked;

    protected function taskManage()
    {
        if ($this->taskManage) return $this->taskManage;

        // 多个站点共用同一 Redis 时，必须通过环境变量区分 key 前缀
        if ($this->siteIsolationChecked === null) {
            $siteId = getenv('WELLCMS_SITE_ID');
            $this->siteIsolationChecked = ($siteId !== false && $siteId !== '');
        }
        if (!$this->siteIsolationChecked) {
            return null;
        }

        $cfg = $this->container->get('cacheConfig');
        if (isset($cfg['stores']['redis'])) {
            $this->taskManage = $this->container->get(TaskManage::class);
            return $this->taskManage;
        }

        return null;
    }

    /**
     * 任务仪表盘
     */
    public function dashboard(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $extra = [];
        // 获取当前用户信息
        $currentUser = $request->getAttribute('user', []);

        // hook app_Controllers_Admin_TaskController_dashboard_start.php


        if ($this->taskManage()) {
            // 获取统计数据
            $stats = [
                'pending' => $this->taskManage->getPendingCount(),
                'running' => $this->taskManage->getRunningCount(),
                'failed' => $this->taskManage->getFailedCount(),
                'success' => $this->taskManage->getSuccessCount(),
            ];
            // 获取最近10个任务
            $recentTasks = $this->taskManage->getRecentTasks(10);
            foreach ($recentTasks as &$task) {
                // 仪表盘只需要显示信息，通常不需要操作或只需要简单的操作。
                // 保留 formatTask 用于格式化。
                $task = $this->formatTask($task, 'M j, Y H:i:s');
                $task['action_buttons'] = $this->getTaskActions($task, $currentUser['salt'] ?? '', 'list');
            }
            unset($task);

            // 获取系统健康状态
            $systemHealth = $this->taskManage->getSystemHealth();
            if ($systemHealth) {
                // 计算进度条百分比，移出视图
                $systemHealth['queue_percent'] = min(100, max(0, (int)($systemHealth['queue_size'] ?? 0)));
                $systemHealth['memory_percent'] = (int)str_replace('%', '', $systemHealth['memory_usage'] ?? '0');
            }
        } else {
            $stats = null;
            $recentTasks = null;
            $systemHealth = null;
        }

        // 获取菜单
        $menu = $this->getAdminMenu();

        // hook app_Controllers_Admin_TaskController_dashboard_before.php

        // 准备视图数据
        $page_link_string = 'admin/task/dashboard';
        $data = [
            'header' => [
                'title' => $this->language->get('task_dashboard'),
                'keywords' => $this->language->get('admin_task'),
                'description' => $this->language->get('task_manage_dashboard')
            ],
            'extra' => $extra,
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'task', 'child' => 'dashboard'],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'stats' => $stats,
            'recent_tasks' => $recentTasks,
            'system_health' => $systemHealth,
            'user' => $currentUser,
            'language' => [
                'dashboard_title' => $this->language->get('task_dashboard'),
                'task_manage_dashboard' => $this->language->get('task_manage_dashboard'),
                'system_health' => $this->language->get('system_health'),
                'redis_connected' => $this->language->get('redis_connected'),
                'queue_size' => $this->language->get('queue_size'),
                'memory_usage' => $this->language->get('memory_usage'),
                'last_execution' => $this->language->get('last_execution'),
                'recent_tasks' => $this->language->get('recent_tasks'),
                'task_id' => $this->language->get('task_id'),
                'class_name' => $this->language->get('class_name'),
                'status' => $this->language->get('status'),
                'priority' => $this->language->get('priority'),
                'retry_count' => $this->language->get('retry_count'),
                'created_at' => $this->language->get('created_at'),
                'details' => $this->language->get('details'),
                'env_setup_title' => $this->language->get('env_setup_title'),
                'env_setup_subtitle' => $this->language->get('env_setup_subtitle'),
                'redis_setup_title' => $this->language->get('redis_setup_title'),
                'redis_setup_desc' => $this->language->get('redis_setup_desc', ['file' => '/config/Cache.php']),
                'redis_ref_label' => $this->language->get('redis_ref_label'),
                'scheduler_setup_title' => $this->language->get('scheduler_setup_title'),
                'scheduler_setup_desc' => $this->language->get('scheduler_setup_desc', ['file' => APP_PATH . 'bin/scheduler']),
                'badge_waitlist' => 'Waitlist',
                'badge_running' => 'Running',
                'badge_healthy' => 'Healthy',
                'badge_critical' => 'Critical',
                'view_guide' => $this->language->get('view_guide'),
                'system_status' => $this->language->get('system_health')
            ],
            'operation_links' => [
                'list' => ['url' => $this->urlGenerator->url('admin/task/list'), 'label' => $this->language->get('view_all')],
                'failed' => ['url' => $this->urlGenerator->url('admin/task/failed'), 'label' => $this->language->get('failed_tasks')],
                'dashboard' => ['url' => $this->urlGenerator->url('admin/task/dashboard'), 'label' => $this->language->get('refresh')]
            ]
        ];

        // hook app_Controllers_Admin_TaskController_dashboard_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'task_dashboard'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 任务列表
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');
        $extra = [];

        // hook app_Controllers_Admin_TaskController_list_start.php

        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        // 搜索参数
        $status = RequestUtils::param('status', '');
        $keywords = RequestUtils::param('keywords', '');
        $page = RequestUtils::param('page', 1);
        $pageSize = 20;

        $keywords = SecurityHelper::urldecode($keywords);
        $keywords = trim($keywords);
        if ($keywords) {
            $extra += ['keywords' => SecurityHelper::urlencode($keywords)];
        }
        if ($status) {
            $extra += ['status' => $status];
        }
        if ($page > 1) {
            $extra += ['page' => $page];
        }

        if ($this->taskManage()) {
            $taskStatus = 'success';
            // 调用新增的 listTasks 方法，支持过滤和分页
            $result = $this->taskManage->listTasks($status, $keywords, $page, $pageSize);
            $itemList = $result['items'] ?? [];
            $totalCount = $result['total'] ?? 0;

            // 循环处理每个任务的操作按钮逻辑，形成业务闭环
            foreach ($itemList as &$item) {
                $item = $this->formatTask($item, 'M j, Y H:i:s');
                $item['action_buttons'] = $this->getTaskActions($item, $currentUser['salt'] ?? '', 'list');
            }
            unset($item);

            // 构建分页数据
            $pagination = [
                'total' => $totalCount,
                'page' => $page,
                'pageSize' => $pageSize,
                'previous_link' => $page > 1 ? $this->urlGenerator->url('admin/task/list', array_merge($extra, ['page' => $page - 1])) : '',
                'next_link' => $totalCount > ($page * $pageSize) ? $this->urlGenerator->url('admin/task/list', array_merge($extra, ['page' => $page + 1])) : '',
            ];
        } else {
            $taskStatus = 'failed';
            $itemList = [];
            $pagination = [
                'previous_link' => '',
                'next_link' => ''
            ];
        }

        // 构建标签页菜单
        $tabMenu = [
            [
                'label' => $this->language->get('all'),
                'url' => $this->urlGenerator->url('admin/task/list'),
                'active' => empty($status)
            ],
            [
                'label' => $this->language->get('task_status_pending'),
                'url' => $this->urlGenerator->url('admin/task/list', array_merge($extra, ['status' => 'pending'])),
                'active' => $status === 'pending'
            ],
            [
                'label' => $this->language->get('task_status_retrying'),
                'url' => $this->urlGenerator->url('admin/task/list', array_merge($extra, ['status' => 'retrying'])),
                'active' => $status === 'retrying'
            ],
            [
                'label' => $this->language->get('task_status_running'),
                'url' => $this->urlGenerator->url('admin/task/list', array_merge($extra, ['status' => 'running'])),
                'active' => $status === 'running'
            ],
            [
                'label' => $this->language->get('task_status_success'),
                'url' => $this->urlGenerator->url('admin/task/list', array_merge($extra, ['status' => 'success'])),
                'active' => $status === 'success'
            ],
            [
                'label' => $this->language->get('task_status_failed'),
                'url' => $this->urlGenerator->url('admin/task/failed'),
                'active' => $status === 'failed'
            ]
        ];

        // hook app_Controllers_Admin_TaskController_list_center.php

        $page_link_string = 'admin/task/list';
        $data = [
            'header' => [
                'title' => $this->language->get('admin_task_list'),
                'keywords' => $this->language->get('admin_task'),
                'description' => $this->language->get('task_manage_dashboard')
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'task', 'child' => 'list'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'search' => [
                'keywords' => $keywords,
                'status' => $status
            ],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'operation_links' => [
                'dashboard' => ['url' => $this->urlGenerator->url('admin/task/dashboard'), 'label' => $this->language->get('task_dashboard')],
                'list' => ['url' => $this->urlGenerator->url('admin/task/list'), 'label' => $this->language->get('task_list')],
                'failed' => ['url' => $this->urlGenerator->url('admin/task/failed'), 'label' => $this->language->get('failed_tasks')],
                'cancel' => ['url' => $this->urlGenerator->url('admin/task/cancel'), 'label' => $this->language->get('cancel')],
                'details' => ['url' => $this->urlGenerator->url('admin/task/details'), 'label' => $this->language->get('view_details')],
            ],
            'task_status' => $taskStatus,
            'config' => [
                'rewrite' => $this->appConfig['url_rewrite_on'],
                'path' => $this->appConfig['path'],
            ],
            'item_list' => $itemList,
            'pagination' => $pagination,
            'tab_menu' => $tabMenu,
            'language' => [
                'task_list' => $this->language->get('task_list_title'),
                'task_id' => $this->language->get('task_id'),
                'class_name' => $this->language->get('class_name'),
                'method_name' => $this->language->get('method_name'),
                'status' => $this->language->get('status'),
                'priority' => $this->language->get('priority'),
                'retry_count' => $this->language->get('retry_count'),
                'created_at' => $this->language->get('created_at'),
                'scheduled_at' => $this->language->get('scheduled_at'),
                'operation' => $this->language->get('operation'),
                'view' => $this->language->get('view'),
                'details' => $this->language->get('view_details'),
                'search' => $this->language->get('search'),
                'all' => $this->language->get('all'),
                'enter_keywords' => $this->language->get('enter_keywords'),
                'select_status' => $this->language->get('select_status'),
                'status_pending' => $this->language->get('status_pending'),
                'status_running' => $this->language->get('status_running'),
                'status_failed' => $this->language->get('status_failed'),
                'status_success' => $this->language->get('status_success'),
                'confirm_cancel' => $this->language->get('confirm_cancel'),
                'operating' => $this->language->get('operating'),
                'env_setup_title' => $this->language->get('env_setup_title'),
                'env_setup_subtitle' => $this->language->get('env_setup_subtitle'),
                'redis_setup_title' => $this->language->get('redis_setup_title'),
                'redis_setup_desc' => $this->language->get('redis_setup_desc', ['file' => '/config/Cache.php']),
                'redis_ref_label' => $this->language->get('redis_ref_label'),
                'scheduler_setup_title' => $this->language->get('scheduler_setup_title'),
                'scheduler_setup_desc' => $this->language->get('scheduler_setup_desc', ['file' => '/bin/scheduler'])
            ]
        ];

        // hook app_Controllers_Admin_TaskController_list_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'task_list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 失败任务列表
     */
    public function failed(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $extra = [];
        // hook app_Controllers_Admin_TaskController_failedList_start.php

        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');

        // 准备视图数据
        $menu = $this->getAdminMenu();

        // 获取失败任务列表
        if ($this->taskManage()) {
            $result = $this->taskManage->failedList();
            if (!empty($result['items'])) {
                foreach ($result['items'] as &$item) {
                    $item = $this->formatTask($item);
                    $item['action_buttons'] = $this->getTaskActions($item, $currentUser['salt'] ?? '', 'list');
                }
                unset($item);
            }
        } else {
            $result = ['status' => 'error', 'items' => []];
        }

        $page_link_string = 'admin/task/failed';
        $data = [
            'header' => [
                'title' => $this->language->get('admin_failed_task'),
                'keywords' => $this->language->get('admin_failed_task'),
                'description' => '查看失败任务列表'
            ],
            'extra' => $extra,
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'task', 'child' => 'list'],
            'csrf_token' => $csrfToken,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'operation_links' => [
                'dashboard' => ['url' => $this->urlGenerator->url('admin/task/dashboard'), 'label' => $this->language->get('task_dashboard')],
                'failed' => ['url' => $this->urlGenerator->url('admin/task/failed'), 'label' => $this->language->get('failed_tasks')],
                'retry' => ['url' => $this->urlGenerator->url('admin/task/retry'), 'label' => $this->language->get('retry')],
                'requeue' => ['url' => $this->urlGenerator->url('admin/task/requeue'), 'label' => $this->language->get('requeue')],
            ],
            'task_result' => $result,
            'language' => [
                'failed_tasks' => $this->language->get('failed_tasks'),
                'task_id' => $this->language->get('task_id'),
                'class_name' => $this->language->get('class_name'),
                'status' => $this->language->get('status'),
                'retry_count' => $this->language->get('retry_count'),
                'confirm_requeue' => $this->language->get('confirm_requeue'),
                'operating' => $this->language->get('operating'),
                'error_message' => $this->language->get('operation_failed'),
                'operation' => $this->language->get('operation')
            ]
        ];

        // hook app_Controllers_Admin_TaskController_failedList_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'task_failed'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 查看任务详情
     */
    public function details(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $extra = [];
        $taskId = RequestUtils::param('task_id', '');

        // hook app_Controllers_Admin_TaskController_details_start.php

        if (empty($taskId)) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'task_id')), 1);
        }

        // hook app_Controllers_Admin_TaskController_details_before.php

        if (!$this->taskManage()) {
            return $this->errorMessage($this->language->get('operation_failed'), 3);
        }

        $result = $this->taskManage->showTask($taskId);

        if ($result['status'] === 'not_found') {
            $data = [
                'code' => 404,
                'status' => 'error',
                'title' => $this->language->get('admin_failed_task'),
                'keywords' => $this->language->get('admin_failed_task'),
                'description' => $this->language->get('task_not_found', array('id' => $taskId)),
                'message' => $this->language->get('task_not_found', array('id' => $taskId)),
            ];
            return $this->render('404', $data);
        }

        // hook app_Controllers_Admin_TaskController_details_center.php

        if ($result['status'] === 'success') {
            $menu = $this->getAdminMenu();
            $task = $this->formatTask($result['task']);

            // hook app_Controllers_Admin_TaskController_details_middle.php
            $page_link_string = 'admin/task/details';

            $data = [
                'header' => [
                    'title' => $this->language->get('admin_task_details') . ' - ' . $task['id'],
                    'keywords' => $this->language->get('admin_task_details'),
                    'description' => $this->language->get('admin_task_details')
                ],
                'extra' => $extra,
                'menu' => $menu,
                'menu_fixed' => ['parent' => 'task', 'child' => 'list'],
                'csrf_token' => $this->getCsrfToken($request->getAttributes()['user']['salt'] ?? ''),
                'page_link' => $this->urlGenerator->url($page_link_string, $extra),
                'page_link_string' => $page_link_string,
                'operation_links' => [
                    'dashboard' => ['url' => $this->urlGenerator->url('admin/task/dashboard'), 'label' => $this->language->get('task_dashboard')],
                    'list' => ['url' => $this->urlGenerator->url('admin/task/list'), 'label' => $this->language->get('task_list')],
                    'failed' => ['url' => $this->urlGenerator->url('admin/task/failed'), 'label' => $this->language->get('failed_tasks')],
                    'back' => ['url' => $this->urlGenerator->url('admin/task/list'), 'label' => $this->language->get('back_to_task_list')],
                ],
                // 生成此特定任务的动态操作按钮
                'action_buttons' => $this->getTaskActions($task, $request->getAttributes()['user']['salt'] ?? '', 'details'),
                'task' => $task,
                'user' => $request->getAttribute('user', []),
                'language' => [
                    'task_details' => $this->language->get('admin_task_details'),
                    'task_id' => $this->language->get('task_id'),
                    'class_name' => $this->language->get('class_name'),
                    'method_name' => $this->language->get('method_name'),
                    'arguments' => $this->language->get('arguments'),
                    'status' => $this->language->get('status'),
                    'priority' => $this->language->get('priority'),
                    'retry_info' => $this->language->get('retry_info'),
                    'retry_count' => $this->language->get('current_retry_count'),
                    'max_retries' => $this->language->get('max_retries'),
                    'retry_delay' => $this->language->get('retry_delay'),
                    'timeout' => $this->language->get('timeout'),
                    'callback' => $this->language->get('callback'),
                    'callback_url' => $this->language->get('callback_url'),
                    'callback_method' => $this->language->get('callback_method'),
                    'timing' => $this->language->get('timing'),
                    'back_to_list' => $this->language->get('back_to_task_list'),
                    'created_at' => $this->language->get('created_at'),
                    'scheduled_at' => $this->language->get('scheduled_at'),
                    'updated_at' => $this->language->get('updated_at'),
                    'operating' => $this->language->get('operating'),
                ]
            ];

            // hook app_Controllers_Admin_TaskController_details_after.php

            $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'task_details'];
            return $this->render($routeMeta['layout'], $data, true);
        }

        // hook app_Controllers_Admin_TaskController_details_end.php

        return $this->errorMessage(
            $result['msg'] ?? $this->language->get('admin_task_details') . ' ' . $this->language->get('operation_failed'),
            3
        );
    }

    /**
     * 取消任务
     */
    public function cancel(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 获取POST请求数据
        $taskId = RequestUtils::post('task_id', '');

        if (empty($taskId)) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'task_id')), 1);
        }

        try {
            if (!$this->taskManage()) {
                return $this->errorMessage($this->language->get('operation_failed'), -1);
            }
            $result = $this->taskManage->cancelTask($taskId);

            if ($result['status'] === 'success') {
                return $this->successMessage($this->language->get('task_cancel_success'), 0);
            }

            return $this->errorMessage($result['msg'] ?? $this->language->get('task_cancel_failed'), -1);
        } catch (\Exception $e) {
            return $this->errorMessage($this->language->get('task_cancel_failed') . ': ' . $e->getMessage(), -1);
        }
    }

    /**
     * 重试失败任务
     */
    public function retry(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 获取POST请求数据
        $taskId = RequestUtils::post('task_id', '');

        if (empty($taskId)) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'task_id')), 1);
        }

        try {
            if (!$this->taskManage()) {
                return $this->errorMessage($this->language->get('operation_failed'), -1);
            }
            $result = $this->taskManage->retryTask($taskId);

            if ($result['status'] === 'success') {
                return $this->successMessage(
                    $this->language->get('task_retry_success') . ' - TaskID: ' . $taskId,
                    0
                );
            }

            return $this->errorMessage($result['msg'] ?? $this->language->get('task_retry_failed'), -1);
        } catch (\Exception $e) {
            return $this->errorMessage($this->language->get('task_retry_failed') . ': ' . $e->getMessage(), -1);
        }
    }

    /**
     * 重新入列任务（从失败队列）
     */
    public function requeue(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 获取POST请求数据
        $postData = $request->getParsedBody();

        $taskIds = $postData['task_ids'] ?? []; // 数组参数仍需从 parsedBody 获取，RequestUtils::post 默认处理字符串

        if (empty($taskIds) || !is_array($taskIds)) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'task_ids')), 1);
        }

        try {
            if (!$this->taskManage()) {
                return $this->errorMessage($this->language->get('operation_failed'), -1);
            }
            $result = $this->taskManage->requeueFailed($taskIds);

            if ($result['status'] === 'success') {
                return $this->successMessage(
                    $this->language->get('task_requeue_success', array('count' => $result['count'])),
                    0
                );
            }

            return $this->errorMessage($result['msg'] ?? $this->language->get('task_requeue_failed'), -1);
        } catch (\Exception $e) {
            return $this->errorMessage($this->language->get('task_requeue_failed') . ': ' . $e->getMessage(), -1);
        }
    }

    /** @var array 驼峰→蛇形字段映射表，减少 formatTask 中逐条 mapping */
    private const FIELD_MAP = [
        'className'    => 'class_name',
        'methodName'   => 'method_display',
        'retryCount'   => 'retry_count',
        'maxRetries'   => 'max_retries',
        'retryDelay'   => 'retry_delay_seconds',
        'callbackMethod' => 'callback_method_display',
        'callbackUrl'  => 'callback_url_display',
        'error'        => 'error_display',
    ];

    /** @var array 状态→CSS class 映射 */
    private const STATUS_CLASS_MAP = [
        'pending'  => 'bg-yellow-100/50 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400',
        'retrying' => 'bg-orange-100/50 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400',
        'running'  => 'bg-blue-100/50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
        'success'  => 'bg-green-100/50 text-green-600 dark:bg-green-900/30 dark:text-green-400',
        'failed'   => 'bg-red-100/50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
    ];

    /**
     * 格式化任务数据 (Data Presentation Logic)
     * Centralizes status styles, date formatting, and labels.
     */
    private function formatTask(array $task, string $dateFormat = 'Y-m-d H:i:s'): array
    {
        $status = $task['status'] ?? 'pending';

        // 1. Status Label & Class
        $task['status_label'] = $this->language->get('task_status_' . $status);
        $task['status_class'] = self::STATUS_CLASS_MAP[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-900/30 dark:text-gray-400';

        // 2. Date Formatting
        $task['formatted_created_at'] = !empty($task['createdAt']) ? date($dateFormat, (int)$task['createdAt']) : '-';
        $task['formatted_scheduled_at'] = !empty($task['scheduledAt']) ? date($dateFormat, (int)$task['scheduledAt']) : '-';
        $task['formatted_updated_at'] = !empty($task['updatedAt']) ? date($dateFormat, (int)$task['updatedAt']) : '-';

        // 3. 驼峰→蛇形批量映射
        foreach (self::FIELD_MAP as $camel => $snake) {
            $task[$snake] = $task[$camel] ?? ($camel === 'retryDelay' ? '0 Seconds' : '-');
        }
        $task['retry_progress'] = ($task['retryCount'] ?? '0') . ' / ' . ($task['maxRetries'] ?? '0');
        $task['retry_delay_seconds'] .= ' Seconds';
        $task['callback_method_display'] = strtoupper($task['callbackMethod'] ?? 'POST');
        $task['callback_url_display'] = htmlspecialchars($task['callbackUrl'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $task['error_display'] = htmlspecialchars($task['error'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $task['formatted_args'] = htmlspecialchars(json_encode($task['args'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        return $task;
    }

    /**
     * 生成任务操作按钮数组
     * 严格分离视图与逻辑。
     *
     * @param array $task 任务数据
     * @param string $salt CSRF 盐值
     * @param string $context 'list' (列表小链接) 或 'details' (详情大按钮)
     * @return array
     */
    private function getTaskActions(array $task, string $salt, string $context = 'list'): array
    {
        $actions = [];
        $csrfToken = $this->getCsrfToken($salt);

        // 1. 详情链接（仅列表上下文）
        // 详情页通常不需要指向自己的链接，或处理方式不同。
        if ($context === 'list') {
            $actions[] = [
                'type' => 'link',
                'label' => $this->language->get('details'),
                'url' => $this->urlGenerator->url('admin/task/details', ['task_id' => $task['id']]),
                'class' => 'text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-bold transition-colors mr-3',
                'attr' => ''
            ];
        }

        // 2. 取消按钮（待处理/运行中）
        if (in_array($task['status'], ['pending', 'running', 'retrying'])) {
            $baseClass = ($context === 'details')
                ? 'ajax-post flex-1 sm:flex-none px-6 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl shadow-lg shadow-amber-500/30 transition-all font-bold text-center'
                : 'ajax-post text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300 font-bold transition-colors';

            $actions[] = [
                'type' => 'button',
                'label' => $this->language->get('cancel'),
                'url' => $this->urlGenerator->url('admin/task/cancel'),
                'class' => $baseClass,
                'attr' => sprintf(
                    'data-confirm="%s" data-json=\'%s\'',
                    $this->language->get('confirm_cancel'),
                    json_encode(['_csrf_token' => $csrfToken, 'task_id' => $task['id']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                )
            ];
        }

        // 3. 重试按钮（失败）
        if ($task['status'] === 'failed') {
            $baseClass = ($context === 'details')
                ? 'ajax-post flex-1 sm:flex-none px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow-lg shadow-blue-500/30 transition-all font-bold text-center'
                : 'ajax-post text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-bold transition-colors';

            $actions[] = [
                'type' => 'button',
                'label' => $this->language->get('retry'),
                'url' => $this->urlGenerator->url('admin/task/retry'),
                'class' => $baseClass,
                'attr' => sprintf(
                    'data-confirm="%s" data-json=\'%s\'',
                    $this->language->get('confirm_retry'),
                    json_encode(['_csrf_token' => $csrfToken, 'task_id' => $task['id']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                )
            ];
        }

        return $actions;
    }

    /**
     * 查看任务日志
     */
    public function logs(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $taskId = RequestUtils::param('task_id', '');

        $currentUser = $request->getAttribute('user', []);
        $menu = $this->getAdminMenu();

        try {
            if (!$this->taskManage()) {
                return $this->errorMessage($this->language->get('operation_failed'), -1);
            }
            $logs = $this->taskManage->getLogs($taskId, 500);

            $data = [
                'header' => [
                    'title' => $this->language->get('admin_task_logs') . ($taskId ? ' - ' . $taskId : ''),
                    'keywords' => $this->language->get('admin_task_logs'),
                    'description' => '查看任务执行日志'
                ],
                'menu' => $menu,
                'menu_fixed' => ['parent' => 'system', 'child' => 'task'],
                'logs' => $logs,
                'task_id' => $taskId,
                'language' => [
                    'task_logs' => $this->language->get('admin_task_logs'),
                    'timestamp' => $this->language->get('created_at'),
                    'level' => '级别',
                    'message' => '消息',
                    'back_to_list' => $this->language->get('back_to_task_list'),
                    'refresh' => $this->language->get('refresh'),
                    'no_logs' => '暂无日志'
                ]
            ];

            $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'task_logs'];
            return $this->render($routeMeta['layout'], $data, true);
        } catch (\Exception $e) {
            return $this->errorMessage(
                $this->language->get('operation_failed') . ': ' . $e->getMessage(),
                -1
            );
        }
    }

    /**
     * 调整任务优先级
     */
    public function updatePriority(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 获取POST请求数据
        $postData = $request->getParsedBody();

        $taskId = RequestUtils::post('task_id', '');
        $priority = RequestUtils::post('priority', 5);

        if (empty($taskId)) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'task_id')), 1);
        }

        // 验证优先级范围
        if ($priority < 0 || $priority > 10) {
            return $this->errorMessage($this->language->get('parameter_error', array('error' => 'priority must be 0-10')), 1);
        }

        try {
            if (!$this->taskManage()) {
                return $this->errorMessage($this->language->get('operation_failed'), -1);
            }
            $result = $this->taskManage->updatePriority($taskId, $priority);

            if ($result['status'] === 'success') {
                return $this->successMessage(
                    sprintf($this->language->get('task_priority_updated'), $taskId, $result['newPriority']),
                    0
                );
            }

            return $this->errorMessage($result['msg'] ?? $this->language->get('operation_failed'), -1);
        } catch (\Exception $e) {
            return $this->errorMessage($this->language->get('operation_failed') . ': ' . $e->getMessage(), -1);
        }
    }
}
