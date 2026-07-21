<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\FileHelper;

class SettingController extends BaseController
{
    use AdminTrait;

    /** @var null */
    protected $serviceLocator = null;
    /** @var string */
    protected $smtpConfigFile = APP_PATH . 'config/Smtp.php';

    // hook app_Controllers_Admin_SettingController_start.php

    public function base(\Framework\Http\Interfaces\ServerRequestInterface $request)
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Admin_SettingController_base_base_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);

        $menu = $this->getAdminMenu();

        // hook app_Controllers_Admin_SettingController_base_base_before.php

        $page_link_string = 'admin/setting/base'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('admin_setting_base'),
                'keywords' => $this->language->get('admin_setting_base'),
                'description' => $this->language->get('admin_setting_base'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'setting', 'child' => 'base'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'site' => $this->site(),
            'timezone' => $this->getTimezoneLabels(),
            'runlevel' => $this->runlevel(),
            'base_on' => $this->baseOn(),
            'language_select' => $this->language(),
            'action' => $this->urlGenerator->url('admin/setting/postBase', $extra),
            'external_link_whitelist' => $this->appConfig['external_link_whitelist'] ?? '',
            'external_link_redirect_enabled' => $this->appConfig['external_link_redirect_enabled'] ?? 0,
            'external_link' => [
                'modules' => [
                    //'forum' => 'Forum',
                ],
                'selected' => $this->appConfig['external_link_modules'] ?? []
            ],
            'language' => [
                'website_name' => $this->language->get('website_name'),
                'sitebrief_tip' => $this->language->get('sitebrief_tip'),
                'runlevel' => $this->language->get('runlevel'),
                'submit' => $this->language->get('submit'),
                'external_link_whitelist' => $this->language->get('external_link_whitelist'),
                'external_link_whitelist_tip' => $this->language->get('external_link_whitelist_tip'),
                'external_link_redirect' => $this->language->get('external_link_redirect'),
                'external_link_modules' => $this->language->get('external_link_modules'),
            ]
        ];

        // hook app_Controllers_Admin_SettingController_base_base_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'setting_base'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postBase(\Framework\Http\Interfaces\ServerRequestInterface $request)
    {
        // hook app_Controllers_Admin_SettingController_base_postBase_start.php

        $sitename = RequestUtils::param('sitename');
        $domain = RequestUtils::param('domain', '');
        //$sitebrief = RequestUtils::param('sitebrief', '', false);
        //$sitebrief = trim($sitebrief);
        $runlevel = RequestUtils::param('runlevel', 0);
        $auth_signUp_on = RequestUtils::param('auth_sign_up_on', 0);
        $signIn_by_username = RequestUtils::param('signIn_by_username', 0);
        $signIn_by_code = RequestUtils::param('signIn_by_code', 0);
        $verify_email_on = RequestUtils::param('verify_email_on', 0);
        $user_resetpw_on = RequestUtils::param('user_resetpw_on', 0);
        $timezone = RequestUtils::param('timezone', 'UTC');
        $locale = RequestUtils::param('language');
        $externalLinkWhitelist = RequestUtils::param('external_link_whitelist', '');
        $externalLinkRedirectEnabled = RequestUtils::param('external_link_redirect_enabled', 0);
        // external_link_modules 是多选数组，从 $_POST 原生读取
        $externalLinkModules = isset($_POST['external_link_modules']) ? (array)$_POST['external_link_modules'] : [];
        $externalLinkModules = array_values(array_unique(array_filter($externalLinkModules)));

        // hook app_Controllers_Admin_SettingController_base_postBase_before.php

        $replace = [
            'sitename' => $sitename,
            'domain' => $domain,
            //'sitebrief' => SafeHelper::filter_all_html($sitebrief),
            'runlevel' => $runlevel,
            'auth_sign_up_on' => $auth_signUp_on,
            'signIn_by_username' => $signIn_by_username,
            'signIn_by_code' => $signIn_by_code,
            'verify_email_on' => $verify_email_on,
            'user_resetpw_on' => $user_resetpw_on,
            'external_link_whitelist' => $externalLinkWhitelist,
            'external_link_redirect_enabled' => $externalLinkRedirectEnabled,
            'external_link_modules' => $externalLinkModules,
        ];

        // hook app_Controllers_Admin_SettingController_base_postBase_after.php

        FileHelper::fileReplaceVar(APP_PATH . 'config/App.php', $replace);

        // 外链配置变更 → 缓存失效钩子
        // 插件在此钩子中监听并清除各自的外链聚合缓存：
        //   well_forum: forum_thread_full_v2_*, forum_reply_full_v2_*, fmtFullThreads_*
        //   well_page: page_cache_*
        //   well_article: article_cache_*, comment_cache_*
        //   well_store_server: store_item_*, store_review_*
        // hook app_Controllers_Admin_SettingController_base_postBase_after_external_link.php

        $replace = [
            'timezone' => $timezone,
            'locale' => $locale,
        ];
        FileHelper::fileReplaceVar(APP_PATH . 'config/I18n.php', $replace);

        // hook app_Controllers_Admin_SettingController_base_postBase_end.php

        return $this->successMessage($this->language->get('change_success'), 0, $this->urlGenerator->url('admin/setting/base'), 2);
    }

    private function site()
    {
        // hook app_Controllers_Admin_SettingController_base_site_start.php
        $data = [
            'sitename' => [
                'type' => 'text',
                'name' => 'sitename',
                'value' => $this->appConfig['sitename'] ?? '',
                'label' => $this->language->get('sitename')
            ],
            'domain' => [
                'type' => 'text',
                'name' => 'domain',
                'value' => $this->appConfig['domain'] ?? '',
                'label' => $this->language->get('domain')
            ]
        ];
        // hook app_Controllers_Admin_SettingController_base_site_end.php
        return $data;
    }

    /* private function siteBrief()
    {
        // hook app_Controllers_Admin_SettingController_sitebrief_start.php
        $data = [
            'sitebrief' => [
                'type' => 'textarea',
                'name' => 'sitebrief',
                'value' => $this->appConfig['sitebrief'] ?? '',
                'label' => $this->language->get('sitebrief')
            ]
        ];
        // hook app_Controllers_Admin_SettingController_sitebrief_end.php
        return $data;
    } */

    private function runlevel()
    {
        // hook app_Controllers_Admin_SettingController_runlevel_start.php
        $data = [
            'type' => 'radio',
            'name' => 'runlevel',
            'data' => [
                ['value' => 0, 'checked' => 0 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_0')],
                ['value' => 1, 'checked' => 1 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_1')],
                ['value' => 2, 'checked' => 2 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_2')],
                ['value' => 3, 'checked' => 3 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_3')],
                ['value' => 4, 'checked' => 4 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_4')],
                ['value' => 5, 'checked' => 5 === (int)$this->appConfig['runlevel'], 'label' => $this->language->get('runlevel_5')]
            ]
        ];
        // hook app_Controllers_Admin_SettingController_runlevel_end.php
        return $data;
    }

    private function baseOn()
    {
        // hook app_Controllers_Admin_SettingController_baseOn_start.php
        $data = [
            'auth_sign_up_on' => [
                'type' => 'checkbox',
                'name' => 'auth_sign_up_on',
                'value' => $this->appConfig['auth_sign_up_on'] ?? 0,
                'label' => $this->language->get('auth_sign_up_on')
            ],
            'signIn_by_username' => [
                'type' => 'checkbox',
                'name' => 'signIn_by_username',
                'value' => $this->appConfig['signIn_by_username'] ?? 0,
                'label' => $this->language->get('signIn_by_username')
            ],
            'signIn_by_code' => [
                'type' => 'checkbox',
                'name' => 'signIn_by_code',
                'value' => $this->appConfig['signIn_by_code'] ?? '',
                'label' => $this->language->get('signIn_by_code')
            ],
            'verify_email_on' => [
                'type' => 'checkbox',
                'name' => 'verify_email_on',
                'value' => $this->appConfig['verify_email_on'] ?? '',
                'label' => $this->language->get('verify_email_on')
            ],
            'user_resetpw_on' => [
                'type' => 'checkbox',
                'name' => 'user_resetpw_on',
                'value' => $this->appConfig['user_resetpw_on'] ?? '',
                'label' => $this->language->get('user_resetpw_on')
            ],
        ];
        // hook app_Controllers_Admin_SettingController_baseOn_end.php
        return $data;
    }

    private function language()
    {
        $i18nConfig = $this->container->get('i18nConfig');
        // hook app_Controllers_Admin_SettingController_language_start.php
        $data = [
            'type' => 'select',
            'name' => 'language',
            'label' => $this->language->get('default_language'),
            'data' => [
                // 默认支持语言
                'zh' => [
                    'value' => 'zh',
                    'selected' => 'zh' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_zh')
                ],
                'tw' => [
                    'value' => 'tw',
                    'selected' => 'tw' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_tw')
                ],
                'en' => [
                    'value' => 'en',
                    'selected' => 'en' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_en')
                ],
                'de' => [
                    'value' => 'de',
                    'selected' => 'de' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_de')
                ],
                'fr' => [
                    'value' => 'fr',
                    'selected' => 'fr' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_fr')
                ],
                'ja' => [
                    'value' => 'ja',
                    'selected' => 'ja' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_ja')
                ],
                'nl' => [
                    'value' => 'nl',
                    'selected' => 'nl' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_nl')
                ],
                'ko' => [
                    'value' => 'ko',
                    'selected' => 'ko' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_ko')
                ],
                'es' => [
                    'value' => 'es',
                    'selected' => 'es' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_es')
                ],
                'pt' => [
                    'value' => 'pt',
                    'selected' => 'pt' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_pt')
                ],
                'it' => [
                    'value' => 'it',
                    'selected' => 'it' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_it')
                ],
                'ru' => [
                    'value' => 'ru',
                    'selected' => 'ru' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_ru')
                ],
                'ar' => [
                    'value' => 'ar',
                    'selected' => 'ar' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_ar')
                ],
                'tr' => [
                    'value' => 'tr',
                    'selected' => 'tr' === $i18nConfig['locale'],
                    'label' => $this->language->get('locale_tr')
                ]
            ]
        ];
        // hook app_Controllers_Admin_SettingController_language_end.php
        return $data;
    }

    /**
     * @return array
     */
    private function getTimezoneLabels()
    {
        // hook app_Controllers_Admin_getTimezoneLabels_language_start.php
        $data = [
            'label' => $this->language->get('timezone'),
            'name' => 'timezone',
            'selected' => $this->appConfig['timezone'] ?? 'UTC',
            'data' => [
                'Pacific/Midway'          => $this->language->get('pacific_midway'),
                'Pacific/Honolulu'        => $this->language->get('pacific_honolulu'),
                'America/Anchorage'       => $this->language->get('america_anchorage'),
                'America/Los_Angeles'     => $this->language->get('america_los_angeles'),
                'America/Tijuana'         => $this->language->get('america_tijuana'),
                'America/Vancouver'       => $this->language->get('america_vancouver'),
                'America/Denver'          => $this->language->get('america_denver'),
                'America/Phoenix'         => $this->language->get('america_phoenix'),
                'America/Chihuahua'       => $this->language->get('america_chihuahua'),
                'America/Mazatlan'        => $this->language->get('america_mazatlan'),
                'America/Chicago'         => $this->language->get('america_chicago'),
                'America/Regina'          => $this->language->get('america_regina'),
                'America/Mexico_City'     => $this->language->get('america_mexico_city'),
                'America/Monterrey'       => $this->language->get('america_monterrey'),
                'America/New_York'        => $this->language->get('america_new_york'),
                'America/Toronto'         => $this->language->get('america_toronto'),
                'America/Indiana/Indianapolis' => $this->language->get('america_indiana_indianapolis'),
                'America/Lima'            => $this->language->get('america_lima'),
                'America/Bogota'          => $this->language->get('america_bogota'),
                'America/Caracas'         => $this->language->get('america_caracas'),
                'America/Santiago'        => $this->language->get('america_santiago'),
                'America/Buenos_Aires'    => $this->language->get('america_buenos_aires'),
                'America/Sao_Paulo'       => $this->language->get('america_sao_paulo'),
                'Atlantic/Azores'         => $this->language->get('atlantic_azores'),
                'Europe/London'           => $this->language->get('europe_london'),
                'Europe/Dublin'           => $this->language->get('europe_dublin'),
                'Europe/Lisbon'           => $this->language->get('europe_lisbon'),
                'Europe/Paris'            => $this->language->get('europe_paris'),
                'Europe/Berlin'           => $this->language->get('europe_berlin'),
                'Europe/Madrid'           => $this->language->get('europe_madrid'),
                'Europe/Rome'             => $this->language->get('europe_rome'),
                'Europe/Amsterdam'        => $this->language->get('europe_amsterdam'),
                'Europe/Brussels'         => $this->language->get('europe_brussels'),
                'Europe/Zurich'           => $this->language->get('europe_zurich'),
                'Europe/Vienna'           => $this->language->get('europe_vienna'),
                'Europe/Prague'           => $this->language->get('europe_prague'),
                'Europe/Warsaw'           => $this->language->get('europe_warsaw'),
                'Europe/Budapest'         => $this->language->get('europe_budapest'),
                'Europe/Athens'           => $this->language->get('europe_athens'),
                'Europe/Helsinki'         => $this->language->get('europe_helsinki'),
                'Europe/Istanbul'         => $this->language->get('europe_istanbul'),
                'Europe/Moscow'           => $this->language->get('europe_moscow'),
                'Asia/Jerusalem'          => $this->language->get('asia_jerusalem'),
                'Asia/Beirut'             => $this->language->get('asia_beirut'),
                'Asia/Riyadh'             => $this->language->get('asia_riyadh'),
                'Asia/Baghdad'            => $this->language->get('asia_baghdad'),
                'Asia/Kuwait'             => $this->language->get('asia_kuwait'),
                'Asia/Dubai'              => $this->language->get('asia_dubai'),
                'Asia/Tehran'             => $this->language->get('asia_tehran'),
                'Asia/Kabul'              => $this->language->get('asia_kabul'),
                'Asia/Karachi'            => $this->language->get('asia_karachi'),
                'Asia/Kolkata'            => $this->language->get('asia_kolkata'),
                'Asia/Kathmandu'          => $this->language->get('asia_kathmandu'),
                'Asia/Dhaka'              => $this->language->get('asia_dhaka'),
                'Asia/Yangon'             => $this->language->get('asia_yangon'),
                'Asia/Bangkok'            => $this->language->get('asia_bangkok'),
                'Asia/Singapore'          => $this->language->get('asia_singapore'),
                'Asia/Kuala_Lumpur'       => $this->language->get('asia_kuala_lumpur'),
                'Asia/Hong_Kong'          => $this->language->get('asia_hong_kong'),
                'Asia/Shanghai'           => $this->language->get('asia_shanghai'),
                'Asia/Chongqing'          => $this->language->get('asia_chongqing'),
                'Asia/Urumqi'             => $this->language->get('asia_urumqi'),
                'Asia/Taipei'             => $this->language->get('asia_taipei'),
                'Asia/Tokyo'              => $this->language->get('asia_tokyo'),
                'Asia/Seoul'              => $this->language->get('asia_seoul'),
                'Asia/Vladivostok'        => $this->language->get('asia_vladivostok'),
                'Australia/Brisbane'      => $this->language->get('australia_brisbane'),
                'Australia/Sydney'        => $this->language->get('australia_sydney'),
                'Australia/Melbourne'     => $this->language->get('australia_melbourne'),
                'Australia/Adelaide'      => $this->language->get('australia_adelaide'),
                'Australia/Perth'         => $this->language->get('australia_perth'),
                'Pacific/Auckland'        => $this->language->get('pacific_auckland'),
                'Pacific/Fiji'            => $this->language->get('pacific_fiji'),
                'Pacific/Tongatapu'       => $this->language->get('pacific_tongatapu'),
                'Antarctica/McMurdo'      => $this->language->get('antarctica_mcmurdo'),
                'UTC'                     => $this->language->get('timezone_utc'),
            ],
        ];
        // hook app_Controllers_Admin_getTimezoneLabels_language_end.php
        return $data;
    }

    public function smtp(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Admin_SettingController_smtp_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);
        $extra['csrf_token'] = $csrfToken;

        $menu = $this->getAdminMenu();
        $smtpList = $this->smtpInit($this->smtpConfigFile);

        // hook app_Controllers_Admin_SettingController_smtp_before.php

        $page_link_string = 'admin/setting/smtp'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('system_smtp_settings'),
                'keywords' => $this->language->get('system_smtp_settings'),
                'description' => $this->language->get('system_smtp_settings'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'setting', 'child' => 'smtp'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'item_list' => $smtpList,
            'action' => $this->urlGenerator->url('admin/setting/smtp', $extra),
            'operation_links' => [
                'create' => $this->urlGenerator->url('admin/setting/smtpOperation')
            ],
            'language' => [
                'operation' => $this->language->get('operation'),
                'add' => $this->language->get('add'),
                'change' => $this->language->get('change'),
                'delete' => $this->language->get('delete'),
                'submit' => $this->language->get('submit'),
            ]
        ];

        // hook app_Controllers_Admin_SettingController_smtp_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'setting_smtp'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function smtpOperation(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        $id = RequestUtils::param('id', 0);
        $extra['id'] = $id;

        // hook app_Controllers_Admin_SettingController_smtpOperation_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);
        $extra['csrf_token'] = $csrfToken;

        $menu = $this->getAdminMenu();

        $smtpList = $this->smtpInit($this->smtpConfigFile);
        $read = isset($smtpList[$id]) ? $smtpList[$id] : [
            'id' => null,
            'email' => '',
            'host' => '',
            'port' => '',
            'username' => '',
            'password' => '',
            'dkim_domain' => '',
            'dkim_private' => '',
            'dkim_selector' => 'default',
            'dkim_passphrase' => ''
        ];

        // hook app_Controllers_Admin_SettingController_smtpOperation_before.php

        $page_link_string = 'admin/setting/postSmtp'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('system_smtp_settings'),
                'keywords' => $this->language->get('system_smtp_settings'),
                'description' => $this->language->get('system_smtp_settings'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'setting', 'child' => 'smtp'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('list'),
                    'url' => $this->urlGenerator->url('admin/setting/smtp')
                ],
                'title' => [
                    'name' => $this->language->get('system_smtp_settings'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'read' => $read,
            'create' => null === $read['id'] ? true : false,
            'action' => $this->urlGenerator->url($page_link_string, $extra),
            'language' => [
                'submit' => $this->language->get('submit'),
            ]
        ];

        // hook app_Controllers_Admin_SettingController_smtpOperation_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'setting_smtp_operation'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postSmtp(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $smtpList = $this->smtpInit($this->smtpConfigFile);

        // hook app_Controllers_Admin_SettingController_postSmtp_start.php

        $id = RequestUtils::param('id', 0);
        $email = RequestUtils::param('email', '');
        $email = trim($email);
        if (!preg_match('#^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z]{2,})+$#', $email)) return $this->errorMessage($this->language->get('email_format_incorrect'), 'email');

        $host = RequestUtils::param('host', '');
        $host = trim($host);
        if (!$host) return $this->errorMessage($this->language->get('no_data_available'), 'host');

        $port = RequestUtils::param('port', 0);
        if (!$port) return $this->errorMessage($this->language->get('no_data_available'), 'port');

        $username = RequestUtils::param('username', '');
        $username = trim($username);
        if (!$username) return $this->errorMessage($this->language->get('email_format_incorrect'), 'username');

        $password = RequestUtils::param('password', '');
        $password = trim($password);
        if (!$password) return $this->errorMessage($this->language->get('password_is_empty'), 'password');

        $dkim_domain = RequestUtils::param('dkim_domain', '');
        $dkim_private = RequestUtils::param('dkim_private', '');
        $dkim_selector = RequestUtils::param('dkim_selector', 'default');
        $dkim_passphrase = RequestUtils::param('dkim_passphrase', '');

        // hook app_Controllers_Admin_SettingController_postSmtp_before.php

        $data = [
            'email' => $email,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'dkim_domain' => $dkim_domain,
            'dkim_private' => $dkim_private,
            'dkim_selector' => $dkim_selector,
            'dkim_passphrase' => $dkim_passphrase
        ];

        if (isset($smtpList[$id])) {
            unset($smtpList[$id]['id'], $smtpList[$id]['operation_links']);
            $smtpList[$id] = $data;
        } else {
            $smtpList[] = $data;
        }

        // hook app_Controllers_Admin_SettingController_postSmtp_after.php

        $result = FileHelper::filePutContentsTry($this->smtpConfigFile, "<?php\r\nreturn " . var_export($smtpList, true) . ";\r\n?" . htmlspecialchars_decode('&gt;'));
        if (!$result) return $this->errorMessage($this->language->get('data_error', ['msg' => 'postSmtp']), 14);

        // hook app_Controllers_Admin_SettingController_postSmtp_end.php

        return $this->successMessage($this->language->get('operation_success'), 0, $this->urlGenerator->url('admin/setting/smtp'), 2);
    }

    public function postSmtpDelete(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $id = RequestUtils::param('id', 0);
        if (!$id) return $this->errorMessage($this->language->get('params_error', ['error' => 'param[id]']), 6);

        $smtpList = $this->smtpInit($this->smtpConfigFile);
        if (isset($smtpList[$id])) unset($smtpList[$id]);

        $result = FileHelper::filePutContentsTry($this->smtpConfigFile, "<?php\r\nreturn " . var_export($smtpList, true) . ";\r\n?" . htmlspecialchars_decode('&gt;'));
        if (!$result) return $this->errorMessage($this->language->get('data_error', ['msg' => 'smtpDelete']), 14);

        return $this->successMessage($this->language->get('delete_success'), 0, $this->urlGenerator->url('admin/setting/smtp'), 2);
    }

    // $this->smtpInit(APP_PATH . 'config/Smtp.php');
    /**
     * @param string $configFile
     */
    private function smtpInit(string $configFile)
    {
        if (is_file($configFile)) {
            $list = include $configFile;
            if (!is_array($list) && empty($list)) return [];

            foreach ($list as $id => $smtp) {
                $list[$id]['id'] = $id;
                $list[$id]['operation_links'] = [
                    'update' => $this->urlGenerator->url('admin/setting/smtpOperation', ['id' => $id]),
                    'delete' => $this->urlGenerator->url('admin/setting/postSmtpDelete', ['id' => $id]),
                ];
            }
            return $list;
        } else {
            touch($configFile);
            return [];
        }
    }

    // hook app_Controllers_Admin_SettingController_end.php
}
