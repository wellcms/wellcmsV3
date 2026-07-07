<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

use App\Controllers\Admin\{GroupController, IndexController as AdminIndex, NavController, OtherController, PartitionController, PluginController, SettingController, StoreController, TaskController as AdminTaskController, ThemeController, UserController as AdminUser};
use App\Controllers\Api\LinkPreviewController;
use App\Controllers\Frontend\{AuthController, ErrorController, ExternalLinkRedirectController, IndexController, ManageController, MyController, TaskController as FrontendTaskController, UploadController, UserController};
use Framework\Http\Router\Router;

// hook app_Routes_Routes_start.php

// 1. 公共路由 (Public)
Router::get('/', [IndexController::class, 'index'], ['layout' => 'index']);
Router::get('/403', [ErrorController::class, 'error403'], ['layout' => '403']);
Router::get('/404', [ErrorController::class, 'error404'], ['layout' => '404']);
Router::get('/500', [ErrorController::class, 'error500'], ['layout' => '500']);
Router::get('/download/{id}', [UploadController::class, 'download']);

// 外链安全跳转提示页（公开 GET，不附加登录或 CSRF 限制）
Router::get('/redirect/external', [ExternalLinkRedirectController::class, 'external'], [
    'layout' => 'external_link_redirect',
]);

// hook app_Routes_Routes_before.php

