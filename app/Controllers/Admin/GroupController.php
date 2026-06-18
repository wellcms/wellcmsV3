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
use App\Traits\Admin\AdminTrait;

class GroupController extends BaseController
{
    use AdminTrait;

    // hook app_Controllers_Admin_GroupController_start.php

    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $groupService = $this->container->get(GroupService::class);
        $extra = [];

        // hook app_Controllers_Admin_GroupController_list_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        $systemGroup = $this->systemGroup();
        $groupList = $groupService->findCacheList();
        $result = [];
        if (!empty($groupList)) {
            $hasGroupManageAccess = $groupService->access((int)$user['group_id'], 'group');
            foreach ($groupList as $group) {
                $groupId = (int)$group['id'];
                $isSystem = in_array($groupId, $systemGroup, true);

                // 封装展示逻辑
                $group['id_fmt'] = [
                    'label' => $group['id'],
                    'class' => 'inline-flex items-center justify-center px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/5 text-gray-500 dark:text-gray-400 text-xs font-bold font-mono'
                ];

                $group['name_fmt'] = [
                    'name' => (string)$group['name'],
                    'dot_class' => $groupId <= 2 ? 'bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.5)]' : 'bg-blue-500 shadow-[0_0_10px_rgba(59,130,246,0.3)]',
                    'is_system' => $groupId <= 4,
                    'system_label' => 'system'
                ];

                $group['credits_from_fmt'] = number_format((float)$group['credits_from']);
                $group['credits_to_fmt'] = number_format((float)$group['credits_to']);

                // 封装操作
                $group['operations'] = [];
                $group['operations'][] = [
                    'label' => $this->language->get('update'),
                    'url' => $this->urlGenerator->url('admin/group/update', ['id' => $groupId]),
                    'class' => 'text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300'
                ];

                if ($hasGroupManageAccess && !$isSystem) {
                    $group['operations'][] = [
                        'label' => $this->language->get('delete'),
                        'url' => $this->urlGenerator->url('admin/group/postDelete', ['id' => $groupId]),
                        'class' => 'ajax-delete text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300'
                    ];
                }
                $result[] = $group;
            }
        }

        // hook app_Controllers_Admin_GroupController_list_before.php

