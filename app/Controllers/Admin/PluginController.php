<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Framework\Core\Container;
use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};
use Framework\Http\Routing\UrlGeneratorInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\{ArrayHelper, SafeHelper};
use App\Controllers\Base\{BaseController, ResponseFormatter, TemplateManager};
use App\Interfaces\LanguageLoaderInterface;
use App\Services\System\MenuService;
use App\Services\Auth\UserService;
use App\Traits\Admin\AdminTrait;

/**
 * PluginController - 插件管理控制器 (Refactored)
 */
class PluginController extends BaseController
{
    use AdminTrait;

    /** @var Container|null */
    protected $container;
    /** @var string */
    protected $currentType = 'plugin';
    /** @var array|null */
    protected $localPlugins = null;
    /** @var object */
    protected $extensionManager;
    /** @var \App\Services\Market\MarketClient */
    protected $market;

    public function __construct(
        ServerRequestInterface $request,
        ResponseFormatter $responseFormatter,
        LanguageLoaderInterface $language,
        UrlGeneratorInterface $urlGenerator,
        UserService $userService,
        MenuService $menuService,
        TemplateManager $templateManager,
        \App\Services\Auth\TokenService $tokenService,
        \App\Services\Extension\ExtensionManager $extensionManager,
        \App\Services\Market\MarketClient $market,
        array $appConfig,
        array $i18nConfig,
        ?Container $container = null
    ) {
        parent::__construct(
            $request,
            $responseFormatter,
            $language,
            $urlGenerator,
            $userService,
            $menuService,
            $templateManager,
            $tokenService,
            $appConfig,
            $i18nConfig,
            $container
        );
        $this->extensionManager = $extensionManager;
        $this->market = $market;
    }

