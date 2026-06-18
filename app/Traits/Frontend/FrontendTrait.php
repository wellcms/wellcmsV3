<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Traits\Frontend;

trait FrontendTrait
{
    /**
     * 个人中心菜单项（由钩子注入，显式声明以避免 PHP 8.2+ 动态属性弃用）
     * @var array
     */
    public $myMenu = [];

    /**
     * 获取导航栏
     * @return array
     */
    public function getNavigation()
    {
        // hook app_Trait_Frontend_FrontendTrait_start.php
        $navigationList = $this->container->get(\App\Services\Content\NavigationService::class)->findCache();

        $primary = [];
        $secondary = [];
        foreach ($navigationList as $nav) {
            if (0 === (int)$nav['type']) {
                $primary[$nav['id']] = $nav;
                $primary[$nav['id']]['sublist'] = [];
            } else {
                $secondary[] = $nav;
            }
        }

        foreach ($secondary as $nav) {
            if (isset($primary[$nav['parent_id']])) {
                $primary[$nav['parent_id']]['sublist'][] = $nav;
            }
        }

        // hook app_Trait_Frontend_FrontendTrait_end.php
        return $primary;
    }

    /**
     * 个人中心导航菜单
     */
    public function myMenu(array $user)
    {
        $groupService = $this->container->get(\App\Services\Auth\GroupService::class);
        $userGroupId = (int)($user['group_id'] ?? 0);
        // hook app_Trait_Frontend_FrontendTrait_myMenu_start.php
        $data = [
            'home' => [
                'parent' => 'home',
                'name' => $this->language->get('home_page'),
                'url' => $this->urlGenerator->url('/'),
                'original_url' => $this->urlGenerator->url('/'),
                'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                </path>
                </svg>',
            ],
            'my' => [
                'parent' => 'my',
                'name' => $this->language->get('my_home'),
                'url' => 'javascript:void(0);',
                'original_url' => $this->urlGenerator->url('my/home'),
                'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                </path>
                </svg>',
                'children' => [
                    'home' => [
                        'child' => 'home',
                        'name' => $this->language->get('overview'),
                        'url' => $this->urlGenerator->url('my/home')
                    ],
                ]
            ],
        ];

        // 是否有管理权限（delete 或 review 均可见管理菜单）
        ($groupService->access($userGroupId, 'delete') || $groupService->access($userGroupId, 'review')) && $data['manage'] = [
            'parent' => 'manage',
            'name' => $this->language->get('management_center'),
            'url' => 'javascript:void(0);',
            'original_url' => $this->urlGenerator->url('manage/dashboard'),
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
            </path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z">
            </path>
            </svg>',
            'children' => []
        ];

        // 功能相关使用此钩子
        // hook app_Trait_Frontend_FrontendTrait_myMenu_before.php

        /* $data['profiles'] = [
            'parent' => 'profiles',
            'name' => $this->language->get('basic_info'),
            'url' => 'javascript:void(0);',
            'children' => [
                'avatar' => [
                    'child' => 'avatar',
                    'name' => $this->language->get('avatar'),
                    'url' => $this->urlGenerator->url('my/avatar')
                ],
                'password' => [
                    'child' => 'password',
                    'name' => $this->language->get('password'),
                    'url' => $this->urlGenerator->url('my/password')
                ],
            ]
        ]; */

        $data['profiles'] = [
            'parent' => 'profiles',
            'name' => $this->language->get('basic_info'),
            'url' => 'javascript:void(0);',
            'original_url' => $this->urlGenerator->url('my/avatar'),
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>',
            'children' => [
                'avatar' => [
                    'child' => 'avatar',
                    'name' => $this->language->get('avatar'),
                    'url' => $this->urlGenerator->url('my/avatar')
                ],
                'password' => [
                    'child' => 'password',
                    'name' => $this->language->get('password'),
                    'url' => $this->urlGenerator->url('my/password')
                ],
            ]
        ];

        // 是否有修改用户名权限
        $groupService->access($userGroupId, 'change_username') && $data['profiles']['children']['username'] = [
            'child' => 'username',
            'name' => $this->language->get('username'),
            'url' => $this->urlGenerator->url('my/username'),
        ];

        // 是否有修改邮箱权限
        $groupService->access($userGroupId, 'email_update') && $data['profiles']['children']['email'] = [
            'child' => 'email',
            'name' => $this->language->get('email'),
            'url' => $this->urlGenerator->url('my/email'),
        ];

        // 个人资料相关使用此钩子
        // hook app_Trait_Frontend_FrontendTrait_myMenu_end.php

        return $data;
    }

    /**
     * 获取面包屑导航
     * @param int $page_link_string
     */
    public function getBreadcrumb($page_link_string, array $extra = []): array{
        return [
            'home' => [
                'name' => $this->language->get('home_page'),
                'url' => '/'
            ],
            'list' => [
                'name' => $this->language->get('my_home'),
                'url' => $this->urlGenerator->url('my/home')
            ],
            'title' => [
                'name' => $this->language->get('page_title'),
                'url' => $this->urlGenerator->url($page_link_string, $extra)
            ]
        ];
    }
}
