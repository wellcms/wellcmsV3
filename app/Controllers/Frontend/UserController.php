<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use App\Controllers\Base\BaseController;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;

class UserController extends BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    // hook app_Controllers_Frontend_UserController_start.php

    public function home(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $extra = [];
        // hook app_Controllers_Frontend_UserController_home_start.php

        $userId = RequestUtils::param(2, 0);
        if (empty($userId)) return $this->errorMessage($this->language->get('data_malformation'), 4);

        // 被访问用户
        $author = $this->userService->readByCache($userId);
        if (empty($author)) return $this->errorMessage($this->language->get('user_not_exists'), 4);

        $author = $this->userService->safe($author, 0);
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_UserController_home_before.php

        $page_link_string = 'user/home/' . $userId; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('user_home'),
                'keywords' => $this->language->get('user_home'),
                'description' => $this->language->get('user_home'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'author' => $author,
            'is_owner' => ($this->userService->getCurrentUser()['id'] ?? 0) === (int)$userId,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'user_navigation' => empty($this->navigation()) ? '' : $this->navigation(),
            'thread_list' => [], // 预留空数组，插件可通过 Hook 安全注入
            'post_list' => [],   // 预留空数组，插件可通过 Hook 安全注入
            'language' => [
                'author_group' => $this->language->get('my_user_group', ['group' => $author['groupname']]),
                'user_information' => $this->language->get('user_information'),
                'created_at_format' => $this->language->get('created_at_format', ['date' => $author['created_at_fmt']]),
                'last_sign_in' => $this->language->get('last_sign_in', ['time' => $author['login_date_fmt']]),
                'follow' => $this->language->get('follow'),
                'send_message' => $this->language->get('send_message'),
                'edit_profile' => $this->language->get('edit_profile'),
                'credits' => $this->language->get('credits'),
                'golds' => $this->language->get('golds'),
                'visits' => $this->language->get('visits'),
                'uploads' => $this->language->get('uploads'),
                'activity_feed' => $this->language->get('activity_feed'),
                'no_activity_available' => $this->language->get('no_activity_available'),
                'author_info' => $this->language->get('author_info'),
                'joined_at' => $this->language->get('joined_at'),
                'last_active_at' => $this->language->get('last_active_at'),
                'user_id' => $this->language->get('user_id'),
                'create_ip' => $this->language->get('create_ip'),
                'last_login_ip' => $this->language->get('last_login_ip'),
                'total_logs' => $this->language->get('total_logs'),
                'no_bio' => $this->language->get('no_bio'),
                'view_details' => $this->language->get('view_details'),
            ],
        ];

        // hook app_Controllers_Frontend_UserController_home_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'user_home'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function navigation()
    {
        // hook app_Controllers_Frontend_UserController_navigation_start.php
        $array = [
            /* 'home' => [
                'name' => $this->language->get('my_home'),
                'url' => $this->urlGenerator->url('my/home'),
            ],
            'profiles' => [
                'name' => 'profiles',
                'sub' => [
                    'avatar' => ['name' => 'Avatar', 'url' => $this->appConfig['path']],
                    'email' => ['name' => 'Email', 'url' => $this->appConfig['path']],
                    'password' => ['name' => 'Password', 'url' => $this->appConfig['path']],
                ]
            ] */];
        // hook app_Controllers_Frontend_UserController_navigation_end.php
        return $array;
    }

    // hook app_Controllers_Frontend_UserController_end.php
}
