<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Base;

use Framework\Http\Interfaces\ResponseInterface;

abstract class BaseController
{
    /** @var \Framework\Core\Container|null */
    protected $container;
    /** @var \Framework\Http\Interfaces\ServerRequestInterface */
    protected $request;
    /** @var array */
    protected $appConfig;
    /** @var array */
    protected $i18nConfig;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    protected $language;
    /** @var \Framework\Http\Routing\UrlGeneratorInterface */
    protected $urlGenerator;
    /** @var \App\Controllers\Base\ResponseFormatter */
    protected $responseFormatter;
    /** @var mixed */
    protected $userSession;
    /** @var \App\Services\Auth\UserService */
    protected $userService;
    /** @var \App\Services\System\MenuService */
    protected $menuService;
    /** @var \App\Controllers\Base\TemplateManager */
    protected $templateManager;
    /** @var \App\Services\Auth\TokenService */
    protected $tokenService;
    /** @var string */
    protected $ip;

    public function __construct(
        \Framework\Http\Interfaces\ServerRequestInterface $request,
        \App\Controllers\Base\ResponseFormatter $responseFormatter,
        \App\Interfaces\LanguageLoaderInterface $language,
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Auth\UserService $userService,
        \App\Services\System\MenuService $menuService,
        \App\Controllers\Base\TemplateManager $templateManager,
        \App\Services\Auth\TokenService $tokenService,
        array $appConfig,
        array $i18nConfig,
        ?\Framework\Core\Container $container = null
    ) {
        $this->request = $request;
        $this->responseFormatter = $responseFormatter;
        $this->language = $language;
        $this->urlGenerator = $urlGenerator;
        $this->userService = $userService;
        $this->menuService = $menuService;
        $this->templateManager = $templateManager;
        $this->tokenService = $tokenService;
        $this->appConfig = $appConfig;
        $this->i18nConfig = $i18nConfig;
        $this->container = $container;

        $serverParams = $this->request->getServerParams();
        $this->ip = \Framework\Utils\IpHelper::ip($serverParams);

        // 获取用户会话
        $this->userSession = $this->request->getAttribute(\Framework\Session\SessionInterface::class);

        // 自动捕获 UserService 上下文
        $this->userService->captureContext($request);
    }

    // $templateName = 模板名 / $data = 展示数据数组 / $templateDir = false 前台模板 true 后台模板
    /**
     * @param bool $templateDir
     */
    protected function render(string $templateName, array $data = [], $templateDir = false, string $id = ''): ResponseInterface
    {
        // hook app_Controllers_Base_BaseController_render_start.php
        try {
            $this->container->get(\App\Services\Storage\TempCleanupService::class)->maybeTriggerGC();
        } catch (\Throwable $e) {
            // 降级：TempCleanupService 不可用时不阻断页面渲染
        }

        $data = (array)$this->initializeCommonData($data);
        $data = (array)$this->processUserData($data);
        $data = (array)$this->setLanguageData($data);
        $data = (array)$this->setDebugData($data);

        $routeMeta = $this->request->getAttribute('_route_meta', []);
        $templatePath = (!empty($routeMeta['api']))
            ? ''
            : $this->templateManager->template($templateDir, $templateName, $id, $this->request);

        $data['website']['extra'] = $data['extra'] ?? [];

        // hook app_Controllers_Base_BaseController_render_end.php

        unset($data['extra'], $data['page_link'], $data['page_link_string']);

        /* echo '<pre>';
        echo '<hr>';
        print_r($data);
        echo '</pre>';
        exit; */

        return $this->responseFormatter->createFormatter($data, $templatePath, $this->request);
    }

    protected function parseSlug(string $slug): array{
        if ((int)($this->appConfig['url_rewrite_on'] ?? 0) > 1) {
            $linkSecret = $this->appConfig['link_secret'] ?? '123456';
            $params = \Framework\Utils\LinkHelper::parseSlug($slug, $linkSecret);
        } else {
            $arr = explode('.', $slug);
            $params = ['id' => $arr[0] ?? 0, 'created_at' => $arr[1] ?? 0];
        }

        return [$params, (int)($params['id'] ?? 0), (int)($params['created_at'] ?? 0)];
    }

