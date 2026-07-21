<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\{SafeHelper, SecurityHelper};
use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;

/**
 * 用户管理控制器
 * 遵循 WellCMS 3.0 开发规范：被动视图、协同文本封装、构造函数注入
 */
class UserController extends BaseController
{
    use AdminTrait;

    /** @var \App\Services\Auth\UserService */
    protected $userService;
    /** @var \App\Services\Auth\GroupService */
    protected $groupService;

    /**
     * 构造函数注入依赖
     */
    public function __construct(
        \Framework\Http\Interfaces\ServerRequestInterface $request,
        \App\Controllers\Base\ResponseFormatter $responseFormatter,
        \App\Interfaces\LanguageLoaderInterface $language,
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Auth\UserService $userService,
        \App\Services\System\MenuService $menuService,
        \App\Controllers\Base\TemplateManager $templateManager,
        \App\Services\Auth\TokenService $tokenService,
        array $appConfig,
        array $i18nConfig,
        \App\Services\Auth\GroupService $groupService,
        ?\Framework\Core\Container $container = null
    ) {
        parent::__construct($request, $responseFormatter, $language, $urlGenerator, $userService, $menuService, $templateManager, $tokenService, $appConfig, $i18nConfig, $container);
        $this->userService = $userService;
        $this->groupService = $groupService;
    }

    // hook app_Controllers_Admin_UserController_start.php

