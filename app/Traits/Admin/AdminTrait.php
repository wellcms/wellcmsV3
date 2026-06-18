<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Traits\Admin;

trait AdminTrait
{
    public function siteId()
    {
        $appConfig = $this->container->get('appConfig');
        $key = $appConfig['auth_key'] ?? '';
        // SERVER_ADDR 优先从 IpHelper 获取（会回退到 $_SERVER）
        $siteIP = \Framework\Utils\IpHelper::ip();
        return md5($key . $siteIP);
    }

    /**
     * 后台导航栏 TODO 需增加根据权限展示菜单
     * @return array
     */
    public function getAdminMenu()
    {
        //$this->language = $this->container->get(LanguageLoaderInterface::class);
        //$this->urlGenerator = $this->container->get(UrlGeneratorInterface::class);
        // hook app_Trait_AdminTrait_getAdminNavigation_start.php
        $data = [
            'home' => [
                'parent' => 'home',
                'name' => $this->language->get('front_page'),
                'url' => $this->urlGenerator->url('admin/panel'),
                'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                    </path>
                </svg>',
            ],
            'User' => [
                'parent' => 'user',
                'name' => $this->language->get('user_management'),
                'url' => 'javascript:void(0);',
                'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                    </path>
                </svg>',
                'children' => [
                    'UserList' => [
                        'child' => 'user',
                        'name' => $this->language->get('user'),
                        'url' => $this->urlGenerator->url('admin/user/list')
                    ],
                    'GroupList' => [
                        'child' => 'group',
                        'name' => $this->language->get('group'),
                        'url' => $this->urlGenerator->url('admin/group/list')
                    ],
                ]
            ],
            'AppCenter' => [
                'parent' => 'AppCenter',
                'name' => $this->language->get('app_center'),
                'url' => 'javascript:void(0);',
                'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                </svg>',
                'children' => [
                    'PluginList' => [
                        'child' => 'plugin',
                        'name' => $this->language->get('plugin'),
                        'url' => $this->urlGenerator->url('admin/plugin/list')
                    ],
                    'ThemeList' => [
                        'child' => 'theme',
                        'name' => $this->language->get('theme'),
                        'url' => $this->urlGenerator->url('admin/theme/list')
                    ],
                    'StoreList' => [
                        'child' => 'store',
                        'name' => $this->language->get('store'),
                        'url' => $this->urlGenerator->url('admin/store/list')
                    ],
                ]
            ],
            'Task' => [
                'parent' => 'task',
                'name' => $this->language->get('admin_task'),
                'url' => 'javascript:void(0);',
                'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>',
                'children' => [
                    'TaskDashboard' => [
                        'child' => 'dashboard',
                        'name' => $this->language->get('task_dashboard'),
                        'url' => $this->urlGenerator->url('admin/task/dashboard')
                    ],
                    'TaskList' => [
                        'child' => 'list',
                        'name' => $this->language->get('admin_task_list'),
                        'url' => $this->urlGenerator->url('admin/task/list')
                    ]
                ]
            ]
        ];
        // hook app_Trait_AdminTrait_getAdminNavigation_after.php
        $data['Other'] = [
            'parent' => 'other',
            'name' => $this->language->get('other'),
            'url' => 'javascript:void(0);',
            'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z">
                </path>
            </svg>',
            'children' => [
                'OtherClearCache' => [
                    'child' => 'clear',
                    'name' => $this->language->get('cache'),
                    'url' => $this->urlGenerator->url('admin/other/clearCache')
                ],
                'PartitionStatus' => [
                    'child' => 'partition',
                    'name' => $this->language->get('partition_management'),
                    'url' => $this->urlGenerator->url('admin/PartitionStatus')
                ]
            ]
        ];
        // hook app_Trait_AdminTrait_getAdminNavigation_end.php
        $data['Settings'] = [
            'parent' => 'setting',
            'name' => $this->language->get('system_settings'),
            'url' => 'javascript:void(0);',
            'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                </path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>',
            'children' => [
                'SystemBasic' => [
                    'child' => 'base',
                    'name' => $this->language->get('system_basic_settings'),
                    'url' => $this->urlGenerator->url('admin/setting/base')
                ],
                'navigation' => [
                    'child' => 'navigation',
                    'name' => $this->language->get('navigation_settings'),
                    'url' => $this->urlGenerator->url('admin/nav/list')
                ],
                'Smtp' => [
                    'child' => 'smtp',
                    'name' => $this->language->get('system_smtp_settings'),
                    'url' => $this->urlGenerator->url('admin/setting/smtp')
                ]
            ]
        ];
        return $data;
    }
}