    public function message(array $data = [], bool $templateDir = false): ResponseInterface
    {
        /* $data = $this->messageDataFarmat(
            $data['status'],
            $data['message'],
            $data['code'],
            $data['url'],
            $data['delay']
        ); */
        return $this->render('message', $data, $templateDir);
    }

    /**
     * 成功消息
     * @param string $message
     * @param mixed $code : 0 操作成功 / = string 返回表单name
     * @param string $url
     * @param int $delay
     * @param array $extraData 额外数据
     * @return ResponseInterface
     */
    public function successMessage(string $message, int $code = 0, string $url = '', int $delay = 3, array $extraData = []): ResponseInterface
    {
        $data = $this->messageDataFarmat('success', $message, $code, $url, $delay, $extraData);
        return $this->message($data);
    }

    /**
     * 错误消息
     * @param string $message
     * @param mixed $code
     * < 0 底层操作失败:-1:操作service数据库 / -2:缓存操作失败 / -3:操作外部API接口返回错误
     * > 0 业务逻辑错误:1:传参数错误 / 2:逻辑或判断参数错误 / 3:方法返回错误 / 4:GET参数不存在 / 5:GET参数错误 / 6:POST参数不存在 / 7:POST参数错误 / 8:验证数据错误 / 9:功能未开启 / 10:目录或文件不存在 / 11:未配置参数或参数错误 / 12:请求返回错误 / 13:升级失败 / 14:保存文件失败 / 15:权限不足 / 16:禁止操作
     * = string 返回错误表单name
     * @param string $url
     * @param int $delay
     * @param array $extraData 额外数据
     * @return ResponseInterface
     */
    public function errorMessage(string $message, $code, string $url = '', int $delay = 3, array $extraData = []): ResponseInterface
    {
        $data = $this->messageDataFarmat('error', $message, $code, $url, $delay, $extraData);
        return $this->message($data);
    }
    /**
     * 消息数据格式化
     * @param string $status
     * @param string $message
     * @param mixed $code
     * @param string $url
     * @param int $delay
     * @param array $extraData
     * @return array
     */
    protected function messageDataFarmat($status, $message, $code, $url, $delay, array $extraData = [])
    {
        $out = [
            'code' => $code,
            'message' => $message,
            'status' => $status,
            //'success' => ($code === 0),
            'data' => [
                'title' => $this->language->get('operation_notice'),
                'keywords' => $this->language->get('operation_notice'),
                'description' => $this->language->get('operation_notice'),
                'redirect' => $url ? ['url' => $url, 'delay' => $delay] : null,
                'modal' => 1,
            ],
            'timestamp' => time(),
        ];

        if (!empty($extraData)) {
            $out = array_replace_recursive($out, $extraData);
        }

        return $out;
    }

    /**
     * Generate CSRF Token
     * @param string $salt = $user['salt']
     * @return string
     */
    protected function getCsrfToken(string $salt): string
    {
        return $this->tokenService->generateToken($salt);
    }

    /**
     * Verify CSRF Token
     * @param string $token
     * @param string $salt = $user['salt']
     * @return bool
     */
    protected function verifyCsrfToken(string $token, string $salt): bool
    {
        return $this->tokenService->verifyToken($token, $salt, $this->appConfig['csrf_ttl'] ?? 1800, true);
    }

