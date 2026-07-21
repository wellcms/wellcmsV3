<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;

class MyController extends \App\Controllers\Base\BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    // hook app_Controllers_Frontend_MyController_start.php

    public function home(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_MyController_home_start.php

        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_MyController_home_before.php

        $page_link_string = 'my/home'; // 当前页链接字符串

        // Default overview statistics
        $overview = [
            'credits' => [
                'name' => $this->language->get('credits'),
                'value' => $user['credits'] ?? 0,
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                'color' => 'text-yellow-500 bg-yellow-50 dark:bg-yellow-900/20'
            ],
            'golds' => [
                'name' => $this->language->get('golds'),
                'value' => $user['golds'] ?? 0,
                'icon' => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-2.066 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946 2.066 3.42 3.42 0 010 4.606 3.42 3.42 0 00-1.946 2.066 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-2.066 3.42 3.42 0 010-4.606z',
                'color' => 'text-amber-500 bg-amber-50 dark:bg-amber-900/20'
            ],
        ];

        // hook app_Controllers_Frontend_MyController_home_after.php

        $data = [
            'header' => [
                'title' => $this->language->get('my_home'),
                'keywords' => $this->language->get('my_home'),
                'description' => $this->language->get('my_home'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'modify_basic_link' => $this->urlGenerator->url('my/avatar'),
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'my', 'child' => 'home'],
            'overview' => $overview,
            'urls' => [
                'avatar' => ['url' => $this->urlGenerator->url('my/avatar')],
                'password' => ['url' => $this->urlGenerator->url('my/password')],
                'email' => ['url' => $this->urlGenerator->url('my/email')],
            ],
            'language' => [
                'sign_in_to' => $this->language->get('sign_in_to'),
                'activity_feed' => $this->language->get('activity_feed'),
                'no_activity_available' => $this->language->get('no_activity_available'),
                'user_information' => $this->language->get('user_information'),
                'user_id' => $this->language->get('user_id'),
                'user_group' => $this->language->get('user_group'),
                'last_signin' => $this->language->get('last_signin'),
                'login_ip' => $this->language->get('sign_in_ip', ['ip' => $user['login_ip'] ?? '']),
                'change_password' => $this->language->get('change_password'),
                'change_email' => $this->language->get('change_email'),
                'user_home' => $this->language->get('user_home')
            ]
        ];

        // hook app_Controllers_Frontend_MyController_home_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'my_home'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function avatar(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_MyController_avatar_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        // hook app_Controllers_Frontend_MyController_avatar_before.php

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_MyController_avatar_after.php

        $page_link_string = 'my/avatar'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('modify_avatar'),
                'keywords' => $this->language->get('modify_avatar'),
                'description' => $this->language->get('modify_avatar'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('my_home'),
                    'url' => $this->urlGenerator->url('my/home')
                ],
                'title' => [
                    'name' => $this->language->get('modify_avatar'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('my/postAvatar'),
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'profiles', 'child' => 'avatar'],
            'language' => [
                'title' => $this->language->get('my_profile'),
                'modify_avatar'  => $this->language->get('modify_avatar'),
                'user_home' => $this->language->get('user_home'),
                'avatar_drag_drop_click_upload' => $this->language->get('avatar_drag_drop_click_upload'),
                'avatar_file_types' => $this->language->get('avatar_file_types'),
            ],
        ];

        // hook app_Controllers_Frontend_MyController_avatar_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'my_avatar'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function postAvatar(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        if (empty($user['id'])) {
            return $this->errorMessage($this->language->get('user_not_logged_in'), 1);
        }

        // hook app_Controllers_Frontend_MyController_postAvatar_start.php

        $files = $request->getUploadedFiles();
        $file = $files['avatar'] ?? null;

        if (!$file instanceof \Framework\Http\Interfaces\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) return $this->errorMessage($this->language->get('upload_failed'), 1);

        // 前置校验：扩展名白名单与文件大小（服务层仍会强制重编码为 PNG）
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $clientName = $file->getClientFilename();
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return $this->errorMessage($this->language->get('upload_failed'), 1);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->errorMessage($this->language->get('upload_failed'), 1);
        }

        try {
            // 业务逻辑已下沉至 $this->userService->updateAvatar
            $url = $this->userService->updateAvatar((int)$user['id'], $file);
        } catch (\Framework\Exception\BusinessException $e) {
            return $this->errorMessage($e->getMessage(), $e->getCode() ?: 1);
        } catch (\Exception $e) {
            return $this->errorMessage($this->language->get('upload_failed'), 1);
        }

        // hook app_Controllers_Frontend_MyController_postAvatar_end.php

        return $this->successMessage($this->language->get('update_success'), 0, $url);
    }

    public function username(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_MyController_username_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_MyController_username_before.php

        $page_link_string = 'my/username'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('change_username'),
                'keywords' => $this->language->get('my_profile'),
                'description' => $this->language->get('my_profile'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('my_home'),
                    'url' => $this->urlGenerator->url('my/home')
                ],
                'title' => [
                    'name' => $this->language->get('change_username'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('my/postUsername'),
            'modify_basic_link' => $this->urlGenerator->url('my/basic'),
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'profiles', 'child' => 'username'],
            'language' => [
                'title' => $this->language->get('my_profile'),
                'change_username' => $this->language->get('change_username'),
                'submit' => $this->language->get('submit'),
                'user_home' => $this->language->get('user_home'),
            ],
        ];

        // hook app_Controllers_Frontend_MyController_username_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'my_username'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function postUsername(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        if (empty($user['id'])) return $this->errorMessage($this->language->get('user_not_logged_in'), 1);

        // hook app_Controllers_Frontend_MyController_postUsername_start.php

        $username = RequestUtils::param('username', '');
        if (!$username) return $this->errorMessage($this->language->get('username_is_empty'), 'username');

        $msg = $this->validateUsername($username);
        if ($msg !== 'success') return $this->errorMessage($msg, 'username');

        // 原子锁：防止并发下重复校验通过
        $lockKey = 'lock:user:update:name:' . md5($username);
        $token = $this->container->get(\Framework\Cache\Interfaces\CacheInterface::class)->lock($lockKey, 5);
        if (!$token) return $this->errorMessage($this->language->get('action_too_frequent'), -1);

        try {
            $result = $this->userService->readByUsername($username);
            if (!empty($result)) return $this->errorMessage($this->language->get('username_is_in_use'), 'username');

            // hook app_Controllers_Frontend_MyController_postUsername_before.php

            $result = $this->userService->update((int)$user['id'], ['username' => $username]);
            if ($result === 0) return $this->errorMessage($this->language->get('modify_failed'), -1);
        } finally {
            $this->container->get(\Framework\Cache\Interfaces\CacheInterface::class)->unlock($lockKey, $token);
        }

        // hook app_Controllers_Frontend_MyController_postUsername_end.php

        return $this->successMessage($this->language->get('change_success'), 0, $this->urlGenerator->url('my/home'), 3);
    }

    public function password(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_MyController_password_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_MyController_password_before.php

        $page_link_string = 'my/password'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('change_password'),
                'keywords' => $this->language->get('my_profile'),
                'description' => $this->language->get('my_profile'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('my_home'),
                    'url' => $this->urlGenerator->url('my/home')
                ],
                'title' => [
                    'name' => $this->language->get('change_password'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('my/postPassword'),
            'modify_basic_link' => $this->urlGenerator->url('my/basic'),
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'profiles', 'child' => 'password'],
            'language' => [
                'title' => $this->language->get('my_profile'),
                'original_password' => $this->language->get('original_password'),
                'new_password' => $this->language->get('new_password'),
                'repeat_new_password' => $this->language->get('repeat_new_password'),
                'submit' => $this->language->get('submit'),
                'user_home' => $this->language->get('user_home'),
            ],
        ];

        // hook app_Controllers_Frontend_MyController_password_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'my_password'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function postPassword(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        if (empty($user['id'])) {
            return $this->errorMessage($this->language->get('user_not_logged_in'), 1);
        }

        $user = $this->userService->read((int)$user['id']);

        // hook app_Controllers_Frontend_MyController_postPassword_start.php

        $originalPassword = RequestUtils::param('originalPassword', '');
        if (!$originalPassword) return $this->errorMessage($this->language->get('password_is_empty'), 'originalPassword');

        if (false === password_verify($originalPassword, $user['password'])) return $this->errorMessage($this->language->get('original_password_incorrect'), 'originalPassword');

        $password = RequestUtils::param('password', '');
        if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

        if (strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

        //$result = SafeHelper::isValidPassword($password);
        //if (false === $result) return $this->errorMessage($this->language->get('password_format'), 'password');

        $repeatPassword = RequestUtils::param('repeatPassword', '');
        if (!$repeatPassword) return $this->errorMessage($this->language->get('password_is_empty'), 'repeatPassword');

        if ($password !== $repeatPassword) return $this->errorMessage($this->language->get('repeat_password_incorrect'), 'repeatPassword');

        // hook app_Controllers_Frontend_MyController_postPassword_before.php

        $password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $result = $this->userService->update((int)$user['id'], ['password' => $password, 'login_version' => (int)$user['login_version'] + 1]);
        if ($result === 0) return $this->errorMessage($this->language->get('modify_failed'), -1);

        // hook app_Controllers_Frontend_MyController_postPassword_end.php

        return $this->successMessage($this->language->get('change_success'), 0, $this->urlGenerator->url('my/home'), 3);
    }

    public function email(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_MyController_email_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        $originalEmail = '';
        $email = RequestUtils::param('email', '');
        if ($email) {
            $originalEmail = strtolower($email);

            $msg = $this->validateEmail($email);
            if ($msg !== 'success') return $this->errorMessage($msg, 'email');

            $code = RequestUtils::param('code', '');
            if (!$code) return $this->errorMessage($this->language->get('verification_code_is_empty'), 6);

            $sessionData = $this->userSession->get('originalEmail', []);
            $sess_email = $sessionData['email'] ?? '';
            $sess_email && $sess_email = strtolower($sess_email);
            $sess_code = $sessionData['code'] ?? '';
            if (!$sess_code || !$sess_email || $originalEmail != $sess_email || strtoupper($code) != $sess_code) return $this->errorMessage($this->language->get('comparison_data_error'), 8);

            if ($originalEmail !== $user['email']) return $this->errorMessage($this->language->get('original_email_error'), 8);
        }

        // hook app_Controllers_Frontend_MyController_email_before.php

        // 获取导航栏信息
        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_MyController_email_after.php

        $page_link_string = 'my/email'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('change_email'),
                'keywords' => $this->language->get('my_profile'),
                'description' => $this->language->get('my_profile'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('my_home'),
                    'url' => $this->urlGenerator->url('my/home')
                ],
                'title' => [
                    'name' => $this->language->get('change_email'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'email' => $email ?? '',
            'action' => $this->urlGenerator->url('my/postEmail'),
            'modify_basic_link' => $this->urlGenerator->url('my/basic'),
            'verify_code_link' => $this->urlGenerator->url('auth/postVerifyCode', ['verifyKey' => 'verifyEmail']),
            'originalEmail' => $originalEmail,
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'profiles', 'child' => 'email'],
            'language' => [
                'title' => $this->language->get('my_profile'),
                'email' => $this->language->get('email'),
                'modify_email' => $this->language->get('modify_email'),
                'original_email' => $this->language->get('original_email'),
                'new_email' => $this->language->get('new_email'),
                'verification_code' => $this->language->get('enter_the_verification_code'),
                'send_verification_code' => $this->language->get('send_verification_code'),
                'send_success' => $this->language->get('send_success'),
                'submit' => $this->language->get('submit'),
                'user_home' => $this->language->get('user_home'),

            ]
        ];

        // hook app_Controllers_Frontend_MyController_email_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'my_email'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    public function postEmail(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        if (empty($user['id'])) {
            return $this->errorMessage($this->language->get('user_not_logged_in'), 1);
        }

        // hook app_Controllers_Frontend_MyController_postEmail_start.php

        $originalEmail = RequestUtils::param('originalEmail', '');
        $msg = $this->validateEmail($originalEmail);
        if ($msg !== 'success') return $this->errorMessage($msg, 'email');

        if ($originalEmail !== $user['email']) return $this->errorMessage($this->language->get('original_email_error'), 8);

        $sessionData = $this->userSession->get('originalEmail', []);
        $sess_email = $sessionData['email'] ?? '';
        $sess_email && $sess_email = strtolower($sess_email);
        if ($originalEmail != $sess_email) return $this->errorMessage($this->language->get('comparison_data_error'), 8);

        $newEmail = RequestUtils::param('email', '');
        $msg = $this->validateEmail($newEmail);
        if ($msg !== 'success') return $this->errorMessage($msg, 'email');

        $code = RequestUtils::param('code', '');
        if (!$code) return $this->errorMessage($this->language->get('verification_code_is_empty'), 6);

        // 检查邮箱是否被使用
        $result = $this->userService->readByEmail($newEmail);
        if (!empty($result)) return $this->errorMessage($this->language->get('email_is_in_use'), 8);

        // hook app_Controllers_Frontend_MyController_postEmail_before.php

        // 比对数据
        $sessionData = $this->userSession->get('modifyEmail', []);
        $sess_email = $sessionData['email'] ?? '';
        $sess_email && $sess_email = strtolower($sess_email);
        $sess_code = $sessionData['code'] ?? '';
        if (!$sess_code || !$sess_email || $newEmail != $sess_email || strtoupper($code) != $sess_code) return $this->errorMessage($this->language->get('comparison_data_error'), 8);

        // hook app_Controllers_Frontend_MyController_postEmail_after.php

        $result = $this->userService->update((int)$user['id'], ['email' => $newEmail]);
        if ($result === 0) return $this->errorMessage($this->language->get('modify_failed'), -1);

        // hook app_Controllers_Frontend_MyController_postEmail_end.php

        $this->userSession->delete('originalEmail');
        $this->userSession->delete('modifyEmail');

        return $this->successMessage($this->language->get('change_success'), 0, $this->urlGenerator->url('my/email'), 3);
    }

    // hook app_Controllers_Frontend_MyController_end.php
}
