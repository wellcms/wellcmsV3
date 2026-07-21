<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use Framework\Http\Interfaces\ResponseInterface;

class ManageController extends \App\Controllers\Base\BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    // hook app_Controllers_Frontend_ManageController_start.php

    public function dashboard(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_ManageController_dashboard_start.php

        $groupService = $this->container->get(\App\Services\Auth\GroupService::class);

        $navigation = $this->getNavigation();

        // hook app_Controllers_Frontend_ManageController_dashboard_before.php

        $page_link_string = 'manage/dashboard';

        // Quick access links
        $quick_access = [
            /* 'user_list' => [
                'name' => $this->language->get('user_management'),
                'url' => $this->urlGenerator->url('admin/user/list'),
                'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
                'color' => 'bg-blue-100 text-blue-600',
                'desc' => $this->language->get('manage_users_desc'),
            ],
            'setting' => [
                'name' => $this->language->get('site_settings'),
                'url' => $this->urlGenerator->url('admin/setting/base'),
                'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
                'color' => 'bg-emerald-100 text-emerald-600',
                'desc' => $this->language->get('manage_settings_desc'),
            ],
            'plugin' => [
                'name' => $this->language->get('plugin_management'),
                'url' => $this->urlGenerator->url('admin/plugin/list'),
                'icon' => 'M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z',
                'color' => 'bg-purple-100 text-purple-600',
                'desc' => $this->language->get('manage_plugins_desc'),
            ],
            'cache' => [
                'name' => $this->language->get('clear_cache'),
                'url' => $this->urlGenerator->url('admin/other/clearCache'),
                'icon' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
                'color' => 'bg-orange-100 text-orange-600',
                'desc' => $this->language->get('manage_cache_desc'),
            ], */
        ];

        // hook app_Controllers_Frontend_ManageController_dashboard_after.php

        $data = [
            'header' => [
                'title' => $this->language->get('manage_dashboard'),
                'keywords' => $this->language->get('manage_dashboard'),
                'description' => $this->language->get('manage_dashboard'),
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'menu' => $this->myMenu($user),
            'menu_fixed' => ['parent' => 'manage', 'child' => 'home'],
            'quick_access' => $quick_access,
            'urls' => [
                'panel' => ['url' => $this->urlGenerator->url('admin/panel')],
                'user_list' => ['url' => $this->urlGenerator->url('admin/user/list')],
                'setting' => ['url' => $this->urlGenerator->url('admin/setting/base')],
            ],
            'language' => [
                'manage_dashboard' => $this->language->get('manage_dashboard'),
                'manage_brief_introduction' => $this->language->get('manage_brief_introduction'),
                'enter_admin_panel' => $this->language->get('enter_admin_panel'),
                'quick_access' => $this->language->get('quick_access'),
                'system_information' => $this->language->get('system_information'),
                'system_version' => $this->language->get('system_version'),
                'php_version' => $this->language->get('php_version'),
                'server_time' => $this->language->get('server_time'),
                'database_size' => $this->language->get('database_size'),
                'admin_settings' => $this->language->get('admin_settings'),
                'user_management' => $this->language->get('user_management'),
                'site_settings' => $this->language->get('site_settings'),
            ]
        ];

        // hook app_Controllers_Frontend_ManageController_dashboard_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'manage_dashboard'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    // hook app_Controllers_Frontend_ManageController_end.php
}