    /**
     * @return void
     */
    private function initializeCommonData(array $data)
    {
        // hook app_Controllers_Base_BaseController_initializeCommonData_start.php

        /** @var \App\Services\Storage\StorageManager $storage */
        $storage = $this->container->get(\App\Services\Storage\StorageManager::class);
        $data = array_merge($data, [
            'website' => [
                'current' => [
                    'domain' => \App\Utils\HttpLink::httpUrl(),
                    'view' => $storage->getViewPath(),
                    'path' => $this->appConfig['path'],
                    'page_link' => $data['page_link'] ?? '',
                    'page_link_string' => $data['page_link_string'] ?? ''
                ],
                'header' => $data['header'] ?? [],
                'sitename' => $this->appConfig['sitename'],
                'title' => $this->appConfig['title'],
                'static_version' => $this->appConfig['static_version'],
                'copyright' => 'CopyRight © ' . date('Y') . ' All Rights Reserved',
                'running' => ['processed_time' => \Framework\Database\Collector\QueryCollector::processedTime()]
            ]
        ]);
        isset($data['runtime_data']) && $data['website']['current']['runtime'] = $data['runtime_data'];

        // hook app_Controllers_Base_BaseController_initializeCommonData_end.php

        unset($data['header'], $data['runtime_data']);
        return $data;
    }

    /**
     * 处理用户相关数据
     */
    protected function processUserData(array $data): array
    {
        // hook app_Controllers_Base_BaseController_processUserData_start.php

        $user = $data['user'] ?? ($this->userService->getCurrentUser() ?: ['group_id' => 0]);
        $param0 = (string)($this->request->getQueryParams()[0] ?? '');

        // hook app_Controllers_Base_BaseController_processUserData_before.php

        // 调用菜单服务
        $userData = $this->menuService->getUserMenuData($user, $param0);
        $data = array_merge($data, $userData);

        // hook app_Controllers_Base_BaseController_processUserData_end.php

        return $data;
    }

    /**
     * 设置语言相关数据
     */
    private function setLanguageData(array $data): array
    {
        // hook app_Controllers_Base_BaseController_setLanguageData_start.php

        // 使用实例化后的 request 获取参数
        $locale = $this->request->getQueryParams()['locale'] ?? $this->request->getAttributes()['locale'] ?? $this->i18nConfig['locale'];
        $data['website']['locale']['default'] = $locale;

        // hook app_Controllers_Base_BaseController_setLanguageData_end.php

        return $data;
    }

    private function setDebugData(array $data): array
    {
        // hook app_Controllers_Base_BaseController_setDebugData_start.php

        $sqlLog = \Framework\Database\Collector\QueryCollector::getLoggedQueries();
        $data['website']['running']['sql_count'] = count($sqlLog);

        // hook app_Controllers_Base_BaseController_setDebugData_before.php

        if (\defined('DEBUG') && \DEBUG > 1) {
            $requestData = array_merge(
                /** 获取 Cookie 参数 */
                $this->request->getCookieParams() ?? [],
                /** 获取 GET 参数 */
                $this->request->getQueryParams() ?? [],
                /** 获取 POST 参数 */
                $this->request->getParsedBody() ?? []
                /** 获取文件上传参数 */
                //$this->request->getUploadedFiles() ?? []
                /** 获取属性 */
                //$this->request->getAttributes() ?? []
            );
            $data['website']['running']['request'] = \Framework\Utils\SafeHelper::txtToHtml(print_r($requestData, true));
            $data['website']['running']['sqls'] = $sqlLog;

            // hook app_Controllers_Base_BaseController_setDebugData_after.php
        }

        // hook app_Controllers_Base_BaseController_setDebugData_end.php

        return $data;
    }

    public function validateUsername(string $username): string
    {
        $len = mb_strlen($username, 'UTF-8');
        if ($len < 2) return $this->language->get('username_is_too_short');

        if ($len > 16) return $this->language->get('username_too_long', array('length' => $len));

        // 中文、英文、数字、-、_
        if (!preg_match('#^[\x{4e00}-\x{9fa5}a-zA-Z0-9._-]+$#u', $username)) return $this->language->get('incorrect_username_format');

        return 'success';
    }

    public function validateEmail(string $email): string
    {
        $email = strtolower($email);
        $len = mb_strlen($email, 'UTF-8');
        if ($len > 32) {
            return $this->language->get('email_too_long', ['length' => $len]);
        }

        if (!preg_match('#^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z]{2,})+$#', $email)) return $this->language->get('email_format_incorrect');

        return 'success';
    }

