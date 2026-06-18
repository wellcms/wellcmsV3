<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use Framework\Http\Interfaces\{ResponseFactoryInterface, ResponseInterface};
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\FormatHelper;
use App\Controllers\Admin\Service\TokenManager;
use App\Controllers\Base\BaseController;
use App\Services\System\{CacheService ,KeyValueService};
use App\Services\Auth\UserService;
use App\Services\Stats\RuntimeStats;
use App\Traits\Admin\AdminTrait;

class IndexController extends BaseController
{
    use AdminTrait;

    // hook app_Controllers_Admin_IndexController_start.php

    public function signIn(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $csrfToken = $this->getCsrfToken($user['salt']);
        $extra = [];

        // hook app_Controllers_Admin_IndexController_signIn_start.php

        $page_link_string = 'admin/signIn'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('admin_sign_in') . '-' . ($this->appConfig['sitename'] ?? 'WellCMS'),
                'keywords' => $this->language->get('admin_sign_in'),
                'description' => $this->language->get('admin_sign_in')
            ],
            'csrf_token' => $csrfToken,
            'extra' => $extra,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('admin/postSignIn'),
            'my_home_link' => $this->urlGenerator->url('my/home'),
            'mode' => 'password',
            'language' => [
                'title' => $this->language->get('admin_sign_in'),
                'password' => $this->language->get('sign_in_password'),
                'submit' => $this->language->get('submit'),
                'my_home' => $this->language->get('my_home'),
                'home_page' => $this->language->get('home_page'),
            ]
        ];

        // hook app_Controllers_Admin_IndexController_signIn_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'signIn'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postSignIn(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $mode = strtolower(RequestUtils::param('mode', 'password'));
        $user = $request->getAttribute('user', []);
        $haystack = ['password'];

        // hook app_Controllers_Admin_IndexController_postSignIn_start.php

        if (!in_array($mode, $haystack, true)) return $this->errorMessage($this->language->get('data_error', ['msg' => '$mode']), 7);

        // hook app_Controllers_Admin_IndexController_postSignIn_before.php

        if ('password' === $mode) {
            $password = RequestUtils::param('password');
            if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

            if (strlen($password) < 6) return $this->errorMessage($this->language->get('password_length_error'), 'password');

            $userService = $this->container->get(UserService::class);
            $result = $userService->read((int)$user['id']);
            if (empty($result)) return $this->errorMessage($this->language->get('user_not_exist'), -1);

            // hook app_Controllers_Admin_IndexController_postSignIn_center.php

            if (false === password_verify($password, $result['password'])) return $this->errorMessage($this->language->get('incorrect_password'), 'password');
        }

        // 设置登录cookie
        $tokenManager = $this->container->get(TokenManager::class);
        $tokenManager->adminTokenSet();

        // hook app_Controllers_Admin_IndexController_postSignIn_after.php

        $referer = RequestUtils::server('HTTP_REFERER');
        if ($referer = trim($referer, '?')) {
            $referer = strtr($referer, '-', '/'); //str_replace('-', '/', $referer);
            if (0 !== strpos($referer, 'admin') || 0 !== strpos($referer, '/admin')) $referer = $this->urlGenerator->url('admin/panel');
        } else {
            $referer = $this->urlGenerator->url('admin/panel');
        }

        // hook app_Controllers_Admin_IndexController_postSignIn_end.php

        return $this->successMessage($this->language->get('sign_in_success'), 0, $referer);
    }

    public function logout(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        // hook app_Controllers_Admin_IndexController_logout_start.php
        $tokenManager = $this->container->get(TokenManager::class);
        $tokenManager->adminTokenClean();
        // hook app_Controllers_Admin_IndexController_logout_end.php
        return $this->successMessage($this->language->get('already_logout'), 0, '/');
    }

    // 首页
    public function panel(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $kv = $this->container->get(KeyValueService::class);
        $extra = [];
        $stats = $this->container->get(RuntimeStats::class);
        $sessionService = $this->container->get(\App\Services\Auth\SessionService::class);

        // hook app_Controllers_Admin_IndexController_panel_start.php

        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        $settingConfig = $kv->settingGet('config');

        // 后台首页自动触发版本检查：仅在尚无已知升级时执行，避免重复外联
        // doVersionCheck 通过引用链直接修改 $settingConfig，内部
        // validateAndStoreVersion 已负责持久化 + 更新内存态，无需重复读取。
        if (($settingConfig['upgrade'] ?? 0) == 0 && $this->needVersionCheck($settingConfig)) {
            $this->doVersionCheck($settingConfig, false, true);
        }

        // hook app_Controllers_Admin_IndexController_panel_after.php

        $page_link_string = 'admin/panel'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('admin_dashboard'),
                'keywords' => $this->language->get('admin_dashboard'),
                'description' => $this->language->get('admin_dashboard')
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'home', 'child' => ''],
            'extra' => $extra,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'software' => [
                'software_name' => isset($settingConfig['name']) ? $settingConfig['name'] : $this->language->get('none'),
                'software_version' => isset($settingConfig['version']) ? $settingConfig['version'] : $this->language->get('none'),
                'software_official_version' => isset($settingConfig['official_version']) ? $settingConfig['official_version'] : $this->language->get('none'),
                'official_info' => isset($settingConfig['official_info']) ? $settingConfig['official_info'] : '',
            ],
            'upgrade' => $settingConfig['upgrade'],
            'upgrade_url' => $this->urlGenerator->url('admin/check/upgrade'),
            'runtime_data' => [
                'users'        => $stats->getTotal('users'),
                'users_online' => $sessionService->onlineCount(),
            ],
            'system' => [
                'systemInfo_link' => $this->urlGenerator->url('admin/systemInfo'),
                'os' => PHP_OS,
                'php_version' => PHP_VERSION,
                'disable_functions' => !empty(ini_get('disable_functions')) ? ini_get('disable_functions') : $this->language->get('none'),
                'allow_url_fopen' => ini_get('allow_url_fopen') ? $this->language->get('yes') : $this->language->get('no'),
                'safe_mode' => ini_get('safe_mode') ? $this->language->get('yes') : $this->language->get('no'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'server_software' => RequestUtils::server('SERVER_SOFTWARE'),
                'remote_addr' => RequestUtils::server('REMOTE_ADDR'),
                'server_ip' => RequestUtils::server('SERVER_ADDR'),
                'disk_free_space' => function_exists('disk_free_space') ? FormatHelper::humanSize((int)disk_free_space(APP_PATH)) : $this->language->get('unknown'),
            ],
            'language' => [
                'have_upgrade' => $this->language->get('have_upgrade'),
                'upgrade' => $this->language->get('upgrade'),
                'current_version' => $this->language->get('current_version'),
                'official_version' => $this->language->get('official_version'),
                'users' => $this->language->get('users'),
                'users_online' => $this->language->get('users_online'),
                'server_info' => $this->language->get('server_info'),
                'disk_free_space' => $this->language->get('disk_free_space'),
                'php' => 'PHP',
                'os' => $this->language->get('os'),
                'post_max_size' => $this->language->get('post_max_size'),
                'upload_max_filesize' => $this->language->get('upload_max_filesize'),
                'allow_url_fopen' => $this->language->get('allow_url_fopen'),
                'max_execution_time' => $this->language->get('max_execution_time'),
                'web_server' => $this->language->get('web_server'),
                'safe_mode' => $this->language->get('safe_mode'),
                'memory_limit' => $this->language->get('memory_limit'),
                'client_ip' => $this->language->get('client_ip'),
                'server_ip' => $this->language->get('server_ip'),
                'development_team' => $this->language->get('development_team'),
            ]
        ];

        // hook app_Controllers_Admin_IndexController_panel_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'panel'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 版本检查页面
     */
    public function checkUpgrade(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'upgrade_check'];
        $kv = $this->container->get(KeyValueService::class);
        $cache = $this->container->get(CacheService::class);

        $settingConfig = $kv->settingGet('config');

        // 支持 ?force=1 强制绕过冷却期，确保用户手动检查时能拿到实时结果
        $force = ($request->getQueryParams()['force'] ?? '') === '1';

        // 统一走 doVersionCheck：冷却期内未强制刷新时返回 null，降级读缓存
        // 注意：doVersionCheck 返回 '' 表示检查成功但无消息文本，也应降级读缓存
        $message = $this->doVersionCheck($settingConfig, $force);
        if (empty($message)) {
            $message = $cache->get('official-message');
        }

        // 生成 CSRF 令牌供 processUpgrade 链接使用（GET 请求通过 _csrf_token 查询参数校验）
        $user = $request->getAttribute('user', []);
        $csrfToken = empty($user['salt']) ? '' : $this->getCsrfToken($user['salt']);

        return $this->renderCheckPage($routeMeta['layout'], [
            'upgrade_url' => $this->urlGenerator->url('admin/process/upgrade', $csrfToken ? ['_csrf_token' => $csrfToken] : []),
            'upgrade_id' => $settingConfig['upgrade_id'],
            'software_version' => $settingConfig['version'],
            'official_version' => $settingConfig['official_version'],
            'official_message' => $message,
            'upgrade' => $settingConfig['upgrade'],
            // upgrade_available 与 process_upgrade 为模板遗留兼容字段，均指向 upgrade
            'upgrade_available' => $settingConfig['upgrade'],
            'process_upgrade' => $settingConfig['upgrade'],
            '_csrf_token' => $csrfToken,
        ]);
    }

    /**
     * 执行升级流程
     *
     * @throws \App\Exception\UpgradeException
     */
    public function processUpgrade(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // CSRF 保护：验证查询参数中的 _csrf_token（GET 请求避免大文件下载超时）
        $user = $request->getAttribute('user', []);
        $token = $request->getQueryParams()['_csrf_token'];
        if (!$token || !$this->verifyCsrfToken($token, $user['salt'] ?? '')) {
            throw new \App\Exception\UpgradeException(
                $this->language->get('request_method_error'),
                16,
                $this->urlGenerator->url('admin/check/upgrade')
            );
        }

        $kv = $this->container->get(KeyValueService::class);
        $settingConfig = $kv->settingGet('config');

        // 【安全性锁死】禁止从 Request 获取 URL/Hash，防止恶意劫持，仅信任数据库固化的数据
        $upgradeUrl = $settingConfig['upgrade_url'];
        $hash = $settingConfig['upgrade_hash'];

        if (empty($upgradeUrl)) {
            throw new \App\Exception\UpgradeException(
                $this->language->get('upgrade_url_empty'),
                13,
                $this->urlGenerator->url('admin/check/upgrade')
            );
        }

        /** @var \App\Services\Upgrade\UpgradeService $upgradeService */
        $upgradeService = $this->container->get(\App\Services\Upgrade\UpgradeService::class);

        // 环境预检下沉到 Service，控制器不再直接操作文件系统
        $upgradeService->preflightCheck();

        try {
            // 执行核心升级逻辑
            $upgradeService->run($upgradeUrl, $hash);
        } catch (\Throwable $e) {
            // 记录真实审计日志
            if ($this->container->has(\Framework\Logger\LoggerInterface::class)) {
                $this->container->get(\Framework\Logger\LoggerInterface::class)->error('Upgrade failed: ' . $e->getMessage(), [
                    'url' => $upgradeUrl,
                    'hash' => $hash,
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // 抛出具名异常，由中间件统一接管，不再直接返回 errorMessage
            throw new \App\Exception\UpgradeException(
                '升级中断：' . $e->getMessage(),
                1,
                $this->urlGenerator->url('admin/check/upgrade'),
                $e
            );
        }

        // 升级成功后 302 跳转
        $responseFactory = $this->container->get(ResponseFactoryInterface::class);
        return $responseFactory->createResponse(302)->withHeader('Location', $this->urlGenerator->url('admin/upgrade/success'));
    }

    /**
     * 显示升级成功页面
     */
    public function upgradeSuccess(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'upgrade_success'];
        $kv = $this->container->get(KeyValueService::class);
        $settingConfig = $kv->settingGet('config');
        $baseData = $this->getBaseTemplateData();

        $data = array_merge($baseData, [
            'title' => $this->language->get('upgrade_successfully'),
            'software_version' => $settingConfig['version'],
            'admin_panel_url'  => $this->urlGenerator->url('admin/panel'),
            'language' => [
                'upgrade_success_title' => $this->language->get('upgrade_success_title'),
                'upgrade_success_desc'  => $this->language->get('upgrade_success_desc'),
                'return_to_admin_panel' => $this->language->get('return_to_admin_panel'),
                'upgrade_cache_cleared' => $this->language->get('upgrade_cache_cleared'),
                'software_version'      => $this->language->get('software_version'),
            ]
        ]);

        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 是否需要版本检查
     */
    private function needVersionCheck(array $config)
    {
        return (isset($config['last_version']) ? $config['last_version'] : 0) < time();
    }

    /**
     * 统一的版本检查入口
     *
     * panel() 的自动检测与 checkUpgrade() 的手动检查共用此方法，消除代码重复。
     * validateAndStoreVersion() 同时负责设置冷却期、持久化升级状态和返回官方消息，
     * 无论远程是否有新版本都会调用，确保 last_version 冷却计时始终更新。
     *
     * @param array $config 配置数组（引用传回，validateAndStoreVersion 直接修改后持久化）
     * @param bool  $force  强制忽略冷却期（checkUpgrade?force=1 时使用）
     * @param bool  $silent 静默模式：异常时记日志返回 null 不抛出（panel 自动检测用）
     * @return string|null  官方消息文本；null = 冷却期内未执行 / silent 模式下检查失败
     * @throws \Throwable 非 silent 模式异常直接上浮
     */
    private function doVersionCheck(array &$config, bool $force = false, bool $silent = false): ?string
    {
        if (!$force && !$this->needVersionCheck($config)) {
            return null;
        }

        try {
            /** @var \App\Services\Upgrade\UpgradeService $upgradeService */
            $upgradeService = $this->container->get(\App\Services\Upgrade\UpgradeService::class);
            $response = $upgradeService->checkVersion();
            return $upgradeService->validateAndStoreVersion($response, $config);
        } catch (\Throwable $e) {
            if ($silent) {
                if ($this->container->has(\Framework\Logger\LoggerInterface::class)) {
                    $this->container->get(\Framework\Logger\LoggerInterface::class)->warning(
                        'Auto version check failed: ' . $e->getMessage()
                    );
                }
                return null;
            }
            throw $e;
        }
    }

    /**
     * 渲染检查页面
     */
    private function renderCheckPage(string $layout, array $data)
    {
        $baseData = $this->getBaseTemplateData();
        return $this->render($layout, array_merge($baseData, [
            'title' => $this->language->get('online_upgrade'),
            'page_link' => $this->urlGenerator->url('admin/check/upgrade'),
            'page_link_string' => 'admin/check/upgrade',
            'official_url' => 'https://www.wellcms.com/',
            'language' => [
                'current_version' => $this->language->get('current_version'),
                'official_version' => $this->language->get('official_version'),
                'online_upgrade' => $this->language->get('online_upgrade'),
                'upgrade_ready_title' => $this->language->get('upgrade_ready_title'),
                'upgrade_ready_desc' => $this->language->get('upgrade_ready_desc'),
                'upgrade_start_now' => $this->language->get('upgrade_start_now'),
                'upgrade_latest_title' => $this->language->get('upgrade_latest_title'),
                'upgrade_latest_desc' => $this->language->get('upgrade_latest_desc'),
                'upgrade_confirm_msg' => $this->language->get('upgrade_confirm_msg'),
                'upgrade_processing' => $this->language->get('upgrade_processing'),
            ]
        ] + $data), true);
    }

    /**
     * 获取基础模板数据
     */
    private function getBaseTemplateData(): array{
        $kv = $this->container->get(KeyValueService::class);
        $config = $kv->settingGet('config');
        return [
            'menu' => $this->getAdminMenu(),
            'keywords' => $this->language->get('upgrading'),
            'description' => $config['name'] . $this->language->get('upgrading')
        ];
    }

    public function systemInfo(): void{
        phpinfo();
    }

    // hook app_Controllers_Admin_IndexController_end.php
}