    /**
     * 插件列表
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $page = (int)RequestUtils::param('page', 1);
        $pageSize = 20;

        $type = (int)RequestUtils::param('type', 0);
        $keywords = (string)RequestUtils::param('keywords', '');
        $searchType = (string)RequestUtils::param('search_type', 'name');

        $condition = $this->buildListCondition($type);
        if ($keywords) {
            $condition[$searchType] = $keywords;
        }

        $this->localPlugins = $this->extensionManager->getLocalList($this->currentType);

        $pluginList = ArrayHelper::arrayListConditionOrderBy($this->localPlugins, $condition, [], $page, $pageSize);
        $pluginList = $this->extensionManager->formatList($pluginList);

        // 方案A: 控制器层预解析确认操作语言键（替代模板层 str_replace）
        foreach ($pluginList as $index => $item) {
            $name = $item['name'] ?? '';
            $ops = &$pluginList[$index]['operation_links'];
            foreach (['enable', 'disable', 'install', 'uninstall'] as $action) {
                if (!empty($ops[$action])) {
                    $ops[$action]['confirm'] = $this->language->get('plugin_' . $action . '_confirm_tip', ['name' => $name]);
                }
            }
            if (!empty($ops['upgrade'])) {
                $ops['upgrade']['confirm'] = $this->language->get('plugin_upgrade_confirm_tip', [
                    'name' => $name,
                    'version' => $item['cloud_version'] ?? ''
                ]);
            }
        }
        unset($ops);

        $allPluginList = ArrayHelper::arrayListConditionOrderBy($this->localPlugins, $condition, [], 1, 1000);
        $totalItems = count($allPluginList);
        //$totalPages = (int)ceil($totalItems / $pageSize);

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/plugin/list';
        $extra = ['type' => $type, 'search_type' => $searchType, 'keywords' => $keywords];

        $data = [
            'header' => [
                'title' => $this->language->get('plugin'),
                'keywords' => $this->language->get('plugin'),
                'description' => $this->language->get('plugin'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'plugin'],
            'type' => $type,
            'header_category' => $this->buildStatusCategories(),
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'pagination' => $this->extensionManager->buildPagination($page, $pageSize, $totalItems, $extra, 'admin/plugin/list'),
            'item_list' => $pluginList,
            'search_types' => $this->extensionManager->buildSearchTypes(),
            'search' => [
                'searchType' => $searchType,
                'keywords' => $keywords,
            ],
            'csrf_token' => $this->getCsrfToken($user['salt']),
            'config' => [
                'rewrite' => $this->appConfig['url_rewrite_on'],
                'path' => $this->appConfig['path'],
            ],
            'language' => [
                'buy' => $this->language->get('buy'),
                'author' => $this->language->get('author'),
                'version' => $this->language->get('version'),
                'official_version' => $this->language->get('official_version'),
                'software_version' => $this->language->get('software_version'),
                'price' => $this->language->get('price'),
                'have_upgrade' => $this->language->get('have_upgrade'),
                'brief' => $this->language->get('brief'),
                'none' => $this->language->get('none'),
                'search' => $this->language->get('search'),
                'enter_keywords' => $this->language->get('enter_keywords'),
                'plugin_enable_confirm_tip' => $this->language->get('plugin_enable_confirm_tip'),
                'plugin_disable_confirm_tip' => $this->language->get('plugin_disable_confirm_tip'),
                'plugin_install_confirm_tip' => $this->language->get('plugin_install_confirm_tip'),
                'plugin_upgrade_confirm_tip' => $this->language->get('plugin_upgrade_confirm_tip'),
                'plugin_uninstall_confirm_tip' => $this->language->get('plugin_uninstall_confirm_tip'),
            ]
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 详情
     */
    public function detail(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $extra = ['dir' => $dir];

        $read = $this->extensionManager->readByDir($dir, $this->currentType);
        if (empty($read)) return $this->errorMessage($this->language->get('data_malformation'), 8, RequestUtils::server('HTTP_REFERER'));

        //$operation_links = $this->extensionManager->buildOperationLinks($read);

        $isLogged = $this->market->isLogged();
        $return = false;
        $payment_tip = '';
        if ($isLogged) {
            $this->extensionManager->syncMarketData([$dir]);
            $read = $this->extensionManager->readByDir($dir, $this->currentType);

            if ($read['price'] > 0 && empty($read['payment_id'])) {
                $payment_tip = $this->language->get('plugin_unpaid_tip');
                $return = true;
            }
        }

        $read['icon_url'] = $this->extensionManager->getIconUrl($dir, $this->currentType, $read['icon'] ?? '');

        $read['operation_links'] = $this->extensionManager->buildOperationLinks($read);

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/plugin/detail';
        $data = [
            'header' => [
                'title' => $this->language->get('plugin_detail') . '-' . $read['name'],
                'keywords' => $this->language->get('plugin_detail') . '-' . $read['name'],
                'description' => $this->language->get('plugin_detail'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'plugin'],
            'extra' => $extra,
            'csrf_token' => $this->getCsrfToken($user['salt']),
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'signin' => $isLogged,
            'return' => $return,
            'payment_tip' => $payment_tip,
            'payment_link' => $this->urlGenerator->url('admin/store/payment', ['dir' => $dir]),
            'extension' => $read,
            'action' => $this->urlGenerator->url('admin/store/signin'),
            'language' => [
                'title' => $this->language->get('please_sign_in'),
                'email' => $this->language->get('email'),
                'username' => $this->language->get('username'),
                'password' => $this->language->get('password'),
                'submit' => $this->language->get('submit'),
                'store_signin_tip' => $this->language->get('store_signin_tip'),
                'link' => $this->language->get('link'),
                'author' => $this->language->get('author'),
                'downloads' => $this->language->get('downloads'),
                'price' => $this->language->get('price'),
                'free' => $this->language->get('free'),
                'plugin_upgrade_v' => $this->language->get('plugin_upgrade_v'),
                'dependencies' => $this->language->get('dependencies'),
                'no_dependencies_found' => $this->language->get('no_dependencies_found'),
                'hooks' => $this->language->get('hooks'),
                'no_hooks_registered' => $this->language->get('no_hooks_registered'),
                'status_info' => $this->language->get('status_info'),
                'installed' => $this->language->get('installed'),
                'enabled' => $this->language->get('enabled'),
                'store_id' => $this->language->get('store_id'),
                'local' => $this->language->get('local'),
                'version_requirement' => $this->language->get('software_version'),
                'last_sync' => $this->language->get('update_time'),
                'refresh_data' => $this->language->get('refresh_data'),
                'processing' => $this->language->get('processing'),
                'brief' => $this->language->get('brief'),
                'signin_tip' => $this->language->get('plugin_signin_tip'),
                'plugin_detail_tip' => $this->language->get('plugin_detail_tip'),
                'plugin_unpaid_tip' => $this->language->get('plugin_unpaid_tip'),
                'something_went_wrong' => $this->language->get('something_went_wrong'),
                'payment' => $this->language->get('payment'),
                'install' => $this->language->get('install'),
                'uninstall' => $this->language->get('uninstall'),
                'setting' => $this->language->get('setting'),
                'enable' => $this->language->get('enable'),
                'disable' => $this->language->get('disable'),
                'upgrade' => $this->language->get('upgrade'),
                'download' => $this->language->get('download'),
                'official' => $this->language->get('official'),
                'unknown' => $this->language->get('unknown'),
                'plugin_install_confirm_tip' => $this->language->get('plugin_install_confirm_tip', ['name' => $read['name']]),
                'plugin_uninstall_confirm_tip' => $this->language->get('plugin_uninstall_confirm_tip', ['name' => $read['name']]),
                'plugin_enable_confirm_tip' => $this->language->get('plugin_enable_confirm_tip', ['name' => $read['name']]),
                'plugin_disable_confirm_tip' => $this->language->get('plugin_disable_confirm_tip', ['name' => $read['name']]),
                'plugin_upgrade_confirm_tip' => $this->language->get('plugin_upgrade_confirm_tip', ['name' => $read['name'], 'version' => $read['cloud_version'] ?? '']),
            ],
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('plugin'),
                    'url' => $this->urlGenerator->url('admin/plugin/list')
                ],
                'title' => [
                    'name' => $this->language->get('plugin_detail'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'detail'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function enable(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'enable');
    }

    public function disable(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'disable');
    }

    public function install(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'install');
    }

    public function uninstall(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'uninstall');
    }