    /**
     * Keyset 翻页执行器
     *
     * @param callable $fetchAdapter  由 makeGenericAdapter 构造的适配器
     * @param int      $pageSize      每页条数
     * @param mixed    $cursorId      游标ID（上一页用 firstId，下一页用 lastId），支持 int/string(UUID)
     * @param string   $orderKey      用于取首尾ID的字段名（如 'tag_id'）
     * @param int      $orderBy       -1: DESC, 1: ASC
     * @param string   $dirFlag       'next' | 'previous'
     * @param bool     $inclusiveFirst 首屏深链接是否包含边界
     *
     * @return array{0: array<int,array<string,mixed>>, 1: bool, 2: mixed, 3: mixed}
     */
    public function fetchPaged(
        callable $fetchAdapter,
        int $pageSize,
        $cursorId,
        string $orderKey,
        int $orderBy = -1,
        string $dirFlag = 'next',
        bool $inclusiveFirst = false
    ): array {
        $pageSize = max(1, $pageSize);
        $dirFlag = ($dirFlag === 'previous') ? 'previous' : 'next';
        $orderBy = ((int)$orderBy === 1) ? 1 : -1;

        $limit = $pageSize + 1;

        // 首页/无游标不加过滤
        $op = null;
        if ($cursorId !== null && $cursorId !== 0) {
            $op = $this->cursorOp($orderBy, $dirFlag, $inclusiveFirst);
        }

        // Keyset 翻页核心逻辑：向后翻页需要临时反转排序，获取最邻近的 N 条数据
        $realOrderBy = $orderBy;
        if ('previous' === $dirFlag) {
            $realOrderBy = ($orderBy === 1) ? -1 : 1;
        }

        /** @var array<int, array<string,mixed>> $items */
        $items = (array)$fetchAdapter($op, $cursorId, $realOrderBy, $limit);

        $hasMore = count($items) > $pageSize;
        if ($hasMore) array_pop($items);

        // 如果是反向获取的数据，需要反转回来，保持调用方预期的顺序（通常是时间正序或倒序）
        if ('previous' === $dirFlag) {
            $items = array_reverse($items);
        }

        $firstId = 0;
        $lastId = 0;
        if (!empty($items)) {
            $first = reset($items);
            $last = end($items);
            $firstId = $first[$orderKey] ?? 0;
            $lastId = $last[$orderKey] ?? 0;
            reset($items);
        }

        return [$items, $hasMore, $firstId, $lastId];
    }

    /**
     * 根据排序与方向，返回严格/包含的比较符
     * DESC: next => < / <=, previous => > / >=
     * ASC : next => > / >=, previous => < / <=
     */
    private function cursorOp(int $orderBy, string $dirFlag, bool $inclusive = false): string
    {
        // DESC
        if (-1 === $orderBy) {
            if ('next' === $dirFlag) return $inclusive ? '<=' : '<';
            return $inclusive ? '>=' : '>';
        }
        // ASC
        if ('next' === $dirFlag) return $inclusive ? '>=' : '>';
        return $inclusive ? '<=' : '<';
    }