        $page_link_string = 'admin/group/list'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('user_group'),
                'keywords' => $this->language->get('user_group'),
                'description' => isset($this->appConfig['sitename']) ? ' - ' . $this->language->get('user_group') : $this->language->get('user_group')
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'user', 'child' => 'group'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'result' => $result,
            'systemGroup' => $systemGroup,
            'operation_links' => [
                'create' => $this->urlGenerator->url('admin/group/create'),
            ],
            'language' => [
                'user_group' => $this->language->get('user_group'),
                'user_group_id' => $this->language->get('user_group_id'),
                'user_group_name' => $this->language->get('user_group_name'),
                'start_point' => $this->language->get('start_point'),
                'end_point' => $this->language->get('end_point'),
                'operation' => $this->language->get('operation'),
                'create' => $this->language->get('create'),
                'change' => $this->language->get('change'),
                'delete' => $this->language->get('delete'),
                'group_operation_warning' => $this->language->get('group_operation_warning')
            ]
        ];

        // hook app_Controllers_Admin_GroupController_list_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'group_list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function create(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Admin_GroupController_create_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);
        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        $groupCommonAuthority = [
            [
                'name' => 'name',
                'language' => $this->language->get('user_group_name'),
                'value' => '',
            ],
            [
                'name' => 'credits_from',
                'language' => $this->language->get('start_point'),
                'value' => '',
            ],
            [
                'name' => 'credits_to',
                'language' => $this->language->get('end_point'),
                'value' => '',
            ],
            [
                'name' => 'upload_daily_quota',
                'language' => $this->language->get('upload_daily_quota'),
                'value' => 50,
            ],
            [
                'name' => 'upload_per_post',
                'language' => $this->language->get('upload_per_post'),
                'value' => 10,
            ],
            [
                'name' => 'quota_daily_size',
                'language' => $this->language->get('quota_daily_size'),
                'value' => 0,
            ],
            [
                'name' => 'quota_single_size',
                'language' => $this->language->get('quota_single_size'),
                'value' => 0,
            ],
            [
                'name' => 'allowed_file_types',
                'language' => $this->language->get('allowed_file_types'),
                'value' => '',
            ],
        ];

        // 用户权限
        $groupUserAuthority = [
            [
                'name' => 'view',
                'language' => $this->language->get('view'),
                'value' => 0
            ],
            [
                'name' => 'post',
                'language' => $this->language->get('post'),
                'value' => 0
            ],
            [
                'name' => 'reply',
                'language' => $this->language->get('reply'),
                'value' => 0
            ],
            [
                'name' => 'user_update',
                'language' => $this->language->get('user_update'),
                'value' => 0
            ],
            [
                'name' => 'user_delete',
                'language' => $this->language->get('user_delete'),
                'value' => 0
            ],
            [
                'name' => 'username_update',
                'language' => $this->language->get('username_update'),
                'value' => 0
            ],
            [
                'name' => 'email_update',
                'language' => $this->language->get('email_update'),
                'value' => 0
            ],
            [
                'name' => 'upload',
                'language' => $this->language->get('upload'),
                'value' => 0
            ],
            [
                'name' => 'down',
                'language' => $this->language->get('down'),
                'value' => 0
            ],
            [
                'name' => 'direct_post',
                'language' => $this->language->get('direct_post'),
                'value' => 0
            ],
            [
                'name' => 'direct_reply',
                'language' => $this->language->get('direct_reply'),
                'value' => 0
            ],
            [
                'name' => 'view_user',
                'language' => $this->language->get('view_user_info'),
                'value' => 0
            ],
            [
                'name' => 'access_user',
                'language' => $this->language->get('access_user'),
                'value' => 0
            ],
            [
                'name' => 'view_ip',
                'language' => $this->language->get('view_ip'),
                'value' => 0
            ]
        ];

        // 前台管理权限
        $groupManageAuthority = [
            [
                'name' => 'pinned',
                'language' => $this->language->get('manage_pinned'),
                'value' => 0
            ],
            [
                'name' => 'feature',
                'language' => $this->language->get('manage_feature'),
                'value' => 0
            ],
            [
                'name' => 'update',
                'language' => $this->language->get('update'),
                'value' => 0
            ],
            [
                'name' => 'remove',
                'language' => $this->language->get('remove'),
                'value' => 0
            ],
            [
                'name' => 'move',
                'language' => $this->language->get('move'),
                'value' => 0
            ],
            [
                'name' => 'delete',
                'language' => $this->language->get('delete'),
                'value' => 0
            ],
            [
                'name' => 'ban',
                'language' => $this->language->get('ban'),
                'value' => 0
            ],
            [
                'name' => 'reward',
                'language' => $this->language->get('reward'),
                'value' => 0
            ],
            [
                'name' => 'punishment',
                'language' => $this->language->get('punishment'),
                'value' => 0
            ],
            [
                'name' => 'review',
                'language' => $this->language->get('review'),
                'value' => 0
            ]
        ];

        // 后台系统权限
        $groupSystemAuthority = [
            [
                'name' => 'administer',
                'language' => $this->language->get('administer'),
                'value' => 0
            ],
            [
                'name' => 'setting',
                'language' => $this->language->get('system_setting'),
                'value' => 0
            ],
            [
                'name' => 'smtp',
                'language' => $this->language->get('setting_smtp'),
                'value' => 0
            ],
            [
                'name' => 'task',
                'language' => $this->language->get('task'),
                'value' => 0
            ],
            [
                'name' => 'group',
                'language' => $this->language->get('manage_user_group'),
                'value' => 0
            ],
            [
                'name' => 'create_group',
                'language' => $this->language->get('manage_create_group'),
                'value' => 0
            ],
            [
                'name' => 'update_group',
                'language' => $this->language->get('manage_update_group'),
                'value' => 0
            ],
            [
                'name' => 'delete_group',
                'language' => $this->language->get('manage_delete_group'),
                'value' => 0
            ],
            [
                'name' => 'store',
                'language' => $this->language->get('manage_app_store'),
                'value' => 0
            ],
            [
                'name' => 'plugin',
                'language' => $this->language->get('manage_plugin'),
                'value' => 0
            ],
            [
                'name' => 'theme',
                'language' => $this->language->get('manage_theme'),
                'value' => 0
            ],
            [
                'name' => 'other',
                'language' => $this->language->get('manage_other'),
                'value' => 0
            ],
            [
                'name' => 'user',
                'language' => $this->language->get('manage_user'),
                'value' => 0
            ],
            [
                'name' => 'create_user',
                'language' => $this->language->get('manage_create_user'),
                'value' => 0
            ],
            [
                'name' => 'update_user',
                'language' => $this->language->get('manage_update_user'),
                'value' => 0
            ],
            [
                'name' => 'delete_user',
                'language' => $this->language->get('manage_delete_user'),
                'value' => 0
            ]
        ];

        // hook app_Controllers_Admin_GroupController_create_after.php

        $page_link_string = 'admin/group/create'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('create_user_group'),
                'keywords' => $this->language->get('create_user_group'),
                'description' => $this->language->get('create_user_group'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'user', 'child' => 'group'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('user_group'),
                    'url' => $this->urlGenerator->url('admin/group/list')
                ],
                'title' => [
                    'name' => $this->language->get('create_user_group'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'group_common_authority' => $groupCommonAuthority,
            'group_user_authority' => $groupUserAuthority,
            'group_manage_authority' => $groupManageAuthority,
            'group_system_authority' => $groupSystemAuthority,
            'result' => [],
            'action' => $this->urlGenerator->url('admin/group/postCreate'),
            'language' => [
                'user_group' => $this->language->get('user_group'),
                'user_group_id' => $this->language->get('user_group_id'),
                'user_group_name' => $this->language->get('user_group_name'),
                'start_point' => $this->language->get('start_point'),
                'end_point' => $this->language->get('end_point'),
                'user_authority' => $this->language->get('user_authority'),
                'manage_authority' => $this->language->get('manage_authority'),
                'system_authority' => $this->language->get('system_authority'),
                'submit' => $this->language->get('submit'),
            ]
        ];

        // hook app_Controllers_Admin_GroupController_create_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'group_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postCreate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $groupService = $this->container->get(GroupService::class);

        // hook app_Controllers_Admin_GroupController_postCreate_start.php

        $name = RequestUtils::post('name', '');
        if (!$name) return $this->errorMessage($this->language->get('user_group_name_is_empty'), 'name');

        $credits_from = RequestUtils::post('credits_from', 0);
        $credits_to = RequestUtils::post('credits_to', 0);
        $upload_daily_quota = RequestUtils::post('upload_daily_quota', 0);
        $upload_per_post = RequestUtils::post('upload_per_post', 0);
        $quota_daily_size = RequestUtils::post('quota_daily_size', 0);
        $quota_single_size = RequestUtils::post('quota_single_size', 0);
        $allowed_file_types = RequestUtils::post('allowed_file_types', 0);

        $administer = RequestUtils::post('administer', 0);
        $setting = RequestUtils::post('setting', 0);
        $group = RequestUtils::post('group', 0);
        $create_group = RequestUtils::post('create_group', 0);
        $update_group = RequestUtils::post('update_group', 0);
        $delete_group = RequestUtils::post('delete_group', 0);
        $store = RequestUtils::post('store', 0);
        $plugin = RequestUtils::post('plugin', 0);
        $theme = RequestUtils::post('theme', 0);
        $other = RequestUtils::post('other', 0);
        $user = RequestUtils::post('user', 0);
        $create_user = RequestUtils::post('create_user', 0);
        $update_user = RequestUtils::post('update_user', 0);
        $delete_user = RequestUtils::post('delete_user', 0);
        $view_user = RequestUtils::post('view_user', 0);
        $access_user = RequestUtils::post('access_user', 0);
        $view_ip = RequestUtils::post('view_ip', 0);
        $pinned = RequestUtils::post('pinned', 0);
        $feature = RequestUtils::post('feature', 0);
        $update = RequestUtils::post('update', 0);
        $remove = RequestUtils::post('remove', 0);
        $move = RequestUtils::post('move', 0);
        $delete = RequestUtils::post('delete', 0);
        $ban = RequestUtils::post('ban', 0);
        $reward = RequestUtils::post('reward', 0);
        $punishment = RequestUtils::post('punishment', 0);
        $verify = RequestUtils::post('review', 0);
        $view = RequestUtils::post('view', 0);
        $post = RequestUtils::post('post', 0);
        $reply = RequestUtils::post('reply', 0);
        $user_update = RequestUtils::post('user_update', 0);
        $user_delete = RequestUtils::post('user_delete', 0);
        $username_update = RequestUtils::post('username_update', 0);
        $email_update = RequestUtils::post('email_update', 0);
        $upload = RequestUtils::post('upload', 0);
        $down = RequestUtils::post('down', 0);
        $direct_post = RequestUtils::post('direct_post', 0);
        $direct_reply = RequestUtils::post('direct_reply', 0);
        $smtp = RequestUtils::post('smtp', 0);
        $task = RequestUtils::post('task', 0);

        // hook app_Controllers_Admin_GroupController_postCreate_before.php

        $maxid = $groupService->maxid();

        $data = [
            'id' => $maxid + 1,
            'name' => $name,
            'credits_from' => $credits_from,
            'credits_to' => $credits_to,
            'upload_daily_quota' => $upload_daily_quota,
            'upload_per_post' => $upload_per_post,
            'quota_daily_size' => $quota_daily_size,
            'quota_single_size' => $quota_single_size,
            'allowed_file_types' => $allowed_file_types,
            'administer' => $administer,
            'setting' => $setting,
            'group' => $group,
            'create_group' => $create_group,
            'update_group' => $update_group,
            'delete_group' => $delete_group,
            'store' => $store,
            'plugin' => $plugin,
            'theme' => $theme,
            'other' => $other,
            'user' => $user,
            'create_user' => $create_user,
            'update_user' => $update_user,
            'delete_user' => $delete_user,
            'view_user' => $view_user,
            'access_user' => $access_user,
            'view_ip' => $view_ip,
            'pinned' => $pinned,
            'feature' => $feature,
            'update' => $update,
            'remove' => $remove,
            'move' => $move,
            'delete' => $delete,
            'ban' => $ban,
            'reward' => $reward,
            'punishment' => $punishment,
            'review' => $verify,
            'view' => $view,
            'post' => $post,
            'reply' => $reply,
            'user_update' => $user_update,
            'user_delete' => $user_delete,
            'username_update' => $username_update,
            'email_update' => $email_update,
            'upload' => $upload,
            'down' => $down,
            'direct_post' => $direct_post,
            'direct_reply' => $direct_reply,
            'smtp' => $smtp,
            'task' => $task
        ];

        // hook app_Controllers_Admin_GroupController_postCreate_before.php

        $result = $groupService->insert($data);

        // hook app_Controllers_Admin_GroupController_postCreate_end.php

        return $this->successMessage($this->language->get('create_success'), 0, $this->urlGenerator->url('admin/group/list'), 2);
    }

    public function update(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $groupService = $this->container->get(GroupService::class);
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Admin_GroupController_update_start.php

        $groupId = RequestUtils::param('id', 0);
        $result = $groupService->read($groupId);
        if (empty($result)) return $this->errorMessage($this->language->get('user_group_does_not_exist'), -1);

        $extra['id'] = $groupId;

        // hook app_Controllers_Admin_GroupController_update_before.php

        $csrfToken = $this->getCsrfToken($user['salt']);
        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        $groupCommonAuthority = [
            [
                'name' => 'name',
                'language' => $this->language->get('user_group_name'),
                'value' => $result['name'],
            ],
            [
                'name' => 'credits_from',
                'language' => $this->language->get('start_point'),
                'value' => $result['credits_from'],
            ],
            [
                'name' => 'credits_to',
                'language' => $this->language->get('end_point'),
                'value' => $result['credits_to'],
            ],
            [
                'name' => 'upload_daily_quota',
                'language' => $this->language->get('upload_daily_quota'),
                'value' => $result['upload_daily_quota'],
            ],
            [
                'name' => 'upload_per_post',
                'language' => $this->language->get('upload_per_post'),
                'value' => $result['upload_per_post'],
            ],
            [
                'name' => 'quota_daily_size',
                'language' => $this->language->get('quota_daily_size'),
                'value' => $result['quota_daily_size'],
            ],
            [
                'name' => 'quota_single_size',
                'language' => $this->language->get('quota_single_size'),
                'value' => $result['quota_single_size'],
            ],
            [
                'name' => 'allowed_file_types',
                'language' => $this->language->get('allowed_file_types'),
                'value' => $result['allowed_file_types'],
            ],
        ];

        // 用户权限
        $groupUserAuthority = [
            [
                'name' => 'view',
                'language' => $this->language->get('view'),
                'value' => isset($result['view']) ? $result['view'] : 0
            ],
            [
                'name' => 'post',
                'language' => $this->language->get('post'),
                'value' => isset($result['post']) ? $result['post'] : 0
            ],
            [
                'name' => 'reply',
                'language' => $this->language->get('reply'),
                'value' => isset($result['reply']) ? $result['reply'] : 0
            ],
            [
                'name' => 'user_update',
                'language' => $this->language->get('user_update'),
                'value' => isset($result['user_update']) ? $result['user_update'] : 0
            ],
            [
                'name' => 'user_delete',
                'language' => $this->language->get('user_delete'),
                'value' => isset($result['user_delete']) ? $result['user_delete'] : 0
            ],
            [
                'name' => 'username_update',
                'language' => $this->language->get('username_update'),
                'value' => isset($result['username_update']) ? $result['username_update'] : 0
            ],
            [
                'name' => 'email_update',
                'language' => $this->language->get('email_update'),
                'value' => isset($result['email_update']) ? $result['email_update'] : 0
            ],
            [
                'name' => 'upload',
                'language' => $this->language->get('upload'),
                'value' => isset($result['upload']) ? $result['upload'] : 0
            ],
            [
                'name' => 'down',
                'language' => $this->language->get('down'),
                'value' => isset($result['down']) ? $result['down'] : 0
            ],
            [
                'name' => 'direct_post',
                'language' => $this->language->get('direct_post'),
                'value' => isset($result['direct_post']) ? $result['direct_post'] : 0
            ],
            [
                'name' => 'direct_reply',
                'language' => $this->language->get('direct_reply'),
                'value' => isset($result['direct_reply']) ? $result['direct_reply'] : 0
            ],
            [
                'name' => 'view_user',
                'language' => $this->language->get('view_user_info'),
                'value' => isset($result['view_user']) ? $result['view_user'] : 0
            ],
            [
                'name' => 'access_user',
                'language' => $this->language->get('access_user'),
                'value' => isset($result['access_user']) ? $result['access_user'] : 0
            ],
            [
                'name' => 'view_ip',
                'language' => $this->language->get('view_ip'),
                'value' => isset($result['view_ip']) ? $result['view_ip'] : 0
            ]
        ];

        // 前台管理权限
        $groupManageAuthority = [
            [
                'name' => 'pinned',
                'language' => $this->language->get('manage_pinned'),
                'value' => isset($result['pinned']) ? $result['pinned'] : 0
            ],
            [
                'name' => 'feature',
                'language' => $this->language->get('manage_feature'),
                'value' => isset($result['feature']) ? $result['feature'] : 0
            ],
            [
                'name' => 'update',
                'language' => $this->language->get('update'),
                'value' => isset($result['update']) ? $result['update'] : 0
            ],
            [
                'name' => 'remove',
                'language' => $this->language->get('remove'),
                'value' => isset($result['remove']) ? $result['remove'] : 0
            ],
            [
                'name' => 'move',
                'language' => $this->language->get('move'),
                'value' => isset($result['move']) ? $result['move'] : 0
            ],
            [
                'name' => 'delete',
                'language' => $this->language->get('physical_delete'),
                'value' => isset($result['delete']) ? $result['delete'] : 0
            ],
            [
                'name' => 'ban',
                'language' => $this->language->get('ban'),
                'value' => isset($result['ban']) ? $result['ban'] : 0
            ],
            [
                'name' => 'reward',
                'language' => $this->language->get('reward'),
                'value' => isset($result['reward']) ? $result['reward'] : 0
            ],
            [
                'name' => 'punishment',
                'language' => $this->language->get('punishment'),
                'value' => isset($result['punishment']) ? $result['punishment'] : 0
            ],
            [
                'name' => 'review',
                'language' => $this->language->get('review'),
                'value' => isset($result['review']) ? $result['review'] : 0
            ]
        ];

        // 后台系统权限
        $groupSystemAuthority = [
            [
                'name' => 'administer',
                'language' => $this->language->get('administer'),
                'value' => isset($result['administer']) ? $result['administer'] : 0
            ],
            [
                'name' => 'setting',
                'language' => $this->language->get('system_setting'),
                'value' => isset($result['setting']) ? $result['setting'] : 0
            ],
            [
                'name' => 'smtp',
                'language' => $this->language->get('setting_smtp'),
                'value' => isset($result['smtp']) ? $result['smtp'] : 0
            ],
            [
                'name' => 'task',
                'language' => $this->language->get('task'),
                'value' => isset($result['task']) ? $result['task'] : 0
            ],
            [
                'name' => 'group',
                'language' => $this->language->get('manage_user_group'),
                'value' => isset($result['group']) ? $result['group'] : 0
            ],
            [
                'name' => 'create_group',
                'language' => $this->language->get('manage_create_group'),
                'value' => isset($result['create_group']) ? $result['create_group'] : 0
            ],
            [
                'name' => 'update_group',
                'language' => $this->language->get('manage_update_group'),
                'value' => isset($result['update_group']) ? $result['update_group'] : 0
            ],
            [
                'name' => 'delete_group',
                'language' => $this->language->get('manage_delete_group'),
                'value' => isset($result['delete_group']) ? $result['delete_group'] : 0
            ],
            [
                'name' => 'store',
                'language' => $this->language->get('manage_app_store'),
                'value' => isset($result['store']) ? $result['store'] : 0
            ],
            [
                'name' => 'plugin',
                'language' => $this->language->get('manage_plugin'),
                'value' => isset($result['plugin']) ? $result['plugin'] : 0
            ],
            [
                'name' => 'theme',
                'language' => $this->language->get('manage_theme'),
                'value' => isset($result['theme']) ? $result['theme'] : 0
            ],
            [
                'name' => 'other',
                'language' => $this->language->get('manage_other'),
                'value' => isset($result['other']) ? $result['other'] : 0
            ],
            [
                'name' => 'user',
                'language' => $this->language->get('manage_user'),
                'value' => isset($result['user']) ? $result['user'] : 0
            ],
            [
                'name' => 'create_user',
                'language' => $this->language->get('manage_create_user'),
                'value' => isset($result['create_user']) ? $result['create_user'] : 0
            ],
            [
                'name' => 'update_user',
                'language' => $this->language->get('manage_update_user'),
                'value' => isset($result['update_user']) ? $result['update_user'] : 0
            ],
            [
                'name' => 'delete_user',
                'language' => $this->language->get('manage_delete_user'),
                'value' => isset($result['delete_user']) ? $result['delete_user'] : 0
            ]
        ];

        // hook app_Controllers_Admin_GroupController_update_after.php

        $page_link_string = 'admin/group/update'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('update_user_group'),
                'keywords' => $this->language->get('update_user_group'),
                'description' => $this->language->get('update_user_group')
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'user', 'child' => 'group'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('user_group'),
                    'url' => $this->urlGenerator->url('admin/group/list')
                ],
                'title' => [
                    'name' => $this->language->get('update_user_group'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'group_common_authority' => $groupCommonAuthority,
            'group_user_authority' => $groupUserAuthority,
            'group_manage_authority' => $groupManageAuthority,
            'group_system_authority' => $groupSystemAuthority,
            'result' => $result,
            'action' => $this->urlGenerator->url('admin/group/postUpdate', $extra),
            'language' => [
                'user_group' => $this->language->get('user_group'),
                'user_group_id' => $this->language->get('user_group_id'),
                'user_group_name' => $this->language->get('user_group_name'),
                'start_point' => $this->language->get('start_point'),
                'end_point' => $this->language->get('end_point'),
                'user_authority' => $this->language->get('user_authority'),
                'manage_authority' => $this->language->get('manage_authority'),
                'system_authority' => $this->language->get('system_authority'),
                'submit' => $this->language->get('submit'),
            ]
        ];

        // hook app_Controllers_Admin_GroupController_update_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'group_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postUpdate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $groupService = $this->container->get(GroupService::class);
        $update = [];

        // hook app_Controllers_Admin_GroupController_postUpdate_start.php

        $groupId = RequestUtils::param('id', 0);
        $result = $groupService->read($groupId);
        if (empty($result)) return $this->errorMessage($this->language->get('user_group_does_not_exist'), -1);

        // hook app_Controllers_Admin_GroupController_postUpdate_before.php

        $name = RequestUtils::post('name', '');
        if (!$name) return $this->errorMessage($this->language->get('user_group_name_is_empty'), 6);

        $name != $result['name'] && $update['name'] = $name;

        foreach ($result as $field => $item) {
            if ('id' === $field || 'name' === $field) continue;

            $postValue = RequestUtils::post($field, null);
            // 处理未传值的字段（通常是未勾选的复选框），原值为数字则视为 0 以便取消权限
            if (null === $postValue) {
                if (is_numeric($item)) {
                    $postValue = 0;
                } else {
                    continue;
                }
            }

            if (is_numeric($item)) {
                $postValue = (int)$postValue;
                if ((int)$item !== $postValue) {
                    $update[$field] = $postValue;
                }
            } else {
                $postValue = (string)$postValue;
                if ((string)$item !== $postValue) {
                    $update[$field] = $postValue;
                }
            }
        }

        // hook app_Controllers_Admin_GroupController_postUpdate_after.php

        if (!empty($update)) $groupService->update($groupId, $update);

        // hook app_Controllers_Admin_GroupController_postUpdate_end.php

        return $this->successMessage($this->language->get('update_success'), 0, $this->urlGenerator->url('admin/group/list'), 2);
    }

    public function postDelete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $groupService = $this->container->get(GroupService::class);

        // hook app_Controllers_Admin_GroupController_postDelete_start.php

        $groupId = RequestUtils::param('id', 0);
        if (empty($groupId)) return $this->errorMessage($this->language->get('params_error', ['error' => '$groupId']), 6);

        $groups = $groupService->findCacheList();
        if (empty($groups)) return $this->errorMessage($this->language->get('user_group_no_data_available'), -1);

        if (!isset($groups[$groupId])) return $this->errorMessage($this->language->get('user_group_does_not_exist'), 2);

        $systemGroup = $this->systemGroup();
        if (in_array((int)$groupId, $systemGroup, true)) return $this->errorMessage($this->language->get('group_delete_warning'), 15);

        // hook app_Controllers_Admin_GroupController_postDelete_after.php

        $result = $groupService->delete($groupId);
        if ($result === 0) return $this->errorMessage($this->language->get('delete_failed'), -1);

        // hook app_Controllers_Admin_GroupController_postDelete_end.php

        return $this->successMessage($this->language->get('delete_success'), 0, $this->urlGenerator->url('admin/group/list'), 2);
    }

    private function systemGroup(): array{
        return [0, 1, 2, 3, 4, 5, 6, 7, 101];
    }

    // hook app_Controllers_Admin_GroupController_end.php
}
