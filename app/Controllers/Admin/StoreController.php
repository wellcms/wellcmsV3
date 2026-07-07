<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Framework\Core\Container;
use Framework\Http\Interfaces\{ResponseInterface, ServerRequestInterface};
use Framework\Http\Routing\UrlGeneratorInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\{ArrayHelper, SafeHelper, SecurityHelper};
use App\Controllers\Base\{BaseController, ResponseFormatter, TemplateManager};
use App\Interfaces\LanguageLoaderInterface;
use App\Services\System\KeyValueService;
use App\Services\System\MenuService;
use App\Services\Auth\UserService;
use App\Traits\Admin\AdminTrait;

/**
 * StoreController - 应用商店控制器 (Refactored)
 */
class StoreController extends BaseController
{
    use AdminTrait;

    /** @var Container|null */
    protected $container;
    /** @var string */
    protected $currentType = 'plugin';
    /** @var array */
    protected $officialPlugins = null;
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
     * 应用商店列表
     */
    public function list(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $page = RequestUtils::param('page', 1);
        $pageSize = 20;

        $type = RequestUtils::param('type', 0);
        $keywords = RequestUtils::param('keywords', '');
        $searchType = RequestUtils::param('search_type', 'name');

        $condition = [];
        if ($type > 0 && in_array($type, [1, 2])) {
            $condition['type'] = $type;
        }
        if ($keywords) {
            // FIX: 使用 LIKE 语义实现模糊搜索，避免精确匹配导致搜索无结果
            $condition[$searchType] = ['LIKE' => $keywords];
        }

        $isLogged = $this->market->isLogged();
        if ($isLogged) {
            $dirs = array_column($this->getOfficialPlugins(), 'dir');
            // 顺序：syncMarketData（增量更新已上架）→ syncRemovedDirs（增量清理已下架）→ syncOfficialCatalog（全量兜底）
            $this->extensionManager->syncMarketData($dirs);
            $this->extensionManager->syncRemovedDirs();
            $this->extensionManager->syncOfficialCatalog();
            $this->initOfficialData();
        }

        // 过滤非官方应用：仅保留官方数据
        // 多层防御：1) name为空是查询未命中的默认壳；2) is_official=0 是非官方；3) 无 last_sync 是本地污染
        $officialPlugins = array_filter($this->getOfficialPlugins(), function ($item) {
            if (empty($item['name'])) {
                return false;
            }
            if (array_key_exists('is_official', $item)) {
                return !empty($item['is_official']);
            }
            return array_key_exists('last_sync', $item);
        });
        $pluginList = ArrayHelper::arrayListConditionOrderBy($officialPlugins, $condition, [], $page, $pageSize);
        $pluginList = $this->extensionManager->formatList($pluginList);
        // FIX: 分页总数不再依赖登录状态
        $totalItems = count($officialPlugins);

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/store/list';
        $extra = ['type' => $type, 'search_type' => $searchType, 'keywords' => $keywords];

        $data = [
            'header' => [
                'title' => $this->language->get('store'),
                'keywords' => $this->language->get('store'),
                'description' => $this->language->get('store'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'store'],
            'signin' => $isLogged,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'pagination' => $this->extensionManager->buildPagination($page, $pageSize, $totalItems),
            'action' => $this->urlGenerator->url('admin/store/signin'),
            'signout_action' => $this->urlGenerator->url('admin/store/signout'),
            'item_list' => $pluginList,
            'search_types' => $this->extensionManager->buildSearchTypes(),
            'search' => [
                'searchType' => $searchType,
                'keywords' => $keywords,
            ],
            'csrf_token' => $this->getCsrfToken($user['salt']),
            'language' => [
                'store' => $this->language->get('store'),
                'official_market_content' => $this->language->get('official_market_content'),
                'enter_keywords' => $this->language->get('enter_keywords'),
                'search' => $this->language->get('search'),
                'price' => $this->language->get('price'),
                'free' => $this->language->get('free'),
                'install' => $this->language->get('install'),
                'installed' => $this->language->get('installed'),
                'upgrade' => $this->language->get('upgrade'),
                'download' => $this->language->get('download'),
                'author' => $this->language->get('author'),
                'official' => $this->language->get('official'),
                'previous' => $this->language->get('previous'),
                'next' => $this->language->get('next'),
                'no_apps_found' => $this->language->get('no_apps_found'),
                'search_filter_tip' => $this->language->get('search_filter_tip'),
                'store_signin_title' => $this->language->get('store_connect'),
                'store_signin_tip' => $this->language->get('store_signin_tip'),
                'email' => $this->language->get('email'),
                'username' => $this->language->get('username'),
                'password' => $this->language->get('password'),
                'submit' => $this->language->get('submit'),
                'official_website' => $this->language->get('official_website'),
                'processing' => $this->language->get('processing'),
                'please_enter_keywords' => $this->language->get('please_enter_keywords'),
                'no_description' => $this->language->get('no_description'),
                'logout' => $this->language->get('logout'),
            ]
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'list'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function detail(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $extra = ['dir' => $dir];

        $read = $this->extensionManager->readByDir($dir, 'plugin', false);
        if (empty($read)) {
            $read = $this->extensionManager->readByDir($dir, 'theme', false);
        }
        if (empty($read)) return $this->errorMessage($this->language->get('data_malformation'), 8, RequestUtils::server('HTTP_REFERER'));

        $isLogged = $this->market->isLogged();
        $return = false;
        $payment_tip = '';

        if ($isLogged) {
            $this->extensionManager->syncMarketData([$dir]);
            $extensionType = (2 === (int)($read['type'] ?? 0)) ? 'theme' : 'plugin';
            $read = $this->extensionManager->readByDir($dir, $extensionType, false);

            if ($read['price'] > 0 && empty($read['payment_id'])) {
                $payment_tip = $this->language->get('plugin_unpaid_tip');
                $return = true;
            }
        }

        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/store/detail';

        $read['operation_links'] = $this->extensionManager->buildOperationLinks($read);

        $data = [
            'header' => [
                'title' => $this->language->get('plugin_detail') . '-' . $read['name'],
                'keywords' => $this->language->get('plugin_detail') . '-' . $read['name'],
                'description' => $this->language->get('plugin_detail'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'AppCenter', 'child' => 'store'],
            'official_url' => 'https://www.wellcms.com/',
            'signin' => $isLogged,
            'return' => $return
                ? ['code' => 1, 'status' => 'error', 'message' => $payment_tip]
                : ['code' => 0, 'status' => 'success'],
            'payment_tip' => $payment_tip,
            'payment_link' => $this->urlGenerator->url('admin/store/payment', ['dir' => $dir]),
            'extension' => $read,
            'csrf_token' => $this->getCsrfToken($user['salt']),
            'action' => $this->urlGenerator->url('admin/store/signin'),
            'signout_action' => $this->urlGenerator->url('admin/store/signout'),
            'language' => [
                'title' => $this->language->get('please_sign_in'),
                'email' => $this->language->get('email'),
                'username' => $this->language->get('username'),
                'password' => $this->language->get('password'),
                'submit' => $this->language->get('submit'),
                'store_signin_tip' => $this->language->get('store_signin_tip'),
                'brief' => $this->language->get('brief'),
                'plugin_version' => $this->language->get('plugin_version'),
                'price' => $this->language->get('price'),
                'installs' => $this->language->get('installs'),
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
                'link' => $this->language->get('link'),
                'minimum_version' => $this->language->get('minimum_version'),
                'official' => $this->language->get('official'),
                'unknown' => $this->language->get('unknown'),
                'downloads' => $this->language->get('downloads'),
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
                'author' => $this->language->get('author'),
                'logout' => $this->language->get('logout'),
            ],
            'breadcrumb' => [
                'home' => [
                    'name' => $this->language->get('home_page'),
                    'url' => $this->urlGenerator->url('admin/panel')
                ],
                'list' => [
                    'name' => $this->language->get('store'),
                    'url' => $this->urlGenerator->url('admin/store/list')
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

    public function signout(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $this->market->logout();
        return $this->successMessage($this->language->get('already_logout'), 0, RequestUtils::server('HTTP_REFERER'));
    }

    public function signin(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $username = RequestUtils::param('username', '');
        $password = RequestUtils::param('password', '');

        if (empty($username) || empty($password)) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        $params = $this->market->getCommonParams();
        $params['username'] = $username;
        $params['password'] = $password;

        $result = $this->market->request('signin.html', $params);
        $result = SecurityHelper::jsonDecode($result);

        if (isset($result['status']) && 'success' === $result['status']) {
            $kv = $this->container->get(KeyValueService::class);

            // V4.1+V4.2 协议：构建新版凭证结构
            $pluginData = [
                'user_id'            => (int)($result['data']['user_id'] ?? 0),
                'site_id'            => (string)($result['data']['site_id'] ?? ''),
                'domain'             => (string)($result['data']['domain'] ?? ''),
                'login_ip'           => (string)($result['data']['login_ip'] ?? ''),
                'access_token'       => (string)($result['data']['access_token'] ?? ''),
                'session_secret'     => (string)($result['data']['session_secret'] ?? ''),
                'public_key_status'  => (string)($result['data']['public_key_status'] ?? ''),
                'expires_at'         => (int)($result['data']['expires_at'] ?? 0),
            ];

            // 验证必要字段
            if (empty($pluginData['access_token']) || empty($pluginData['site_id'])) {
                return $this->errorMessage($this->language->get('signin_failed'), 4);
            }

            // 保存凭证到 plugin_data（数组格式直接存储）
            $kv->settingSet('plugin_data', $pluginData);

            // --- 登录后闭环：立即强制触发全量本地扩展同步，刷新版本与授权状态 ---
            $localPlugins = $this->extensionManager->getLocalList('plugin');
            $localThemes = $this->extensionManager->getLocalList('theme');
            $allDirs = array_merge(array_keys($localPlugins), array_keys($localThemes));
            $this->extensionManager->syncMarketData($allDirs, true);

            return $this->successMessage($this->language->get('signin_successfully'), 0, RequestUtils::server('HTTP_REFERER'));
        }

        // V4.2: 处理公钥不匹配错误（服务端返回 status=error, data.code=public_key_mismatch）
        $errorCode = $result['data']['code'] ?? $result['code'] ?? '';
        if ($errorCode === 'public_key_mismatch') {
            $kv = $this->container->get(KeyValueService::class);
            $pluginData = $kv->settingGet('plugin_data');
            $siteId = (string)($pluginData['site_id'] ?? '');
            $officialUrl = rtrim($this->appConfig['official_url'] ?? 'https://www.wellcms.com', '/');
            $unbindUrl = $officialUrl . '/my/sites' . (!empty($siteId) ? '?highlight=' . urlencode($siteId) : '');

            $message = $this->language->get('store_public_key_mismatch_detail')
                ?: '公钥不匹配，无法登录。这通常发生在服务器迁移或重装后。'
                   . '请前往官网用户中心解绑此站点，然后返回重新登录。';

            return $this->errorMessage($message, 4, '', 0, [
                'action_links' => [
                    [
                        'label'  => $this->language->get('store_unbind_site') ?: '前往官网解绑此站点',
                        'url'    => $unbindUrl,
                        'target' => '_blank',
                    ],
                ],
            ]);
        }

        return $this->errorMessage($result['data']['message'] ?? $result['message'] ?? $this->language->get('signin_failed'), 4);
    }

    /**
     * 下载并安装应用 (含升级)
     */
    public function download(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        // 下载/安装大文件可能耗时较长，解除 PHP 执行时间限制
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $storeId = RequestUtils::param('storeid', 0);

        if (!$dir) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        // 回退：旧缓存可能缺少 storeid，通过 queryExtensions 向服务端查询
        if (!$storeId) {
            $queryResult = $this->market->queryExtensions([$dir]);
            if (!empty($queryResult[$dir]['storeid'])) {
                $storeId = (int)$queryResult[$dir]['storeid'];
            }
        }

        if (!$storeId) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        $read = $this->extensionManager->readByDir($dir, 'plugin', false);
        if (empty($read)) {
            $read = $this->extensionManager->readByDir($dir, 'theme', false);
        }
        $extensionType = (2 === (int)($read['type'] ?? 1)) ? 'theme' : 'plugin';

        // P0 FIX: 本地付费校验，未购买禁止直接下载
        if ((int)($read['price'] ?? 0) > 0 && empty($read['payment_id'])) {
            return $this->errorMessage(
                $this->language->get('plugin_unpaid_tip'),
                1,
                $this->urlGenerator->url('admin/store/payment', ['dir' => $dir])
            );
        }

        // 使用统一部署逻辑 (物理安装 -> 脚本运行 -> 状态固化)
        $result = $this->extensionManager->deploy($dir, $extensionType, $storeId);

        if ($result['status'] === 'success') {
            $detailUrl = $this->urlGenerator->url(
                'admin/' . $extensionType . '/detail',
                ['dir' => $dir]
            );
            return $this->successMessage($this->language->get('install_successfully'), 0, $detailUrl, 2);
        }

        return $this->errorMessage($result['message'], 1);
    }

    /**
     * 升级应用
     */
    public function upgrade(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        return $this->download($request);
    }

    /**
     * 支付页面
     */
    public function payment(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));

        // ─── 第一步：调用服务端预下单 ───
        $params = $this->market->getCommonParams();
        $params['dir'] = $dir;

        $result = $this->market->request('payment.html', $params);
        $result = SecurityHelper::jsonDecode($result);

        $payToken = '';
        $alreadyPaid = false;
        $price = 0;
        $balance = 0;
        $requiresPassword = false;
        $payApi = 0;
        $qrUrl = '';

        if (isset($result['status']) && $result['status'] === 'success') {
            $data = $result['data'] ?? [];
            $alreadyPaid = $data['already_purchased'] ?? false;
            $payToken = $data['pay_token'] ?? '';
            $price = $data['price'] ?? 0;
            $balance = $data['balance'] ?? 0;
            $requiresPassword = $data['requires_password'] ?? false;
            $payApi = (int)($data['pay_api'] ?? 0);
            $qrUrl = (string)($data['url'] ?? '');
        } else {
            $errorMessage = $result['data']['message'] ?? $this->language->get('payment_prepare_failed');
            $errorCode = (int)($result['data']['code'] ?? 4);
            return $this->errorMessage($errorMessage, $errorCode, RequestUtils::server('HTTP_REFERER'));
        }

        // 如果已购买，直接跳转或提示
        if ($alreadyPaid) {
            return $this->successMessage($this->language->get('already_paid'), 0, RequestUtils::server('HTTP_REFERER'));
        }

        $data = [
            'header' => [
                'title' => $this->language->get('payment'),
            ],
            'action' => $this->urlGenerator->url('admin/store/postPayment'),
            'csrf_token' => $this->getCsrfToken($user['salt']),
            'pay_token' => $payToken,
            'dir' => $dir,
            'price' => $price,
            'balance' => $balance,
            'official_url' => $this->appConfig['official_url'] ?? 'https://www.wellcms.com',
            'requires_password' => $requiresPassword,
            'payment_tip' => $this->language->get('payment'),
            'qrcode' => !empty($qrUrl),
            'return' => [
                'pay_api' => $payApi,
                'url' => $qrUrl,
            ],
            'config' => [
                'rewrite' => $this->appConfig['url_rewrite_on'],
                'path' => $this->appConfig['path'],
            ],
            'language' => [
                'password' => $this->language->get('password'),
                'insufficient_balance_short' => $this->language->get('insufficient_balance_short'),
                'submit' => $this->language->get('submit'),
                'payment_password_tip' => $this->language->get('for_safe_input_official_signIn_password'),
                'secure_payment_auth' => $this->language->get('secure_payment_auth'),
                'scan_to_pay' => $this->language->get('scan_to_pay'),
                'waiting_transaction' => $this->language->get('waiting_transaction'),
                'success' => $this->language->get('success'),
                'payment_verified' => $this->language->get('payment_verified'),
                'processing' => $this->language->get('processing'),
            ]
        ];

        return $this->render('store_payment', $data, true);
    }

    /**
     * 支付弹窗数据接口（AJAX）
     */
    public function paymentDialog(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        if (!$dir) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        $params = $this->market->getCommonParams();
        $params['dir'] = $dir;

        $result = $this->market->request('payment.html', $params);
        $result = SecurityHelper::jsonDecode($result);

        if (!isset($result['status']) || $result['status'] !== 'success') {
            $errorCode = $result['data']['code'] ?? 4;
            $errorMsg = $result['data']['message'] ?? $this->language->get('payment_prepare_failed');
            return $this->errorMessage($errorMsg, $errorCode);
        }

        $data = $result['data'] ?? [];
        $alreadyPaid = $data['already_purchased'] ?? false;

        $officialUrl = rtrim($this->appConfig['official_url'] ?? 'https://www.wellcms.com', '/');

        if ($alreadyPaid) {
            return $this->successMessage(
                $this->language->get('already_paid'),
                0,
                '',
                0,
                ['data' => ['already_paid' => true]]
            );
        }

        return $this->successMessage(
            '',
            0,
            '',
            0,
            [
                'data' => [
                    'dir'               => $dir,
                    'price'             => (float)($data['price'] ?? 0),
                    'balance'           => (float)($data['balance'] ?? 0),
                    'pay_token'         => (string)($data['pay_token'] ?? ''),
                    'requires_password' => (bool)($data['requires_password'] ?? false),
                    'recharge_url'      => $officialUrl . '/my/recharge',
                ]
            ]
        );
    }

    /**
     * 提交支付请求
     */
    public function postPayment(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        $password = RequestUtils::param('password', '');
        $payToken = RequestUtils::param('pay_token', '');

        if (empty($dir) || empty($payToken)) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        $params = $this->market->getCommonParams();
        $params['dir'] = $dir;
        $params['pay_token'] = $payToken;
        $params['password'] = $password;

        $result = $this->market->request('payment.html', $params);
        $result = SecurityHelper::jsonDecode($result);

        if (isset($result['status']) && 'success' === $result['status']) {
            // 1. 强制同步本地授权状态（异常时静默降级，不阻塞主流程）
            $this->extensionManager->forceSyncSingle($dir);

            // 2. 构造详情页 URL（支付完成后引导用户到详情页手动触发下载安装）
            $detailUrl = $this->urlGenerator->url('admin/store/detail', ['dir' => $dir]);

            // 3. 返回带 redirect 的成功消息，由 GlobalFormHandler 自闭环
            return $this->successMessage(
                $result['data']['message'] ?? $this->language->get('payment_success'),
                0,
                $detailUrl
            );
        }

        $errorCode = $result['data']['code'] ?? '';
        $errorMsg = $result['data']['message'] ?? $this->language->get('payment_failed');

        if (in_array($errorCode, ['insufficient_balance', 3], true)) {
            $officialUrl = rtrim($this->appConfig['official_url'] ?? '', '/');
            $errorMsg .= ' <a href="' . $officialUrl . '/my/recharge" target="_blank">去充值</a>';
        }

        return $this->errorMessage($errorMsg, 4);
    }

    /**
     * 检查是否已购买
     */
    public function bought(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $dir = SafeHelper::safeWord(RequestUtils::param('dir'));
        if (!$dir) {
            return $this->errorMessage($this->language->get('params_error'), 4);
        }

        // ─── FIX: 调用专用 bought 端点，传递 dirs ───
        $result = $this->market->request('bought.html', ['dirs' => [$dir]]);
        $result = SecurityHelper::jsonDecode($result);

        if (isset($result['status']) && $result['status'] === 'success') {
            $data = $result['data'] ?? [];
            $item = $data[$dir] ?? [];
            if (!empty($item['payment_id'])) {
                return $this->successMessage($this->language->get('already_paid'), 0);
            }
        }

        return $this->errorMessage($this->language->get('waiting_payment'), 1);
    }

    /**
     * 同步官方数据
     */
    public function postSync(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $lastPostSync = (int)($this->container->get(KeyValueService::class)->settingGet('storePostSyncLastTime') ?? 0);
        $postSyncInterval = 60;
        if ((time() - $lastPostSync) < $postSyncInterval) {
            return $this->errorMessage(
                $this->language->get('well_store_sync_too_frequent')
                    . ' (' . ($postSyncInterval - (time() - $lastPostSync)) . 's)',
                1
            );
        }

        $localPlugins = $this->extensionManager->getLocalList('plugin');
        $localThemes = $this->extensionManager->getLocalList('theme');
        $allDirs = array_merge(array_keys($localPlugins), array_keys($localThemes));

        $this->extensionManager->syncMarketData($allDirs, true);
        $this->extensionManager->syncRemovedDirs();

        $this->container->get(KeyValueService::class)->settingSet('storePostSyncLastTime', time());

        return $this->successMessage($this->language->get('sync_success'), 0, RequestUtils::server('HTTP_REFERER'));
    }

    private function getOfficialPlugins(): array
    {
        if ($this->officialPlugins === null) {
            $this->initOfficialData();
        }
        return $this->officialPlugins;
    }

    private function initOfficialData(): void
    {
        $kv = $this->container->get(KeyValueService::class);
        $this->officialPlugins = $kv->settingGet('officialData') ?? [];
    }

}