    /**
     * 组装分页链接：
     * - previous 使用 firstId
     * - next     使用 lastId
     *
     * $params 形如："{page}|{cursorId}|{dirFlag}[|masterId|maxId]"
     */
    public function buildPaginationLinks(
        array &$pagination,
        int $page,
        $firstId,
        $lastId,
        bool $hasNext,
        string $base,
        array $extra = [],
        int $maxId = 0,
        int $masterId = 0
    ): void {
        $pagination['previous_link'] = '';
        $pagination['next_link'] = '';
        $pagination['ops'] = [
            'prev' => null,
            'next' => null
        ];

        $extra['maxId'] = $maxId;
        $masterId && $extra['masterId'] = $masterId;

        // “上一页”
        if ($page > 1 && $firstId > 0) {
            $prevExtra = $extra;
            $prevExtra['page'] = $page - 1;
            $prevExtra['cursorId'] = $firstId;
            $prevExtra['dirFlag'] = 'previous';
            $url = $this->urlGenerator->url($base, $prevExtra);

            $pagination['previous_link'] = $url;
            $pagination['ops']['prev'] = [
                'url' => $url,
                'label' => $this->language->get('previous_page'),
                'class' => 'pagination-prev-link'
            ];
        }

        // “下一页”
        if ($hasNext && $lastId > 0) {
            $nextExtra = $extra;
            $nextExtra['page'] = $page + 1;
            $nextExtra['cursorId'] = $lastId;
            $nextExtra['dirFlag'] = 'next';
            $url = $this->urlGenerator->url($base, $nextExtra);

            $pagination['next_link'] = $url;
            $pagination['ops']['next'] = [
                'url' => $url,
                'label' => $this->language->get('next_page'),
                'class' => 'pagination-next-link'
            ];
        }

        // 首页 (如果需要，也可以在这里增加)
        $pagination['ops']['first'] = [
            'url' => $this->urlGenerator->url($base, array_merge($extra, ['page' => 1])),
            'label' => $this->language->get('home_page'),
            'class' => 'pagination-first-link'
        ];
    }

    /**
     * 基础适配器构建器：仅支持单字段范围/游标
     * 对应 A 签名时，返回 [ '<' => 100 ] 这种操作符集合
     * 兼容形态：['<='=>100] 或 ['tag_id'=>['<='=>100]] 或 ['$lte'=>100]
     */
    public static function simpleConditionBuilder(
        array $baseOps,
        string $indexKey,
        ?string $op,
        $cursorId
    ): array {
        // 兼容 mongo 风格操作符
        static $m2s = ['$lt' => '<', '$lte' => '<=', '$gt' => '>', '$gte' => '>=', '$eq' => '='];
        static $ok  = ['<' => 1, '<=' => 1, '>' => 1, '>=' => 1, '=' => 1];

        $ops = [];

        // 取出 base 的“内层”集合
        if (isset($baseOps[$indexKey]) && is_array($baseOps[$indexKey])) {
            $baseOps = $baseOps[$indexKey];
        }

        foreach ($baseOps as $k => $v) {
            $k = $m2s[$k] ?? $k;
            if (isset($ok[$k])) $ops[$k] = $v;
        }

        // 叠加游标条件（上一页/下一页的 < / > / <= / >=）
        if ($op !== null && $cursorId !== null) {
            $ops[$op] = $cursorId;
        }

        return $ops; // 注意：不带字段名
    }

    /**
     * 复合适配器构建器：支持固定字段 + 范围/游标字段
     * 适用于 KEY (a, b, id) 这种复合索引，支持 a=1 AND b=2 AND id < 100 格式
     */
    public static function compoundConditionBuilder(
        array $baseCond,
        string $indexKey,
        ?string $op,
        $cursorId
    ): array {
        static $m2s = ['$lt' => '<', '$lte' => '<=', '$gt' => '>', '$gte' => '>=', '$eq' => '='];
        static $ok  = ['<' => 1, '<=' => 1, '>' => 1, '>=' => 1, '=' => 1];

        $condition = [];
        $indexOps = [];

        // 遍历 baseCondition 分离固定条件与操作符
        foreach ($baseCond as $k => $v) {
            $realK = $m2s[$k] ?? $k;
            if (isset($ok[$realK])) {
                // 如果键名本身是操作符，归类为 indexKey 的范围限制
                $indexOps[$realK] = $v;
            } else {
                // 普通字段，如 'status' => 0
                $condition[$k] = $v;
            }
        }

        // 叠加游标
        if ($op !== null && $cursorId !== null) {
            $indexOps[$op] = $cursorId;
        }

        // 如果有索引字段的操作符，合入 condition
        if (!empty($indexOps)) {
            $condition[$indexKey] = $indexOps;
        }

        return $condition;
    }