    /**
     * 统一动作处理
     */
    private function getLocalPlugins(): array
    {
        if ($this->localPlugins === null) {
            $this->localPlugins = $this->extensionManager->getLocalList($this->currentType);
        }
        return $this->localPlugins;
    }

    private function handleAction($request, string $action): ResponseInterface
    {
        $dir = RequestUtils::param('dir');
        if (!$dir) return $this->errorMessage($this->language->get('params_error', ['error' => 'dir']), 4);

        $dir = (string)SafeHelper::safeWord($dir);
        $localPlugins = $this->getLocalPlugins();
        if (!isset($localPlugins[$dir])) return $this->errorMessage($this->language->get('plugin_not_exists'), 4);

        // 统一动作执行 (锁定 -> 依赖检查 -> 状态更新)
        $res = $this->extensionManager->execute($dir, $this->currentType, $action);
        if ($res['status'] === 'error') {
            return $this->errorMessage($res['message'], 1);
        }

        $msg = $action === 'uninstall' ? $this->language->get('plugin_' . $action . '_success', ['name' => $res['name'], 'dir' => $dir]) : $this->language->get('plugin_' . $action . '_success', ['name' => $res['name']]);
        return $this->successMessage($msg, 0, RequestUtils::server('HTTP_REFERER'), 2);
    }

