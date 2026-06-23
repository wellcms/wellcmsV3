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
use App\Services\System\{KeyValueService, MenuService};
use App\Services\Auth\UserService;
use App\Traits\Admin\AdminTrait;

/**
 * ThemeController - 主题管理控制器 (Refactored)
 */
class ThemeController extends BaseController
{
    use AdminTrait;

    /** @var Container|null */
    protected $container;
    /** @var string */
    protected $currentType = 'theme';
    /** @var array|null */
    protected $localThemes = null;
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
     * 主题列表
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

        // --- 下游管理隔离：列表页实时扫描本地物理扩展，确保断网环境可用且响应迅速 ---
        $this->localThemes = $this->extensionManager->getLocalList($this->currentType);

        $themeList = ArrayHelper::arrayListConditionOrderBy($this->localThemes, $condition, [], $page, $pageSize);
        $themeList = $this->extensionManager->formatList($themeList);

        $kv = $this->container->get(KeyValueService::class);
        $settingConfig = $kv->settingGet('config');
        $currentThemeDir = $settingConfig['theme'] ?? '';

        // 将当前主题置顶
        if ($currentThemeDir && isset($themeList[$currentThemeDir])) {
            $current = $themeList[$currentThemeDir];
            unset($themeList[$currentThemeDir]);
            $themeList = [$currentThemeDir => $current] + $themeList;
        }

        $allThemeList = ArrayHelper::arrayListConditionOrderBy($this->localThemes, $condition, [], 1, 1000);
        $totalItems = count($allThemeList);
        //$totalPages = (int)ceil($totalItems / $pageSize);

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/theme/list';
        $extra = ['type' => $type, 'search_type' => $searchType, 'keywords' => $keywords];

        $data = [
            'header' => [
                'title' => $this->language->get('theme'),
                'keywords' => $this->language->get('theme'),
                'description' => $this->language->get('theme'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'theme'],
            'type' => $type,
            'header_category' => $this->buildStatusCategories(),
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'pagination' => $this->extensionManager->buildPagination($page, $pageSize, $totalItems, $extra, 'admin/theme/list'),
            'item_list' => $themeList,
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
                'search' => $this->language->get('search'),
                'enter_keywords' => $this->language->get('enter_keywords'),
                'none' => $this->language->get('none'),
                'previous' => $this->language->get('previous'),
                'next' => $this->language->get('next'),
                'confirm_uninstall' => $this->language->get('theme_uninstall_confirm_tip'),
            ]
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 主题详情
     */
    public function detail(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $extra = ['dir' => $dir];

        $read = $this->extensionManager->readByDir($dir, $this->currentType);
        if (empty($read)) return $this->errorMessage($this->language->get('data_malformation'), 8, RequestUtils::server('HTTP_REFERER'));

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

        $read['operation_links'] = $this->extensionManager->buildOperationLinks($read);

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/theme/detail';
        $data = [
            'header' => [
                'title' => $this->language->get('theme_detail') . '-' . $read['name'],
                'keywords' => $this->language->get('theme_detail') . '-' . $read['name'],
                'description' => $this->language->get('theme_detail'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'theme'],
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
            ],
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('theme'),
                    'url' => $this->urlGenerator->url('admin/theme/list')
                ],
                'title' => [
                    'name' => $this->language->get('theme_detail'),
                    'url' => $this->urlGenerator->url($page_link_string, $extra)
                ]
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'detail'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function install(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'install');
    }

    public function enable(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'enable');
    }

    public function uninstall(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleAction($request, 'uninstall');
    }

    /**
     * 统一动作处理
     */
    private function handleAction(\Framework\Http\Interfaces\ServerRequestInterface $request, string $action): ResponseInterface
    {
        $dir = RequestUtils::param('dir');
        if (!$dir) return $this->errorMessage($this->language->get('params_error', ['error' => 'dir']), 4);

        $dir = (string)SafeHelper::safeWord($dir);
        $localThemes = $this->extensionManager->getLocalList($this->currentType);
        if (!isset($localThemes[$dir])) return $this->errorMessage($this->language->get('theme_does_not_exist'), 4);

        // 统一动作执行 (锁定 -> 依赖检查 -> 状态更新)
        $res = $this->extensionManager->execute($dir, $this->currentType, $action);
        if ($res['status'] === 'error') {
            return $this->errorMessage($res['message'], 1);
        }

        $langKey = 'theme_' . $action . '_success';
        return $this->successMessage($this->language->get($langKey, ['name' => $res['name']]), 0, RequestUtils::server('HTTP_REFERER'), 2);
    }

    private function getLocalThemes(): array
    {
        if ($this->localThemes === null) {
            $this->localThemes = $this->extensionManager->getLocalList($this->currentType);
        }
        return $this->localThemes;
    }

    private function buildStatusCategories(): array
    {
        return [
            0 => [
                'name' => $this->language->get('all'),
                'type' => 0,
                'url' => $this->urlGenerator->url('admin/theme/list', ['type' => 0])
            ],
            1 => [
                'name' => $this->language->get('enabled'),
                'type' => 1,
                'url' => $this->urlGenerator->url('admin/theme/list', ['type' => 1])
            ],
            2 => [
                'name' => $this->language->get('not_installed'),
                'type' => 2,
                'url' => $this->urlGenerator->url('admin/theme/list', ['type' => 2])
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
            default:
                return [];
        }
    }
}