    /**
     * 通用适配器：兼容 A/B 两种签名
     *
     * A) method(array $condition, array $orderby, int $page, int $pageSize, string $indexKey, array $fields)
     * B) method(int $masterId, array $condition, array $orderby, int $page, int $pageSize, string $indexKey, array $fields)
     *
     * $repoMethod: 任意可调用，如 [$repo,'findByXxx']
     * $options = [
     *   —— 必/常用
     *   'orderKey'         => 'comment_id',   // 排序字段（用于构造 $orderby）
     *   'indexKey'         => 'comment_id',   // 过滤字段（用于构造 $condition）
     *   'baseCondition'    => [],             // 固定条件
     *   'fields'           => ['*'],          // 字段列
     *   —— 带主ID（masterId）时
     *   'hasMasterId'      => bool,      // true/false=强制
     *   'masterId'         => null|int,       // hasMasterId=true 时必填
     *   —— 可选
     *   'conditionBuilder' => callable|null,  // 默认 simpleConditionBuilder（返回内层操作符集合）
     * - baseOnFirstOnly: true=baseCondition 只用于“首页锚点”；false=始终携带（锁定子集）
     * ]
     *
     * 返回的闭包签名：fn(?string $op, ?int $cursorId, int $orderBy, int $limit): array
     *
     * @return callable
     */
    public static function makeGenericAdapter(callable $repoMethod, array $options)
    {
        $orderKey = isset($options['orderKey']) ? $options['orderKey'] : 'id';
        $indexKey = isset($options['indexKey']) ? $options['indexKey'] : $orderKey;
        $baseCond = isset($options['baseCondition']) ? $options['baseCondition'] : [];
        $fields = isset($options['fields']) ? $options['fields'] : ['*'];
        $condBuilder = isset($options['conditionBuilder']) ? $options['conditionBuilder'] : [self::class, 'simpleConditionBuilder'];

        // 锁定子集：false（默认）；仅首页锚点：true
        $baseOnFirstOnly = !empty($options['baseOnFirstOnly']);

        $hasMasterId = !empty($options['hasMasterId']);
        $masterId = isset($options['masterId']) ? $options['masterId'] : null;

        if ($hasMasterId && !isset($masterId)) {
            throw new \InvalidArgumentException('An ID must be provided when makeGenericAdapter: hasMasterId=true');
        }

        return function (?string $op, $cursorId, int $orderBy, int $limit) use (
            $repoMethod,
            $orderKey,
            $indexKey,
            $baseCond,
            $fields,
            $condBuilder,
            $hasMasterId,
            $masterId,
            $baseOnFirstOnly
        ): array {
            // 锚点/子集策略：有游标且仅首页用锚点 => 本次不带 base；否则带上
            $baseForThisCall = ($baseOnFirstOnly && null !== $cursorId) ? [] : $baseCond;

            // 让 builder 返回查询条件
            $innerOps = call_user_func($condBuilder, $baseForThisCall, $indexKey, $op, $cursorId);

            // 排序子句
            $orderby = [$orderKey => ((1 === (int)$orderBy) ? 1 : -1)];

            // A/B 两种签名参数
            // 特殊逻辑：如果使用了复合构建器且没有强制 masterId，则倾向于使用签名 A (因为复合条件通常已包含所有筛选字段)
            $isCompound = is_array($condBuilder) && $condBuilder[1] === 'compoundConditionBuilder';

            $args = ($hasMasterId && !$isCompound)
                ? [$masterId, $innerOps, $orderby, 1, $limit, $indexKey, $fields]
                : [$innerOps, $orderby, 1, $limit, $indexKey, $fields];

            /** @var array $ret */
            $ret = call_user_func_array($repoMethod, $args);
            return $ret;
        };
    }
}