    /**
     * 用户列表
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $currentUser = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($currentUser['salt'] ?? '');
        $extra = [];

        // hook app_Controllers_Admin_UserController_list_start.php

        $menu = $this->getAdminMenu();

        // 分页及过滤参数 (利用 RequestUtils 定型)
        $page = RequestUtils::param('page', 1);
        $cursorId = RequestUtils::param('cursorId', null);
        $dirFlag = RequestUtils::param('dirFlag', 'next');
        $masterId = RequestUtils::param('masterId', 0);
        $maxId = RequestUtils::param('maxId', 0);
        $searchType = RequestUtils::param('searchType', '');
        $keywords = RequestUtils::param('keywords', '');
        $keywords = SecurityHelper::urldecode($keywords);
        $keywords = trim($keywords);

        if ($searchType && $keywords) {
            $extra += ['searchType' => $searchType, 'keywords' => SecurityHelper::urlencode($keywords)];
        }

        $pageSize = 100;
        $hasMore = false;
        $dataList = [];

        // hook app_Controllers_Admin_UserController_list_before.php

        if ($searchType && $keywords) {
            $cond = [];
            $allowType = ['id', 'username', 'email', 'group_id', 'createIp'];
            if (!in_array($searchType, $allowType, true)) {
                return $this->errorMessage($this->language->get('data_error', ['msg' => $searchType]), 8);
            }

            if ('createIp' === $searchType) {
                if (false === filter_var($keywords, FILTER_VALIDATE_IP)) {
                    return $this->errorMessage($this->language->get('data_error', ['msg' => $searchType]), 8);
                }
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($keywords);
                $cond['create_ip'] = $ip2bin;
            } elseif (in_array($searchType, ['id', 'group_id'], true)) {
                $cond[$searchType] = (int)$keywords;
            } else {
                $cond[$searchType] = (string)$keywords;
            }

            $dataList = $this->userService->find($cond, ['id' => -1], 1, $pageSize);
            $hasMore = count($dataList) === $pageSize;
            $firstId = !empty($dataList) ? (int)reset($dataList)['id'] : 0;
            $lastId  = $hasMore ? (int)end($dataList)['id'] : 0;
        } else {
            0 === $maxId && $maxId = (int)$this->userService->maxid();

            // 适配器翻页
            $adapter = BaseController::makeGenericAdapter([$this->userService, 'findPaged'], [
                'orderKey' => 'id',
                'indexKey' => 'id',
                'baseCondition' => ['<=' => $maxId],
                'conditionBuilder' => [BaseController::class, 'simpleConditionBuilder'],
                'baseOnFirstOnly' => false,
            ]);

            [$dataList, $hasMore, $firstId, $lastId] = $this->fetchPaged($adapter, $pageSize, $cursorId, 'id', -1, $dirFlag, false);
        }

        // hook app_Controllers_Admin_UserController_list_center.php

        // 执行“协同文本封装” (Synergy Text Encapsulation)
        if (!empty($dataList)) {
            foreach ($dataList as &$item) {
                $item['ops'] = [];
                // 校验操作权限
                if ($this->groupService->access((int)$currentUser['group_id'], 'user')) {
                    $item['ops']['update'] = [
                        'url' => $this->urlGenerator->url('admin/user/update', ['id' => $item['id']]),
                        'label' => $this->language->get('change'),
                        'class' => 'text-blue-600 hover:text-blue-900 font-bold mr-3'
                    ];

                    // 非管理组允许删除
                    if (!in_array((int)$item['group_id'], [1, 2, 3, 4], true)) {
                        $item['ops']['delete'] = [
                            'url' => $this->urlGenerator->url('admin/user/postDelete', ['id' => $item['id'], '_csrf_token' => $csrfToken]),
                            'label' => $this->language->get('delete'),
                            'class' => 'text-red-600 hover:text-red-900 font-bold ajax-delete'
                        ];
                    }
                }
            }
            unset($item);
        }

        // hook app_Controllers_Admin_UserController_list_middle.php

        $page_link_string = 'admin/user/list';
        $data = [
            'header' => [
                'title' => $this->language->get('user_list'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'user', 'child' => 'user'],
            'csrf_token' => $csrfToken,
            'search' => [
                'searchType' => $searchType,
                'keywords' => $keywords,
                'types' => [
                    'id' => 'id',
                    'username' => $this->language->get('username'),
                    'email' => $this->language->get('email'),
                    'group_id' => $this->language->get('user_group_id'),
                    'createIp' => $this->language->get('create_ip'),
                ]
            ],
            'pagination' => [
                'previous' => ($page > 1 && $firstId > 0) ? [
                    'url' => $this->urlGenerator->url($page_link_string, $extra + ['page' => ($page - 1), 'cursorId' => $firstId, 'dirFlag' => 'previous', 'masterId' => $masterId, 'maxId' => $maxId]),
                    'label' => $this->language->get('previous'),
                ] : null,
                'next' => ($hasMore && $lastId > 0) ? [
                    'url' => $this->urlGenerator->url($page_link_string, $extra + ['page' => ($page + 1), 'cursorId' => $lastId, 'dirFlag' => 'next', 'masterId' => $masterId, 'maxId' => $maxId]),
                    'label' => $this->language->get('next'),
                ] : null,
            ],
            'actions' => [
                'create' => [
                    'url' => $this->urlGenerator->url('admin/user/create'),
                    'label' => $this->language->get('create_user'),
                ],
                'bulk_delete' => [
                    'url' => $this->urlGenerator->url('admin/user/postDelete'),
                    'label' => $this->language->get('delete'),
                    'confirm' => $this->language->get('confirm_batch_delete'),
                ]
            ],
            'item_list' => $dataList,
            'config' => [
                'rewrite' => $this->appConfig['url_rewrite_on'],
                'path' => $this->appConfig['path'],
            ],
            'language' => [
                'user' => $this->language->get('user'),
                'user_group' => $this->language->get('user_group'),
                'confirm_delete' => $this->language->get('confirm_delete'),
                'id' => 'id',
                'created_at' => $this->language->get('created_at'),
                'create_ip' => $this->language->get('create_ip'),
                'operation' => $this->language->get('operation'),
                'search' => $this->language->get('search'),
                'select_all' => $this->language->get('select_all'),
                'please_select_items' => $this->language->get('please_select_items'),
                'deleting' => $this->language->get('deleting'),
                'network_error' => $this->language->get('network_error'),
                'please_enter_keywords' => $this->language->get('please_enter_keywords'),
                'users_on_page' => $this->language->get('users_on_page'),
            ]
        ];

        // hook app_Controllers_Admin_UserController_list_after.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'user_list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 创建用户页面
     */
    public function create(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);

        // hook app_Controllers_Admin_UserController_create_start.php

        $groups = $this->groupService->findCacheList();