// 2. 个人中心 (My Group)
Router::group(['prefix' => '/my'], function () {
    // 需要登录验证的 GET 页面
    Router::group(['meta' => ['requiresAuth' => true]], function () {
        Router::get('/home', [MyController::class, 'home'], ['layout' => 'my_home']);
        Router::get('/avatar', [MyController::class, 'avatar'], ['layout' => 'my_avatar']);
        Router::get('/password', [MyController::class, 'password'], ['layout' => 'my_password']);
        Router::get('/username', [MyController::class, 'username'], ['layout' => 'my_username']);
        Router::get('/email', [MyController::class, 'email'], ['layout' => 'my_email']);
        Router::get('/upload', [UploadController::class, 'list'], ['layout' => 'upload_list']);
    });

    // 需要 CSRF 验证的 POST 操作
    Router::group(['meta' => ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]], function () {
        Router::post('/postAvatar', [MyController::class, 'postAvatar']);
        Router::post('/postPassword', [MyController::class, 'postPassword']);
        Router::post('/postUsername', [MyController::class, 'postUsername']);
    });

    Router::post('/postEmail', [MyController::class, 'postEmail'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
});

// hook app_Routes_manage_before.php

// 管理仪表盘
Router::group(['prefix' => '/manage', 'meta' => ['requiresAuth' => true],
'requiresUserPerm' => ['enable' => true, 'role' => ['delete']]], function () {
    Router::get('/dashboard', [ManageController::class, 'dashboard'], ['layout' => 'manage_dashboard']);
});

// hook app_Routes_manage_after.php

// 3. 认证相关 (Auth Group)
Router::group(['prefix' => '/auth'], function () {
    Router::get('/signUp', [AuthController::class, 'signUp'], ['layout' => 'sign_up']);
    Router::get('/signIn', [AuthController::class, 'signIn'], ['layout' => 'sign_in']);
    Router::get('/logout', [AuthController::class, 'logout'], ['requiresAuth' => true]);
    Router::get('/resetPassword', [AuthController::class, 'resetPassword'], ['layout' => 'reset_password']);
    Router::get('/synSignIn', [AuthController::class, 'synSignIn']);

    // 提交操作 (带 Token 验证)
    Router::group(['meta' => ['requiresToken' => ['enable' => true, 'ttl' => 600]]], function () {
        Router::post('/postSignUp', [AuthController::class, 'postSignUp']);
        Router::post('/postSignIn', [AuthController::class, 'postSignIn'], ['name' => 'auth_sign_in']);
        Router::post('/postResetVerify', [AuthController::class, 'postResetVerify']);
        Router::post('/postResetPassword', [AuthController::class, 'postResetPassword']);
        Router::post('/postVerifyCode', [AuthController::class, 'postVerifyCode'], ['requiresToken' => ['enable' => true, 'ttl' => 600]]);
    });
});

// hook app_Routes_Routes_center.php

// 4. 用户前台操作 (User Group)
Router::group(['prefix' => '/user'], function () {
    Router::get('/home/{userId}', [UserController::class, 'home'], ['layout' => 'user_home']);
});

// hook app_Routes_Routes_middle.php

// 5. 后台管理 (Admin Group)
Router::group(['prefix' => '/admin'], function () {

    // === 后台登录入口 (不需要 AdminSignIn 权限，但需要 Auth 和 UserPerm) ===
    Router::get('/signIn', [AdminIndex::class, 'signIn'], [
        'layout' => 'signIn',
        'requiresAuth' => true,
        'requiresUserPerm' => ['enable' => true, 'role' => ['administer']]
    ]);

    Router::post('/postSignIn', [AdminIndex::class, 'postSignIn'], [
        'requiresAuth' => true,
        'requiresCsrf' => ['enable' => true, 'ttl' => 600],
        'requiresUserPerm' => ['enable' => true, 'role' => ['administer']]
    ]);

    // === 已登录后台区域 ===
    Router::group(['meta' => ['requiresAdminSignIn' => true]], function () {

        // 登出与基础信息
        Router::get('/logout', [AdminIndex::class, 'logout'], ['requiresUserPerm' => ['enable' => true, 'role' => ['administer']]]);
        Router::get('/systemInfo', [AdminIndex::class, 'systemInfo'], ['requiresUserPerm' => ['enable' => true, 'role' => ['administer']]]);
        Router::get('/panel', [AdminIndex::class, 'panel'], [
            'layout' => 'panel',
            'requiresUserPerm' => ['enable' => true, 'role' => ['administer']]
        ]);

        // 导航管理 (Nav)
        Router::group([
            'prefix' => '/nav',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'setting']]]
        ], function () {
            Router::get('/list', [NavController::class, 'list'], ['layout' => 'nav_list']);
            Router::get('/create', [NavController::class, 'create'], ['layout' => 'nav_add_post']);
            Router::get('/update', [NavController::class, 'update'], ['layout' => 'nav_add_post']);

            Router::group(['meta' => ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]], function () {
                Router::post('/postCreate', [NavController::class, 'postCreate']);
                Router::post('/postUpdate', [NavController::class, 'postUpdate']);
            });
            Router::post('/postDelete', [NavController::class, 'postDelete'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
        });

        // 用户管理 (User)
        Router::group(['prefix' => '/user'], function () {
            Router::get('/list', [AdminUser::class, 'list'], [
                'layout' => 'user_list',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user']]
            ]);

            // 创建
            Router::get('/create', [AdminUser::class, 'create'], [
                'layout' => 'user_add_post',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user', 'create_user']]
            ]);
            Router::post('/postCreate', [AdminUser::class, 'postCreate'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user', 'create_user']]
            ]);

            // 更新
            Router::get('/update', [AdminUser::class, 'update'], [
                'layout' => 'user_add_post',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user', 'update_user']]
            ]);
            Router::post('/postUpdate', [AdminUser::class, 'postUpdate'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user', 'update_user']]
            ]);

            // 删除
            Router::post('/postDelete', [AdminUser::class, 'postDelete'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'user', 'delete_user']]
            ]);
        });

        // 用户组管理 (Group)
        Router::group(['prefix' => '/group'], function () {
            Router::get('/list', [GroupController::class, 'list'], [
                'layout' => 'group_list',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group']]
            ]);

            // 创建
            Router::get('/create', [GroupController::class, 'create'], [
                'layout' => 'group_add_post',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group', 'create_group']]
            ]);
            Router::post('/postCreate', [GroupController::class, 'postCreate'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group', 'create_group']]
            ]);

            // 更新
            Router::get('/update', [GroupController::class, 'update'], [
                'layout' => 'group_add_post',
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group', 'update_group']]
            ]);
            Router::post('/postUpdate', [GroupController::class, 'postUpdate'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group', 'update_group']]
            ]);

            // 删除
            Router::post('/postDelete', [GroupController::class, 'postDelete'], [
                'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
                'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'group', 'delete_group']]
            ]);
        });

        // 插件管理 (Plugin)
        Router::group([
            'prefix' => '/plugin',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'plugin']]]
        ], function () {
            Router::get('/list', [PluginController::class, 'list'], ['layout' => 'plugin_list']);
            Router::get('/detail', [PluginController::class, 'detail'], ['layout' => 'plugin_detail']);
            Router::post('/postInstall', [PluginController::class, 'install'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/postUninstall', [PluginController::class, 'uninstall'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/postEnable', [PluginController::class, 'enable'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/postDisable', [PluginController::class, 'disable'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::get('/setting', [PluginController::class, 'setting'], ['layout' => 'setting']);
            Router::post('/postSetting', [PluginController::class, 'postSetting'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
        });

        // 主题管理 (Theme)
        Router::group([
            'prefix' => '/theme',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'theme']]]
        ], function () {
            Router::get('/list', [ThemeController::class, 'list'], ['layout' => 'theme_list']);
            Router::get('/detail', [ThemeController::class, 'detail'], ['layout' => 'plugin_detail']);
            Router::post('/postInstall', [ThemeController::class, 'install'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/postUninstall', [ThemeController::class, 'uninstall'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::get('/setting', [ThemeController::class, 'setting'], ['layout' => 'setting']);
            Router::get('/postSetting', [ThemeController::class, 'postSetting'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
        });

        // 商店管理 (Store)
        Router::group([
            'prefix' => '/store',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'store']]]
        ], function () {
            Router::get('/list', [StoreController::class, 'list'], ['layout' => 'store_list']);
            Router::get('/detail', [StoreController::class, 'detail'], ['layout' => 'plugin_detail']);
            Router::get('/payment', [StoreController::class, 'payment'], ['layout' => 'store_payment']);
            Router::get('/paymentDialog', [StoreController::class, 'paymentDialog']);
            Router::get('/download', [StoreController::class, 'download']);
            Router::get('/upgrade', [StoreController::class, 'upgrade']);

            Router::post('/postPayment', [StoreController::class, 'postPayment'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
            Router::post('/bought', [StoreController::class, 'bought'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
            Router::post('/signin', [StoreController::class, 'signin'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/signout', [StoreController::class, 'signout'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
            Router::post('/postSync', [StoreController::class, 'postSync'], ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
        });

        // 任务管理 (Task)
        Router::group([
            'prefix' => '/task',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'task']]]
        ], function () {
            Router::get('/dashboard', [AdminTaskController::class, 'dashboard'], ['layout' => 'task_dashboard']);
            Router::get('/list', [AdminTaskController::class, 'list'], ['layout' => 'task_list']);
            Router::get('/failed', [AdminTaskController::class, 'failed'], ['layout' => 'task_failed']);
            Router::get('/details', [AdminTaskController::class, 'details'], ['layout' => 'task_details']);

            Router::group(['meta' => ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]], function () {
                Router::post('/cancel', [AdminTaskController::class, 'cancel']);
                Router::post('/retry', [AdminTaskController::class, 'retry']);
                Router::post('/requeue', [AdminTaskController::class, 'requeue']);
                Router::post('/updatePriority', [AdminTaskController::class, 'updatePriority']);
            });
            Router::get('/logs', [AdminTaskController::class, 'logs'], ['layout' => 'task_logs']);
        });

        // 系统设置 (Setting)
        Router::group([
            'prefix' => '/setting',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'setting']]]
        ], function () {
            Router::get('/base', [SettingController::class, 'base'], ['layout' => 'setting_base']);
            Router::get('/smtp', [SettingController::class, 'smtp'], ['layout' => 'setting_smtp']);
            Router::get('/smtpOperation', [SettingController::class, 'smtpOperation'], ['layout' => 'setting_smtp_operation']);

            Router::post('/postBase', [SettingController::class, 'postBase'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
            Router::post('/postSmtp', [SettingController::class, 'postSmtp'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
            Router::post('/postSmtpDelete', [SettingController::class, 'postSmtpDelete'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
        });

        // 其他/缓存清理 (Other)
        Router::group([
            'prefix' => '/other',
            'meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'other']]]
        ], function () {
            Router::get('/clearCache', [OtherController::class, 'clearCache'], ['layout' => 'other_clear']);
            Router::post('/postClearCache', [OtherController::class, 'postClearCache'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
        });

        // 分区管理 (Partition)
        Router::get('/PartitionStatus', [PartitionController::class, 'index'], [
            'layout' => 'partition_status',
            'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'setting']]
        ]);
        Router::post('/PostPartitionMaintain', [PartitionController::class, 'postMaintain'], [
            'requiresCsrf' => ['enable' => true, 'ttl' => 600],
            'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'setting']]
        ]);

        // 升级检查与处理
        Router::group(['meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer']]]], function () {
            Router::get('/check/upgrade', [AdminIndex::class, 'checkUpgrade'], ['layout' => 'upgrade_check']);
            Router::get('/process/upgrade', [AdminIndex::class, 'processUpgrade']);
            Router::get('/upgrade/success', [AdminIndex::class, 'upgradeSuccess'], ['layout' => 'upgrade_success']);
        });

        // 临时文件清理 (Temp Cleanup)
        Router::get('/TempCleanup', [\App\Controllers\Admin\UploadController::class, 'index'], [
            'layout' => 'temp_cleanup',
        ]);
        Router::post('/TempCleanup', [\App\Controllers\Admin\UploadController::class, 'tempCleanup'], [
            'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
        ]);
    });
});

// hook app_Routes_Routes_after.php

// 6. 上传动作 (Upload Group)
Router::group(['prefix' => '/upload', 'meta' => ['requiresAuth' => true]], function () {
    Router::get('/', [UploadController::class, 'handle']);
    Router::post('/', [UploadController::class, 'handle']);

    // 仅限管理精细控制的动作 (如果不想在 handle 里写死权限)
    Router::group(['meta' => ['requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'upload']]]], function () {
        Router::post('/delete', [UploadController::class, 'delete']);
        Router::post('/batchDelete', [UploadController::class, 'batchDelete']);
        Router::post('/review', [UploadController::class, 'review']);
    });
});

// hook app_Routes_Routes_end.php

// 7. API 路由 (API Group)
Router::group(['prefix' => '/api', 'meta' => ['api' => true]], function () {
    // 链接预览（需登录）
    Router::get('/linkPreview', [LinkPreviewController::class, 'fetch'], [
        'requiresAuth' => true,
        'api' => true,
        'api_rate_limit' => [
            'enable' => true,
            'session' => ['limit' => 30, 'window' => 60],
        ],
    ]);
});

// hook app_Routes_Api_end.php

// 返回生成的路由数组
return Router::getRoutes();

/* // 1. 单个路由 (无分组)

// 最终路径: /example-page
Router::get('/example-page', [HomeController::class, 'index'], ['layout' => 'index']);

// 2. 插入到“后台组” (Admin Group)
// plugins/well_example/Hooks/app_Routes_Routes.php


// 定义一个新组，属性与核心 Admin 组保持一致
Router::group([
    'prefix' => '/admin/example', // 自动通过前缀合并到 /admin 下
    'meta' => [
        'requiresAdminSignIn' => true, // 必须：强制后台登录验证
        'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'plugin']] // 可选：权限控制
    ]
], function () {

    // 最终路径: /admin/example/setting
    Router::get('/setting', [MyConfigController::class, 'setting'], ['layout' => 'plugin_setting']);

    // 最终路径: /admin/example/save
    Router::post('/save', [MyConfigController::class, 'save'], ['requiresCsrf' => ['enable' => true, 'ttl' => 600]]);
});

// 3. 创建全新的“独立组” (New Group)
// plugins/well_example/Hooks/app_Routes_Routes.php


Router::group([
    'prefix' => '/api/example', // 新的 URL 前缀
    'meta' => [
        // 可以在这里定义该组独有的中间件或元数据
        'requiresAuth' => false, // 可选：强制登录验证 true: 需要登录 false: 不需要登录
        'api' => true, // 强制 JSON 响应
        'api_rate_limit' => ['session' => ['limit' => 60, 'window' => 60]] // 声明式限流覆盖
    ]
], function () {

    // 最终路径: /api/example/list
    Router::get('/list', [ApiController::class, 'list']);

    // 最终路径: /api/example/detail
    Router::get('/detail', [ApiController::class, 'detail']);
});

// 4. 声明式限流精细化控制 (api_rate_limit)
Router::post('/api/heavy-task', [HeavyTaskController::class, 'process'], [
    'api' => true,
    'api_rate_limit' => [
        'enable' => true,
        'session' => [
            'limit'  => 5,  // 严格限制：每分钟仅允许 5 次访问
            'window' => 60
        ],
        'ip' => [
            'limit'  => 20, // IP 级放宽：允许共享公网 IP 的并发访问
            'window' => 60
        ]
    ]
]);
*/