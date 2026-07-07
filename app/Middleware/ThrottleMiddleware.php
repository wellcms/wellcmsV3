<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Utils\IpHelper;

class ThrottleMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var \App\Controllers\Base\MessageController */
    private $message;
    /** @var \App\Services\Auth\UserService */
    private $userService;
    /** @var \App\Services\Auth\GroupService */
    private $groupService;
    /** @var \App\Services\System\IpListService */
    private $ipListService;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;
    /** @var \App\Session\Service\SessionManager */
    private $sessionManager;
    /** @var array */
    private $sessionConfig;
    /** @var array */
    private $cacheConfig;
    /** @var array */
    private $appConfig;
    /** @var array */
    private $bots = [];
    /** @var array */
    private $botList = [];
    /** @var bool */
    private $verifyRobot = false;
    /** @var bool */
    private $verifyDns = false;
    /** @var array */
    private $staticExt = [];
    /** @var array */
    private $urlPrefixAllow = [];
    /** @var array */
    private $staticPathWhitelist = [];
    /** @var array */
    private $staticContentTypeWhitelist = [];
    /** @var bool */
    private $debug = false;

    public function __construct(
        array $appConfig,
        array $cacheConfig,
        array $sessionConfig,
        \Framework\Core\Container $container,
        \App\Services\Auth\UserService $userService,
        \App\Services\Auth\GroupService $groupService,
        \App\Services\System\IpListService $ipListService,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \Framework\Logger\LoggerInterface $logger,
        \App\Session\Service\SessionManager $sessionManager
    ) {
        $this->container = $container;
        $this->appConfig = $appConfig ?? [];
        $this->cacheConfig = $cacheConfig ?? [];
        $this->sessionConfig = $sessionConfig ?? [];
        $this->userService = $userService;
        $this->groupService = $groupService;
        $this->ipListService = $ipListService;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        $this->debug = \defined('DEBUG') ? (bool)\DEBUG : false;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): ResponseInterface
    {
        try {

            $rateConf = $this->sessionConfig['rate_limit'] ?? [];
            $this->urlPrefixAllow = $rateConf['url_prefix_allow'] ?? ['admin', 'my', 'api'];
            $this->staticExt = $rateConf['static_ext'] ?? ['css' => 1, 'js' => 1, 'png' => 1, 'jpg' => 1, 'jpeg' => 1, 'gif' => 1, 'svg' => 1, 'woff' => 1, 'woff2' => 1];
            $this->staticPathWhitelist = $rateConf['path_whitelist'] ?? ['app/views', 'plugins', 'themes', 'storage/upload'];
            $this->staticContentTypeWhitelist = $rateConf['content_type_whitelist'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp', 'text/css', 'application/javascript', 'font/woff', 'font/woff2'];
            $this->botList = $rateConf['bot_list'] ?? [];
            $this->verifyDns = (bool)($rateConf['verify_dns'] ?? false);
            $this->bots = empty($rateConf['bots']) ? [] : $rateConf['bots'];

            // 白名单：后台 & 静态资源
            if ($this->urlAllow($request) || $this->isRealStatic($request)) return $handler->handle($request);

            // 1. 获取由 SessionMiddleware 注入的 Session 对象
            /** @var mixed */
            $session = $request->getAttribute(\Framework\Session\SessionInterface::class);
            if (empty($session)) return $handler->handle($request);

            // 手动触发快照捕获，确保只有通过过滤的业务请求才更新活跃 URL (Manual capture)
            $this->sessionManager->collectContext($request);

            $sessionId = $session->getId();

            // 确保请求属性中有 _session_id
            $request = $request->withAttribute('_session_id', $sessionId);

            $now = time();
            $userAgent = $request->getServerParams()['HTTP_USER_AGENT'] ?? '';

            // IP & 设备指纹提取
            $ip = IpHelper::ip() ?: $request->getServerParams()['REMOTE_ADDR'] ?: '0.0.0.0';
            $fingerprint = $request->getHeaderLine('X-Device-Fingerprint') ?: md5($userAgent . $ip);

            // 综合行为 Key
            $behaviorKey = 'throttle:behavior:' . md5($ip . $fingerprint);

            $userId = 0;
            $this->userService->captureContext($request);
            $user = $request->getAttribute('user', null) ?? $this->userService->getCurrentUser(0);
            if (!empty($user['id'])) {
                // 用户信息挂载
                $request = $request->withAttribute('user', $user);
                $userId = (int)($user['id'] ?? 0);
                if ($userId && empty($session->get('user_id'))) {
                    $session->set('user_id', $userId);
                }

                $groupId = (int)$user['group_id'];
                if (
                    $this->groupService->access($groupId, 'punishment') ||
                    $this->groupService->access($groupId, 'reward') ||
                    $this->groupService->access($groupId, 'ban') ||
                    $this->groupService->access($groupId, 'administer')
                ) {
                    return $handler->handle($request);
                }
            }

            // 空UA限制访问
            if (empty($userAgent)) return $this->restrictAccess($sessionId, $ip, $userId, $now, 1);


            // 路由元数据级限流参数覆盖 (Meta-driven Override)
            $routeMeta = $request->getAttribute('_route_meta', []);
            if (!empty($routeMeta['api_rate_limit']) && is_array($routeMeta['api_rate_limit'])) {
                // 深度合并，确保仅覆盖定义的子参数，其余保留全局默认策略（如白名单等）
                $rateConf = array_replace_recursive($rateConf, $routeMeta['api_rate_limit']);
            }

            $addBlacklist = (bool)($rateConf['add_to_blacklist'] ?? false);

            // 限流功能未开启
            if (!(bool)($rateConf['enable'] ?? false)) return $handler->handle($request);

            // 检查行为墙：阶梯惩罚逻辑
            $violationKey = 'violation_count:' . $behaviorKey;
            $violations = (int)($this->cache->get($violationKey) ?: 0);
            if ($violations > 0) {
                // 惩罚时长：随着违规次数呈指数级增长 60s, 3600s, 86400s...
                $penaltyTime = (6 ** min($violations, 8)) * 10;
                $lastViolation = (int)($this->cache->get('last_violation_time:' . $behaviorKey) ?: 0);
                if ($now - $lastViolation < $penaltyTime) {
                    return $this->restrictAccess($sessionId, $ip, $userId, $now, 9); // 返回行为限制错误
                }
            }

            // 全部黑名单走分布式Redis或持久缓存，杜绝静态内存爆炸
            if ((bool)($rateConf['verify_blacklist'] ?? false) && $this->inBlacklist($ip)) return $this->restrictAccess($sessionId, $ip, $userId, $now, 0);

            // 验证搜索引擎爬虫关闭 || 返回true蜘蛛放行，false伪造 / 继续向下走流程
            $this->verifyRobot = (bool)($rateConf['verify_robot'] ?? false);
            if (!$this->verifyRobot || $this->isCrawler($userAgent, $ip)) return $handler->handle($request);

            // 此处可检测共享的 Token 失败计数
            $tokenFailKey = 'token_fail_count:' . $sessionId;
            if ((int)($this->cache->get($tokenFailKey) ?: 0) >= 3) {
                return $this->restrictAccess($sessionId, $ip, $userId, $now, 6, true); // Token 多次失败直接加黑
            }

            $params = array_merge(
                $request->getQueryParams() ?? [],
                $request->getParsedBody() ?? []
            );

            $api = (string)($params['api'] ?? '');
            $apiOn = $this->appConfig['api_on'] ?? 0;
            $apiKey = (string)($params['apiKey'] ?? $request->getHeaderLine('X-API-Key') ?? '');
            if ($api && (!$apiOn || !$apiKey || $apiKey !== ($this->appConfig['apiKey'] ?? ''))) {
                return $this->restrictAccess($sessionId, $ip, $userId, $now, 2);
            }

            $stores = $this->cacheConfig['stores'] ?? [];
            // 未配置缓存
            if (empty($stores) || isset($stores['mysql'])) return $handler->handle($request);

            $cacheThrottleCfg = $rateConf['cache'] ?? [];
            // 限流三层：yac/apcu、本机、memcached、redis
            $cacheKey = 'cacheThrottle:' . $behaviorKey; // 使用行为 Key 替代纯 IP
            // 第1层：本机粗限流
            if (isset($stores['yac']) || isset($stores['apcu'])) {
                $cacheKeys = array_keys($stores);
                $cfgDefault = isset($cacheThrottleCfg['default']) ? $cacheThrottleCfg['default'] : [];
                if (!$this->cache->allow($cacheKey, ($cfgDefault['capacity'] ?? 10), ($cfgDefault['rate'] ?? 5), [$cacheKeys[0]])) {
                    return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 7);
                }
            }

            // 第2层：Memcached 跨进程
            if (isset($stores['memcached'])) {
                $memCfg = isset($cacheThrottleCfg['memcached']) ? $cacheThrottleCfg['memcached'] : [];
                if (!$this->cache->allow($cacheKey, ($memCfg['capacity'] ?? 100), ($memCfg['rate'] ?? 10), ['memcached'])) {
                    return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 7);
                }
            }

            // 第3层：Redis 全局跨进程
            if (isset($stores['redis'])) {
                $redisCfg = isset($cacheThrottleCfg['redis']) ? $cacheThrottleCfg['redis'] : [];
                if (!$this->cache->allow($cacheKey, ($redisCfg['capacity'] ?? 200), ($redisCfg['rate'] ?? 20), ['redis'])) {
                    return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 7);
                }
            }

            // 三维限流参数
            $sessLimit = (int)($rateConf['session']['limit'] ?? 30);
            $sessWindow = (int)($rateConf['session']['window'] ?? 60) + random_int(0, 15);
            $ipLimit = (int)($rateConf['ip']['limit'] ?? 10);
            $ipWindow = (int)($rateConf['ip']['window'] ?? 600) + random_int(0, 15);

            $cookieThrottleCfg = $rateConf['cookie'] ?? [];
            $cookieName = ($this->sessionConfig['pre'] ?? 'well_') . 'session_id';
            $hasSessCookie = $request->getCookieParams()[$cookieName] ?? null;
            // 客户端连续不带 Cookie ≤ 2次 判定采集
            $noCcookieWindow = ($cookieThrottleCfg['window'] ?? 120) + random_int(0, 30); // 增加随机扰动
            if (!$hasSessCookie && $this->isOverLimit('noCookie:' . $behaviorKey, ($cookieThrottleCfg['limit'] ?? 2), $noCcookieWindow)) {
                return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 5, $addBlacklist);
            }

            // -------------以下开始确保可以生成固定sid---------------
            // Session 限流
            if ($this->isOverLimit('sessionRateLimit:' . $sessionId, $sessLimit, $sessWindow)) {
                return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 3, $addBlacklist);
            }

            // UA 滚动 ≥ 3 次判爬虫
            $uaKey = 'throttleUA:' . $sessionId;
            $result = $this->cache->get($uaKey);
            if ($result) {
                if ($result['lastUA'] !== md5($userAgent)) {
                    $result['lastUA'] = md5($userAgent);
                    $result['count'] += 1;
                }
                if ($result['count'] >= 3) {
                    return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 5, $addBlacklist);
                }
                $this->cache->set($uaKey, $result, 600);
            } else {
                $this->cache->set($uaKey, ['lastUA' => md5($userAgent), 'count' => 1], 600);
            }

            // IP+行为限流
            if ($this->isOverLimit('throttleBehaviorRate:' . $behaviorKey, $ipLimit, $ipWindow)) {
                return $this->handleViolation($behaviorKey, $sessionId, $ip, $userId, $now, 5);
            }

            return $handler->handle($request);
        } catch (\Throwable $e) {
            // 路由未命中属于正常 HTTP 语义，不应被限流层接管
            if ($e instanceof \Framework\Exception\Http\NotFoundException) {
                throw $e;
            }

            $this->logger->error("Error in " . get_class($this) . ": " . $e->getMessage(), ['exception' => $e]);

            if (\defined('DEBUG') && \DEBUG >= 2) {
                throw $e;
            }

            $message = $this->container->get(\App\Controllers\Base\MessageController::class);

            // 保持现有错误码 8 不变
            return $message->errorMessage('Throttle verification error', 8);
        }
    }

    // URL 前缀放行
    private function urlAllow(\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');
        foreach ($this->urlPrefixAllow as $prefix) {
            if (strncmp($path, $prefix, strlen($prefix)) === 0) return true;
        }
        return false;
    }

    private function isRealStatic(\Framework\Http\Interfaces\ServerRequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');
        $docRoot = $request->getServerParams()['DOCUMENT_ROOT'] ?? '';
        if (empty($docRoot)) return false;

        // 路径白名单短路
        foreach ($this->staticPathWhitelist as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                // 标准扩展，直接放行
                if (isset($this->staticExt[$ext])) {
                    return true;
                }

                // “.php.xxx”可疑扩展查Content-Type
                if (preg_match('/\.php\.[a-z0-9]+$/i', $path)) {
                    $file = realpath($docRoot . DIRECTORY_SEPARATOR . $path);
                    if (!$file || !is_file($file)) {
                        return false;
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $contentType = $finfo->file($file);
                    return in_array($contentType, $this->staticContentTypeWhitelist, true);
                }
            }
        }

        return false;
    }

    /**
     * 通用：判断某个缓存 Key 是否超过限流阈值
     *
     * @param string $cacheKey   缓存 Key，例如 "rl:session:abcd1234"
     * @param int    $limit      单位时间窗口内最大允许次数
     * @param int    $window     时间窗口长度（秒）
     * @return bool  若超过限流，返回 true；否则更新计数并返回 false。
     */
    private function isOverLimit(string $cacheKey, int $limit, int $window): bool
    {
        $count = $this->cache->increment($cacheKey, 1, $window);
        return $count >= $limit;
    }

    /**
     * 处理违规行为：增加分值并记录时间
     */
    private function handleViolation(string $behaviorKey, ?string $sessionId, string $ip, int $userId, int $now, int $reason = 0, bool $addBlacklist = false): ResponseInterface
    {
        $violationKey = 'violation_count:' . $behaviorKey;
        $count = (int)($this->cache->get($violationKey) ?: 0);

        $this->cache->set($violationKey, $count + 1, 86400); // 违规记录保留 24 小时
        $this->cache->set('last_violation_time:' . $behaviorKey, $now, 86400);

        return $this->restrictAccess($sessionId, $ip, $userId, $now, $reason, $addBlacklist);
    }

    /**
     * 限流统一响应处理
     * @param string|null $sessionId
     * @param string $ip
     * @param int $userId
     * @param int $now
     * @param int $reason
     * @param bool $addBlacklist = true:写入黑名单
     * @return ResponseInterface
     */
    private function restrictAccess(?string $sessionId, string $ip, int $userId, int $now, int $reason = 0, bool $addBlacklist = false): ResponseInterface
    {
        try {
            if ($addBlacklist) {
                $this->addToBlacklist($userId, $reason, $ip);
            }
        } catch (\Throwable $e) {
            $this->logger->error("AddToBlacklist failed: " . $e->getMessage());
        }

        $message = $this->container->get(\App\Controllers\Base\MessageController::class);

        $extraData = [];
        if ($this->debug && !empty($this->appConfig['throttle_diag']['enabled_in_debug'])) {
            $extraData['data']['diagnostic'] = [
                'reason' => $reason,
                'stage'  => $this->resolveThrottleStage($reason),
            ];
        }

        // 保持现有错误码 15 不变
        return $message->errorMessage('Access Denied【' . $reason . '】', 15, '', 3, $extraData);
    }

    private function resolveThrottleStage(int $reason): string
    {
        $map = [
            1 => 'empty_user_agent',
            2 => 'api_auth_failed',
            3 => 'session_rate_limit',
            5 => 'behavior_rate_limit',
            6 => 'token_fail_limit',
            7 => 'cache_rate_limit',
            8 => 'verification_failed',
            9 => 'behavior_penalty',
        ];

        return isset($map[$reason]) ? $map[$reason] : 'unknown';
    }

    /** 黑名单检查 */
    private function inBlacklist(string $ip): bool
    {
        // 内网IP放行
        if (IpHelper::isPrivate($ip)) return false;

        $result = $this->ipListService->readByCacheIp($ip); // 服务层已缓存
        $isBlack = $result && (int)$result['type'] === 1;

        return $isBlack;
    }

    /** 持久化写黑名单，全部走ipListService+缓存 */
    private function addToBlacklist(int $userId, int $reason, string $ip): bool
    {
        if (IpHelper::isPrivate($ip)) return false;

        $lockKey = 'ip_blacklist_lock:' . $ip;
        if ($this->cache->isLocked($lockKey)) return true; // 锁已存在直接返回

        $token = $this->cache->lock($lockKey, 60) ?? null; // 获取锁，过期时间60秒
        if (!$token) return true;

        try {

            // 1. 双重检查：缓存+数据库
            $result = $this->ipListService->readByCacheIp($ip);
            if ($result) {
                $this->cache->unlock($lockKey, $token);
                return true;
            }

            $data = [
                'user_id' => $userId,
                'ip' => $ip,
                'type' => 1,
                'reason' => $reason,
                'created_at' => time()
            ];
            $success = false;
            $retry = 0;
            $maxRetry = 3;

            // 2. 先插入数据库（确保持久化）
            while ($retry < $maxRetry) {
                try {
                    $this->ipListService->insert($data);
                    $success = true;
                    break;
                } catch (\Throwable $e) {
                    $retry++;
                    if ($retry < $maxRetry) usleep(100000);
                }
            }

            // 3. 数据库成功后再更新缓存
            if ($success) {
                $this->cache->set('readByIp:' . $ip, $data, 86400 * 30); // 设置30天缓存
            }
        } finally {
            $this->cache->unlock($lockKey, $token); // 确保锁释放
        }

        return $success;
    }

    /**
     * 检测爬虫请求 false:限流 / true:放行
     */
    private function isCrawler( string $userAgent= '', string $ip = ''): bool
    {
        if (!$this->verifyRobot) return false;

        // 内网IP放行
        if (IpHelper::isPrivate($ip)) return true;

        // 爬虫限流
        if (empty($userAgent)) return false;

        foreach ($this->botList ?? [] as $bot => $val) {
            if (stripos($userAgent, $bot) !== false) {
                // 特定爬虫限流
                if (empty($val['enable'])) return false;

                // 伪造爬虫限流
                if ($this->verifyDns && !$this->verifyBotDNS($val['host'], $ip)) return false;

                return true;
            }
        }

        if (empty($this->bots)) return true;

        // 爬虫特征识别
        foreach ($this->bots as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * IP反查验证爬虫
     */
    private function verifyBotDNS($hostData, string $ip): bool
    {
        $dnsCacheKey = 'bot_dns:' . $ip;
        $host = $this->cache->get($dnsCacheKey);
        if (!$host) {
            $host = gethostbyaddr($ip);
            $this->cache->set($dnsCacheKey, $host, 300);
        }

        // 反查失败限流
        if (empty($host) || $host === $ip) return false;

        if (is_string($hostData)) return $this->verifyHostDomain($hostData, $host);

        foreach ($hostData as $domain) {
            if (!$domain) continue;
            if ($this->verifyHostDomain($domain, $host)) return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    private function verifyHostDomain($domain, $host)
    {
        return strlen($domain) <= strlen($host) && substr_compare($host, $domain, -strlen($domain), null, true) === 0;
    }
}