/*
================================================================================
分页适配器与翻页执行器 (Keyset Pagination) 使用指南
================================================================================

1. 场景 A：基础单字段索引翻页 (使用 simpleConditionBuilder)
--------------------------------------------------------------------------------
适用：只有游标字段需要过滤，或者 Service 方法签名支持 (int $masterId, array $innerOps, ...) 格式。

$nodeId = 123;
$adapter = BaseController::makeGenericAdapter(
    [$nodesService, 'findThreadsByNodeId'], // 假设签名：method(int $nodeId, array $innerOps, ...)
    [
        'orderKey'         => 'id', // 排序字段
        'indexKey'         => 'id', // 游标过滤字段
        'baseCondition'    => [],   // 无额外固定条件
        'conditionBuilder' => [BaseController::class, 'simpleConditionBuilder'],
        'hasMasterId'      => true,
        'masterId'         => $nodeId,
    ]
);

2. 场景 B：复合索引多字段翻页 (使用 compoundConditionBuilder)
--------------------------------------------------------------------------------
适用：需要多个固定等值条件（如 category_id, status），且 Service 使用通用 find(array $condition, ...) 签名。
这种方式能最大化发挥数据库复合索引 (category_id, status, id) 的性能。

$categoryId = 5;
$adapter = BaseController::makeGenericAdapter(
    [$threadService, 'find'],          // 使用通用的 $condition 数组签名，类($threadService)->find(array $condition, ...)
    [
        'orderKey'         => 'id',
        'indexKey'         => 'id',       // 游标应用于 id 字段
        'baseCondition'    => [
            'category_id' => $categoryId, // 固定条件：版块ID
            'status'      => 0,           // 固定条件：正常状态
        ],
        'conditionBuilder' => [BaseController::class, 'compoundConditionBuilder'],
        'hasMasterId'      => false,      // 复合模式下通常不需要 masterId 干扰
    ]
);

3. 翻页执行与结果处理 (fetchPaged)
--------------------------------------------------------------------------------
在 Controller Action 中调用：

// 获取前端传入的参数
$page = param('page', 1);
$cursorId = param('cursorId', 0); // 支持数字 ID 或 UUID 字符串
$dir = param('dir', 'next');

// 执行查询
[$items, $hasMore, $firstId, $lastId] = $this->fetchPaged(
    $adapter,
    20,        // 每页条数
    $cursorId, // 当前游标
    'id',      // 排序键名
    -1,        // -1: 降序 (DESC), 1: 升序 (ASC)
    $dir       // 翻页方向
);

// 此时生成的 SQL 逻辑：
// 首页 (next, cursorId=0):
//    WHERE category_id=5 AND status=0 ORDER BY id DESC LIMIT 21
// 下一页 (next, cursorId=100):
//    WHERE category_id=5 AND status=0 AND id < 100 ORDER BY id DESC LIMIT 21
// 上一页 (previous, cursorId=80):
//    WHERE category_id=5 AND status=0 AND id > 80 ORDER BY id ASC LIMIT 21 (取回后续反转)

4. 进阶：首页锚点保护与显式字段锁定 (Anchor Protection)
--------------------------------------------------------------------------------
在处理海量数据时，为了防止翻页过程中因新数据插入导致列表出现重复，并充分利用分区索引：

$maxId = (int)param('maxId', 0);
if (0 === $maxId) $maxId = $threadService->maxid(); // 首页进入时获取当前最大ID作为锚点

$adapter = BaseController::makeGenericAdapter(
    [$threadService, 'find'],
    [
        'orderKey'         => 'created_at', // 配合时间分区
        'indexKey'         => 'created_at',
        'baseCondition'    => [
            'category_id' => $categoryId,
            // 写法 1：显式字段名定义业务锚点。推荐使用，语义最清晰。
            'created_at' => ['<=' => $maxId],
            // 写法 2：通配符简写。会自动映射到 indexKey 字段。
            // '<=' => $maxId,
        ],
        'conditionBuilder' => [BaseController::class, 'compoundConditionBuilder'],
    ]
);

5. 返回给前台的数据
--------------------------------------------------------------------------------
return $this->successMessage('success', 0, '', 0, [
    'items'    => $items,
    'hasMore'  => $hasMore,
    'firstId'  => $firstId,
    'lastId'   => $lastId,
    'page'     => $page,
    'params'   => ['maxId' => $maxId], // 务必将锚点透传给前端，后续翻页请求需带回
]);
================================================================================
*/