        $data = [
            'header' => ['title' => $this->language->get('create_user')],
            'menu' => $this->getAdminMenu(),
            'menu_fixed' => ['parent' => 'user', 'child' => 'user'],
            'csrf_token' => $this->getCsrfToken($user['salt'] ?? ''),
            'breadcrumb' => [
                ['name' => $this->language->get('home_page'), 'url' => $this->urlGenerator->url('admin/panel')],
                ['name' => $this->language->get('user_list'), 'url' => $this->urlGenerator->url('admin/user/list')],
                ['name' => $this->language->get('create_user'), 'url' => ''],
            ],
            'form' => [
                'action' => $this->urlGenerator->url('admin/user/postCreate'),
                'method' => 'POST',
                'submit_label' => $this->language->get('submit'),
            ],
            'result' => ['id' => '', 'username' => '', 'email' => '', 'group_id' => 101],
            'groups' => $groups,
            'is_update' => false,
            'language' => [
                'email' => $this->language->get('email'),
                'username' => $this->language->get('username'),
                'password' => $this->language->get('password'),
                'user_group' => $this->language->get('user_group'),
            ]
        ];

        // hook app_Controllers_Admin_UserController_create_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'user_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 执行创建
     */
    public function postCreate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_UserController_postCreate_start.php

        $email = RequestUtils::param('email', '');
        if (empty($email)) return $this->errorMessage($this->language->get('email_is_empty'), 'email');

        $email = strtolower($email);
        $msg = $this->validateEmail($email);
        if ('success' !== $msg) return $this->errorMessage($msg, 'email');

        if ($this->userService->readByEmail($email)) {
            return $this->errorMessage($this->language->get('email_is_in_use'), 'email');
        }

        $username = RequestUtils::param('username', '');
        if (empty($username)) return $this->errorMessage($this->language->get('username_is_empty'), 'username');

        $msg = $this->validateUsername($username);
        if ('success' !== $msg) return $this->errorMessage($msg, 'username');

        if ($this->userService->readByUsername($username)) {
            return $this->errorMessage($this->language->get('username_is_in_use'), 'username');
        }

        // hook app_Controllers_Admin_UserController_postCreate_center.php

        $password = RequestUtils::param('password', '');
        if (empty($password)) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

        if (strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

        $groupId = RequestUtils::param('group_id', 0);
        $groups = $this->groupService->findCacheList();
        if (!isset($groups[$groupId])) return $this->errorMessage($this->language->get('user_group_does_not_exist'), 7);

        $userData = [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, \PASSWORD_BCRYPT, ['cost' => 12]),
            'salt' => SafeHelper::randomStr(16),
            'group_id' => $groupId,
            'create_ip' => $this->ip,
            'created_at' => time()
        ];

        // hook app_Controllers_Admin_UserController_postCreate_end.php

        $userId = $this->userService->insert($userData);

        // hook app_Controllers_Admin_UserController_postCreate_after.php

        if (empty($userId)) return $this->errorMessage($this->language->get('failed_to_create_user'), -1);