    public function setting(\Framework\Http\Interfaces\ServerRequestInterface $request, string $method = 'GET'): ResponseInterface
    {
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $localPlugins = $this->getLocalPlugins();
        if (!isset($localPlugins[$dir])) return $this->errorMessage($this->language->get('plugin_not_exists'), 4);

        $user = $request->getAttribute('user', []);
        $action = RequestUtils::get('action', 'setting');
        /* switch ($action) {
            case 'setting':
                if ('GET' === $method) {
                    $csrfToken = $this->getCsrfToken($user['salt']);
                    // 获取导航栏信息
                    $menu = $this->getAdminMenu();
                    $extra = ['dir' => $dir, 'action' => 'setting', 'csrf_token' => $csrfToken];
                    $r = $this->localPlugins[$dir];
                    $page_link_string = 'admin/plugin/setting'; // 当前页链接字符串
                    $data = [
                        'header' => [
                            'title' => $this->language->get('plugin_detail') . '-' . $r['name'],
                            'keywords' => $this->language->get('plugin_detail') . '-' . $r['name'],
                            'description' => $this->language->get('plugin_detail'),
                        ],
                        'menu' => $menu,
                        'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'plugin'],
                        'extra' => $extra,
                        'csrf_token' => $csrfToken,
                        'page_link' => $this->urlGenerator->url($page_link_string, $extra),
                        'page_link_string' => $page_link_string,
                        'have_upgrade_url' => $this->urlGenerator->url('admin/plugin/upgrade', $extra),
                        'action' => $this->urlGenerator->url('admin/plugin/postSetting', $extra),
                        'plugin_info' => $r,
                        'language' => [
                            'brief' => $this->language->get('brief'),
                            'plugin_version' => $this->language->get('plugin_version'),
                            'price' => $this->language->get('price'),
                            'installs' => $this->language->get('installs'),
                            'signin_tip' => $this->language->get('plugin_signin_tip'),
                            'email' => $this->language->get('email'),
                            'username' => $this->language->get('username'),
                            'password' => $this->language->get('password'),
                            'submit' => $this->language->get('submit'),
                        ]
                    ];
                    echo '<pre>';
                    print_r($data);
                    echo '<hr>';
                    print_r(date('Y-m-d H:i:s'));
                    echo '<hr>';
                    var_dump($action);
                    echo '<hr>';
                    echo '</pre>';
                    exit;
                    $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'setting'];
                    return $this->render($routeMeta['layout'], $data, $dir);
                } elseif ('POST' === $method) {
                }
                break;
            default:
                return $this->errorMessage($this->language->get('data_malformation'), 2, RequestUtils::server('HTTP_REFERER'));
                break;
        } */

        $settingFile = $this->extensionManager->getBasePath($this->currentType) . $dir . '/setting.php';
        if (!file_exists($settingFile)) return $this->errorMessage($this->language->get('params_error'), 4);

        $compiledFile = \App\Core\Compile::include($settingFile);

        // 科学修复：使用 static 闭包隔离作用域，彻底阻断 $this 和局部变量向插件泄漏
        $response = (static function (string $file) {
            return include $file;
        })($compiledFile);

        // 类型安全：强制校验返回值必须为 ResponseInterface
        if (!$response instanceof ResponseInterface) {
            $typeStr = is_object($response) ? get_class($response) : gettype($response);
            return $this->errorMessage(
                $this->language->get('data_malformation') . ' (Invalid plugin response type: ' . $typeStr . ')',
                500
            );
        }

        return $response;
    }

    public function postSetting(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->setting($request, $method = 'POST');
    }

    private function buildStatusCategories(): array
    {
        return [
            0 => [
                'name' => $this->language->get('all'),
                'type' => 0,
                'url' => $this->urlGenerator->url('admin/plugin/list', ['type' => 0])
            ],
            1 => [
                'name' => $this->language->get('installed'),
                'type' => 1,
                'url' => $this->urlGenerator->url('admin/plugin/list', ['type' => 1])
            ],
            2 => [
                'name' => $this->language->get('not_installed'),
                'type' => 2,
                'url' => $this->urlGenerator->url('admin/plugin/list', ['type' => 2])
            ],
            3 => [
                'name' => $this->language->get('disable'),
                'type' => 3,
                'url' => $this->urlGenerator->url('admin/plugin/list', ['type' => 3])
            ]
        ];
    }

    private function buildListCondition(int $type): array
    {
        switch ($type) {
            case 1:
                return ['installed' => 1, 'enable' => 1];
            case 2:
                return ['installed' => 0, 'enable' => 0];
            case 3:
                return ['installed' => 1, 'enable' => 0];
            default:
                return [];
        }
    }
}
