<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use App\Controllers\Base\BaseController;
use App\Services\Auth\GroupService;
use App\Services\Content\NavigationService;
use App\Traits\Admin\AdminTrait;

/**
 * 导航管理控制器
 */
class NavController extends BaseController
{
    use AdminTrait;

    // hook app_Controllers_Admin_NavController_start.php

    /**
     * 导航列表
     *
     * @param $request
     * @return ResponseInterface
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $navigationService = $this->container->get(NavigationService::class);
        $groupService = $this->container->get(GroupService::class);
        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');

        // hook app_Controllers_Admin_NavController_list_start.php

        // 获取分页原始参数（路由段或 query 都可能）
        $page = RequestUtils::param('page', 1);
        $cursorId = RequestUtils::param('cursorId') ? RequestUtils::param('cursorId', 0) : null;
        $dirFlag = RequestUtils::param('dirFlag', 'next');
        $maxId = RequestUtils::param('maxId', 0);

        $pageSize = 20;
        $hasMore = false;

        // hook app_Controllers_Admin_NavController_list_before.php

        if (0 === $maxId) {
            $maxId = $navigationService->maxid();
        }

        // 适配器：锁定在 <= nodeId 的子集内翻页（baseOnFirstOnly=false）
        $adapter = BaseController::makeGenericAdapter([$navigationService, 'findPaged'], [
            'orderKey' => 'id',
            'indexKey' => 'id',
            'baseCondition' => ['<=' => $maxId], // 子集上界
            'conditionBuilder' => [BaseController::class, 'simpleConditionBuilder'],
            'baseOnFirstOnly' => false, // 锁定子集 <=$maxId
        ]);

        // cursor 分页
        [$dataList, $hasMore, $firstId, $lastId] = $this->fetchPaged($adapter, $pageSize, $cursorId, 'id', -1, $dirFlag, false);

        // hook app_Controllers_Admin_NavController_list_center.php

        // 获取所有一级菜单，用于显示父菜单名称
        $primaryNavs = $navigationService->find(['type' => 0], [], 1, 1000);

        // 格式化数据，遵循视图“绝对被动”原则
        if (!empty($dataList)) {
            $hasSettingAccess = $groupService->access((int)$currentUser['group_id'], 'setting');
            foreach ($dataList as &$item) {
                // 封装类型展示
                $isPrimary = 0 === (int)$item['type'];
                $item['type_fmt'] = [
                    'label' => $isPrimary ? $this->language->get('primary_menu') : $this->language->get('secondary_menu'),
                    'class' => $isPrimary ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400'
                ];

                // 封装跳转状态
                $isJump = (int)$item['jump'];
                $item['jump_fmt_data'] = [
                    'label' => $isJump ? $this->language->get('yes') : $this->language->get('no'),
                    'class' => $isJump ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'
                ];

                // 封装隐藏状态
                $isHide = (int)$item['hide'];
                $item['hide_fmt_data'] = [
                    'label' => $isHide ? $this->language->get('yes') : $this->language->get('no'),
                    'class' => $isHide ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'
                ];

                $item['name_fmt'] = [
                    'prefix' => !$isPrimary ? '<span class="text-gray-400 mr-2">└─</span>' : '',
                    'name' => (string)$item['name'],
                    'url_title' => (string)$item['url']
                ];

                $item['parent_name'] = '';
                if ($item['parent_id'] > 0 && isset($primaryNavs[$item['parent_id']])) {
                    $item['parent_name'] = $primaryNavs[$item['parent_id']]['name'];
                }

                // 预封装操作链接
                $item['operations'] = [];
                if ($hasSettingAccess) {
                    $item['operations'][] = [
                        'label' => $this->language->get('update'),
                        'url' => $this->urlGenerator->url('admin/nav/update', ['id' => $item['id']]),
                        'class' => 'text-blue-600 dark:text-blue-400'
                    ];
                    $item['operations'][] = [
                        'label' => $this->language->get('delete'),
                        'url' => $this->urlGenerator->url('admin/nav/postDelete', ['id' => $item['id']]),
                        'class' => 'text-red-600 dark:text-red-400 ajax-delete'
                    ];
                }
            }
            unset($item);
        }

        // hook app_Controllers_Admin_NavController_list_middle.php

        $pageLinkString = 'admin/nav/list'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('navigation_settings'),
                'keywords' => $this->language->get('navigation_settings'),
                'description' => $this->language->get('navigation_settings')
            ],
            'menu' => $this->getAdminMenu(),
            'menu_fixed' => ['parent' => 'setting', 'child' => 'navigation'],
            'csrf_token' => $csrfToken,
            'breadcrumb' => $this->getBreadcrumbData($pageLinkString),
            'pagination' => [
                'prev' => ($page > 1 && $firstId > 0) ? [
                    'url' => $this->urlGenerator->url($pageLinkString, ['page' => ($page - 1), 'cursorId' => $firstId, 'dirFlag' => 'previous', 'maxId' => $maxId]),
                    'label' => '&lsaquo; ' . ($this->language->get('previous_page') ?: 'Prev'),
                    'class' => 'px-4 py-2 text-xs font-bold rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-all'
                ] : null,
                'next' => ($hasMore && $lastId > 0) ? [
                    'url' => $this->urlGenerator->url($pageLinkString, ['page' => ($page + 1), 'cursorId' => $lastId, 'dirFlag' => 'next', 'maxId' => $maxId]),
                    'label' => ($this->language->get('next_page') ?: 'Next') . ' &rsaquo;',
                    'class' => 'px-4 py-2 text-xs font-bold rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-all'
                ] : null,
            ],
            'create_btn' => [
                'url' => $this->urlGenerator->url('admin/nav/create'),
                'label' => '+ ' . $this->language->get('add'),
                'class' => 'flex-1 sm:flex-none px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow-lg shadow-blue-500/30 transition-all transform hover:-translate-y-0.5 text-sm font-bold text-center'
            ],
            'bulk_delete_btn' => [
                'url' => $this->urlGenerator->url('admin/nav/postDelete'),
                'label' => $this->language->get('delete'),
                'class' => 'bulk-delete px-4 py-2 text-sm font-bold text-white rounded-xl bg-red-500 hover:bg-red-600 shadow-lg shadow-red-500/20 transition-all transform hover:-translate-y-0.5'
            ],
            'result' => $dataList,
            'language' => [
                'navigation_name' => $this->language->get('navigation_name'),
                'navigation_link' => $this->language->get('navigation_link'),
                'jump' => $this->language->get('jump'),
                'hide' => $this->language->get('hide'),
                'type' => $this->language->get('type'),
                'parent_id' => $this->language->get('parent_id'),
                'primary_menu' => $this->language->get('primary_menu'),
                'secondary_menu' => $this->language->get('secondary_menu'),
                'operation' => $this->language->get('operation'),
                'select_all' => $this->language->get('select_all'),
                'none' => $this->language->get('no_data_available'),
                'bulk_delete' => $this->language->get('delete'),
                'confirm_delete' => $this->language->get('confirm_delete'),
            ]
        ];

        // hook app_Controllers_Admin_NavController_list_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'nav_list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 创建导航
     *
     * @param $request
     * @return ResponseInterface
     */
    public function create(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $navigationService = $this->container->get(NavigationService::class);
        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');

        // hook app_Controllers_Admin_NavController_create_start.php

        $primaryNavList = $navigationService->find(['type' => 0], [], 1, 1000);

        // hook app_Controllers_Admin_NavController_create_after.php

        $pageLinkString = 'admin/nav/create'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('add') . ' - ' . $this->language->get('navigation_settings'),
            ],
            'menu' => $this->getAdminMenu(),
            'menu_fixed' => ['parent' => 'setting', 'child' => 'navigation'],
            'csrf_token' => $csrfToken,
            'breadcrumb' => $this->getBreadcrumbData($pageLinkString),
            'result' => [],
            'primary_nav_list' => $primaryNavList,
            'action' => $this->urlGenerator->url('admin/nav/postCreate'),
            'language' => [
                'navigation_name' => $this->language->get('navigation_name'),
                'navigation_link' => $this->language->get('navigation_link'),
                'jump' => $this->language->get('jump'),
                'hide' => $this->language->get('hide'),
                'type' => $this->language->get('type'),
                'parent_id' => $this->language->get('parent_id'),
                'primary_menu' => $this->language->get('primary_menu'),
                'secondary_menu' => $this->language->get('secondary_menu'),
                'submit' => $this->language->get('submit'),
                'cancel' => $this->language->get('cancel'),
                'none' => $this->language->get('none'),
            ]
        ];

        // hook app_Controllers_Admin_NavController_create_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'nav_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 保存创建
     *
     * @param $request
     * @return ResponseInterface
     */
    public function postCreate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $navigationService = $this->container->get(NavigationService::class);
        // hook app_Controllers_Admin_NavController_postCreate_start.php

        $name = RequestUtils::param('name', '');
        if (!$name) return $this->errorMessage($this->language->get('navigation_name') . ' ' . $this->language->get('no_data_available'), 'name');

        $url = RequestUtils::param('url', '');
        if (!$url) return $this->errorMessage($this->language->get('navigation_link') . ' ' . $this->language->get('no_data_available'), 'url');

        $parentId = RequestUtils::param('parent_id', 0);

        // hook app_Controllers_Admin_NavController_postCreate_before.php

        $data = [
            'type' => RequestUtils::param('type', 0),
            'parent_id' => $parentId,
            'hide' => RequestUtils::param('hide', 0),
            'jump' => RequestUtils::param('jump', 0),
            'name' => $name,
            'url' => $url,
            'created_at' => time()
        ];

        // hook app_Controllers_Admin_NavController_postCreate_after.php

        $result = $navigationService->insert($data);
        if (empty($result)) return $this->errorMessage($this->language->get('operation_failed'), -1);

        if ($parentId > 0) {
            $navigationService->update((int)$parentId, ['count+' => 1]);
        }

        // hook app_Controllers_Admin_NavController_postCreate_end.php

        return $this->successMessage($this->language->get('create_success'), 0, $this->urlGenerator->url('admin/nav/list'));
    }

    /**
     * 编辑导航
     *
     * @param $request
     * @return ResponseInterface
     */
    public function update(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $navigationService = $this->container->get(NavigationService::class);
        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');

        // hook app_Controllers_Admin_NavController_update_start.php

        $id = RequestUtils::param('id', 0);
        if (empty($id)) return $this->errorMessage($this->language->get('parameter_error'), 1);

        $nav = $navigationService->read($id);
        if (empty($nav)) return $this->errorMessage($this->language->get('data_does_not_exist'), -1);

        $primaryNavList = $navigationService->find(['type' => 0], [], 1, 1000);
        if (isset($primaryNavList[$id])) unset($primaryNavList[$id]); // 不能选自己作为父菜单

        // hook app_Controllers_Admin_NavController_update_after.php

        $pageLinkString = 'admin/nav/update'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('change') . ' - ' . $this->language->get('navigation_settings'),
            ],
            'menu' => $this->getAdminMenu(),
            'menu_fixed' => ['parent' => 'setting', 'child' => 'navigation'],
            'csrf_token' => $csrfToken,
            'breadcrumb' => $this->getBreadcrumbData($pageLinkString, ['id' => $id]),
            'result' => $nav,
            'primary_nav_list' => $primaryNavList,
            'action' => $this->urlGenerator->url('admin/nav/postUpdate', ['id' => $id]),
            'language' => [
                'navigation_name' => $this->language->get('navigation_name'),
                'navigation_link' => $this->language->get('navigation_link'),
                'jump' => $this->language->get('jump'),
                'hide' => $this->language->get('hide'),
                'type' => $this->language->get('type'),
                'parent_id' => $this->language->get('parent_id'),
                'primary_menu' => $this->language->get('primary_menu'),
                'secondary_menu' => $this->language->get('secondary_menu'),
                'submit' => $this->language->get('submit'),
                'cancel' => $this->language->get('cancel'),
                'none' => $this->language->get('none'),
            ]
        ];

        // hook app_Controllers_Admin_NavController_update_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'nav_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 保存更新
     *
     * @param $request
     * @return ResponseInterface
     */
    public function postUpdate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $navigationService = $this->container->get(NavigationService::class);
        // hook app_Controllers_Admin_NavController_postUpdate_start.php

        $id = RequestUtils::param('id', 0);
        if (empty($id)) return $this->errorMessage($this->language->get('parameter_error'), 1);

        $nav = $navigationService->read($id);
        if (empty($nav)) return $this->errorMessage($this->language->get('data_does_not_exist'), -1);

        $name = RequestUtils::param('name', '');
        if (!$name) return $this->errorMessage($this->language->get('navigation_name') . ' ' . $this->language->get('no_data_available'), 'name');

        $url = RequestUtils::param('url', '');
        if (!$url) return $this->errorMessage($this->language->get('navigation_link') . ' ' . $this->language->get('no_data_available'), 'url');

        $parentId = RequestUtils::param('parent_id', 0);

        $update = [
            'name' => $name,
            'url' => $url,
            'hide' => RequestUtils::param('hide', 0),
            'jump' => RequestUtils::param('jump', 0),
            'type' => RequestUtils::param('type', 0),
            'parent_id' => $parentId,
        ];

        // hook app_Controllers_Admin_NavController_postUpdate_before.php

        $result = $navigationService->update($id, $update);
        if ($result === 0) return $this->errorMessage($this->language->get('operation_failed'), -1);

        // 如果父级 ID 发生变动，同步更新 old/new parent 的数量
        if ((int)$nav['parent_id'] !== $parentId) {
            if ($nav['parent_id'] > 0) $navigationService->update((int)$nav['parent_id'], ['count-' => 1]);
            if ($parentId > 0) $navigationService->update($parentId, ['count+' => 1]);
        }

        // hook app_Controllers_Admin_NavController_postUpdate_end.php

        return $this->successMessage($this->language->get('update_success'), 0, $this->urlGenerator->url('admin/nav/list'));
    }

    /**
     * 删除操作
     *
     * @param $request
     * @return ResponseInterface
     */
    public function postDelete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_NavController_postDelete_start.php

        $ids = RequestUtils::param('id');
        if (empty($ids)) return $this->errorMessage($this->language->get('parameter_error'), 1);

        if (!is_array($ids)) {
            $ids = [RequestUtils::param('id', 0)];
        } else {
            $ids = RequestUtils::param('id', [0]);
        }

        $navigationService = $this->container->get(NavigationService::class);

        // 获取要删除的导航详情，判断是否有二级导航 (count 字段 > 0 表示有下级)
        $datalist = $navigationService->find(['id' => $ids], [], 1, count($ids));
        $deleteIds = [];
        $parentIds = [];
        foreach ($datalist as $item) {
            if ((int)($item['count'] ?? 0) > 0) {
                continue; // 跳过有二级导航的项目
            }
            $deleteIds[] = (int)$item['id'];
            if ($item['parent_id'] > 0) $parentIds[] = (int)$item['parent_id'];
        }

        if (empty($deleteIds)) {
            return $this->errorMessage($this->language->get('delete_not_allowed_has_data'), -1);
        }

        // hook app_Controllers_Admin_NavController_postDelete_before.php

        $result = $navigationService->delete($deleteIds);
        if ($result === 0) return $this->errorMessage($this->language->get('operation_failed'), -1);

        // 同步更新父级的二级导航数量
        if (!empty($parentIds)) {
            $parentIds = array_unique($parentIds);
            foreach ($parentIds as $pid) {
                if ($pid > 0) {
                    $navigationService->update((int)$pid, ['count' => $navigationService->count(['parent_id' => $pid])]);
                }
            }
        }

        // hook app_Controllers_Admin_NavController_postDelete_end.php

        return $this->successMessage($this->language->get('delete_success'), 0, $this->urlGenerator->url('admin/nav/list'));
    }

    /**
     * 获取面包屑导航数据
     *
     * @param string $pageLinkString
     * @param array $extra
     * @return array
     */
    private function getBreadcrumbData(string $pageLinkString, array $extra = []): array
    {
        $breadcrumb = [
            'home' => [
                'name' => $this->language->get('home_page'),
                'url' => $this->urlGenerator->url('admin/panel')
            ],
            'list' => [
                'name' => $this->language->get('navigation_settings'),
                'url' => $this->urlGenerator->url('admin/nav/list')
            ]
        ];

        if ($pageLinkString === 'admin/nav/create') {
            $breadcrumb['title'] = [
                'name' => $this->language->get('add'),
                'url' => $this->urlGenerator->url($pageLinkString, $extra)
            ];
        } elseif ($pageLinkString === 'admin/nav/update') {
            $breadcrumb['title'] = [
                'name' => $this->language->get('change'),
                'url' => $this->urlGenerator->url($pageLinkString, $extra)
            ];
        }

        return $breadcrumb;
    }

    // hook app_Controllers_Admin_NavController_end.php
}