        return $this->successMessage($this->language->get('create_success'), 0, $this->urlGenerator->url('admin/user/list'));
    }

    /**
     * 更新页面
     */
    public function update(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $userId = RequestUtils::param('id', 0);
        if (empty($userId)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'id']), 7);

        // hook app_Controllers_Admin_UserController_update_start.php

        $result = $this->userService->read($userId);
        if (empty($result)) return $this->errorMessage($this->language->get('user_not_exists'), -1);

        $groups = $this->groupService->findCacheList();

        $data = [
            'header' => ['title' => $this->language->get('update_user')],
            'menu' => $this->getAdminMenu(),
            'menu_fixed' => ['parent' => 'user', 'child' => 'user'],
            'csrf_token' => $this->getCsrfToken($user['salt'] ?? ''),
            'breadcrumb' => [
                ['name' => $this->language->get('home_page'), 'url' => $this->urlGenerator->url('admin/panel')],
                ['name' => $this->language->get('user_list'), 'url' => $this->urlGenerator->url('admin/user/list')],
                ['name' => $this->language->get('update_user'), 'url' => ''],
            ],
            'form' => [
                'action' => $this->urlGenerator->url('admin/user/postUpdate', ['id' => $userId]),
                'method' => 'POST',
                'submit_label' => $this->language->get('submit'),
            ],
            'result' => $result,
            'groups' => $groups,
            'is_update' => true,
            'language' => [
                'email' => $this->language->get('email'),
                'username' => $this->language->get('username'),
                'password' => $this->language->get('password'),
                'password_placeholder' => $this->language->get('password_placeholder'),
                'user_group' => $this->language->get('user_group'),
            ]
        ];

        // hook app_Controllers_Admin_UserController_update_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'user_add_post'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 执行更新
     */
    public function postUpdate(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_UserController_postUpdate_start.php

        $userId = RequestUtils::param('id', 0);
        if (empty($userId)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'id']), 7);

        $user = $this->userService->read($userId);
        if (empty($user)) return $this->errorMessage($this->language->get('user_not_exists'), -1);

        $update = [];
        $email = RequestUtils::param('email', '');
        if ($email && $user['email'] !== $email) {
            $msg = $this->validateEmail($email);
            if ('success' !== $msg) return $this->errorMessage($msg, 'email');

            if ($this->userService->readByEmail($email)) {
                return $this->errorMessage($this->language->get('email_is_in_use'), 'email');
            }
            $update['email'] = $email;
        }

        $username = RequestUtils::param('username', '');
        if ($username && $user['username'] !== $username) {
            $msg = $this->validateUsername($username);
            if ('success' !== $msg) return $this->errorMessage($msg, 'username');

            if ($this->userService->readByUsername($username)) {
                return $this->errorMessage($this->language->get('username_is_in_use'), 'username');
            }
            $update['username'] = $username;
        }

        $passwordPlain = RequestUtils::param('password', '');
        if ($passwordPlain) {
            if (strlen($passwordPlain) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

            if (!password_verify($passwordPlain, $user['password'])) {
                $update['password'] = password_hash($passwordPlain, \PASSWORD_BCRYPT, ['cost' => 12]);
                $update['login_version'] = (int)($user['login_version'] ?? 0) + 1;
            }
        }

        $groupId = RequestUtils::param('group_id', 0);
        if ($groupId && (int)$user['group_id'] !== $groupId) {
            $groups = $this->groupService->findCacheList();
            if (!isset($groups[$groupId])) return $this->errorMessage($this->language->get('user_group_does_not_exist'), 7);
            $update['group_id'] = $groupId;
        }

        // hook app_Controllers_Admin_UserController_postUpdate_after.php

        if (!empty($update)) {
            if ($this->userService->update($userId, $update) === 0) {
                return $this->errorMessage($this->language->get('update_failed'), -1);
            }
        }

        // hook app_Controllers_Admin_UserController_postUpdate_end.php

        return $this->successMessage($this->language->get('change_success'), 0, $this->urlGenerator->url('admin/user/list'));
    }

    /**
     * 执行删除
     */
    public function postDelete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // hook app_Controllers_Admin_UserController_postDelete_start.php

        $userIds = RequestUtils::param('user_id', null);

        if (is_array($userIds)) {
            if (empty($userIds)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'user_id']), 7);

            $dataList = $this->userService->find(['id' => $userIds], [], 1, count($userIds), '');

            $validuserIds = [];
            foreach ($dataList as $item) {
                if (!in_array((int)$item['group_id'], [1, 2, 3, 4], true)) {
                    $validuserIds[] = (int)$item['id'];
                }
            }

            if (empty($validuserIds)) return $this->errorMessage($this->language->get('user_groups_cannot_be_deleted'), -1);

            $result = $this->userService->bulkDelete($validuserIds);
        } else {
            $userId = (int)$userIds;
            if (empty($userId)) return $this->errorMessage($this->language->get('parameter_error', ['error' => 'user_id']), 7);

            $user = $this->userService->read($userId);
            if (empty($user)) return $this->errorMessage($this->language->get('user_not_exists'), -1);

            if (in_array((int)$user['group_id'], [1, 2, 3, 4], true)) {
                return $this->errorMessage($this->language->get('user_groups_cannot_be_deleted'), -1);
            }

            $result = $this->userService->delete($userId);

            // hook app_Controllers_Admin_UserController_postDelete_after.php
        }

        // hook app_Controllers_Admin_UserController_postDelete_end.php

        return $this->successMessage($this->language->get('delete_success'), 0, $this->urlGenerator->url('admin/user/list'));
    }

    // hook app_Controllers_Admin_UserController_end.php
}
