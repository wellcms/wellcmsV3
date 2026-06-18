<?php

/**
 * MarketClient - 应用商店客户端（重构后）
 *
 * 绑定凭证验证协议 V4 实现
 *
 * 职责：
 * 1. 基础 API 通讯封装
 * 2. 绑定凭证管理（access_token）
 * 3. 临时会话密钥管理（session_key）
 * 4. 请求重试与退避
 * 5. 降级策略
 * 6. 会话续约
 */

declare(strict_types=1);

namespace App\Services\Market;

use Framework\Core\Traits\StatefulTrait;
use Framework\Logger\LoggerInterface;
use Framework\Utils\{IpHelper, Runtime, SecurityHelper};
use App\Services\System\KeyValueService;
use App\Services\Market\MarketConstants;

class MarketClient
{
    use StatefulTrait;

    /** @var KeyValueService */
    protected $kv;

    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;

    /** @var array */
    protected $appConfig;

    /** @var string */
    protected $apiUrl = 'https://www.wellcms.com/';

    /** @var string */
    protected $apiBasePath = 'api/v1/store';

    /** @var \Framework\Core\Container */
    protected $container;

    /** @var RetryPolicy */
    protected $retryPolicy;

    /** @var MarketCircuitBreaker */
    protected $circuitBreaker;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
        $this->kv = $container->get(KeyValueService::class);
        $this->cache = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
        $this->appConfig = $container->get('appConfig');
        $this->logger = $container->get(LoggerInterface::class);
        $this->retryPolicy = new RetryPolicy();
        $this->circuitBreaker = new MarketCircuitBreaker($this->kv);

        $this->registerCleanupHook();
    }

    /**
     * 注册请求结束清理钩子
     */
    protected function registerCleanupHook(): void
    {
        if (Runtime::isSwoole() && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::defer(function () {
                $this->clearStates();
            });
        }
        if (!Runtime::isSwoole()) {
            register_shutdown_function(function () {
                $this->clearStates();
            });
        }
    }

    /**
     * 协程安全的毫秒级延迟
     */
    private function sleepMs(int $milliseconds): void
    {
        if (Runtime::inCoroutine()) {
            \Swoole\Coroutine\System::sleep($milliseconds / 1000);
        } else {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * 发送 API 请求
     *
     * @param string $path API 路径（不含基础路径）
     * @param array $data 请求数据
     * @return string 响应内容
     */
    public function request(string $path, array $data = []): string
    {
        $fullPath = $this->apiBasePath . '/' . ltrim($path, '/');
        return $this->requestWithRetry($fullPath, $data);
    }

    /**
     * 带重试和降级的请求
     */
    protected function requestWithRetry(string $path, array $data): string
    {
        $maxRetries = $this->retryPolicy->getMaxRetries();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->doRequest($path, $data);

                if ($response === '') {
                    if ($attempt < $maxRetries) {
                        $this->sleepMs($this->retryPolicy->getDelay($attempt));
                        continue;
                    }
                    $this->circuitBreaker->recordFailure();
                    return $this->degradedResponse('Service temporarily unavailable');
                }

                $result = json_decode($response, true);

                // ─── 先读取 status，再从 data.code 读取错误码 ───
                $status = $result['status'] ?? '';

                if ($status === 'error') {
                    // 兼容两种服务端错误格式
                    // 格式 A (Controller errorMessage): {"status":"error","data":{"message":"...","code":...}}
                    // 格式 B (ExceptionHandler): {"status":"error","code":403,"message":"...","data":[]}
                    $errorCode = (int)($result['data']['code'] ?? $result['code'] ?? 0);

                    // 会话过期，刷新后重试
                    if ($errorCode === 401 && $attempt < $maxRetries) {
                        $this->logger->warning('Session expired, refreshing', [
                            'path' => $path,
                            'attempt' => $attempt
                        ]);
                        $this->clearSession();
                        continue;
                    }

                    // 服务端维护中
                    if ($errorCode === 503) {
                        return $this->degradedResponse('Service maintenance');
                    }

                    // 403 账号被封禁 / IP 变动 或 未购买等业务错误
                    if ($errorCode === 403) {
                        $errorMessage = $result['data']['message'] ?? $result['message'] ?? '';
                        // 未购买属于业务错误，不应清除凭证；只有安全类 403 才清除
                        $purchaseIndicators = ['purchased', 'purchase', 'buy', 'bought', '购买', '订单'];
                        foreach ($purchaseIndicators as $indicator) {
                            if (stripos($errorMessage, $indicator) !== false) {
                                return $response; // 保留原始响应让调用方处理
                            }
                        }
                        $this->kv->settingDelete('plugin_data');
                        return $this->degradedResponse('Account suspended or IP changed');
                    }

                    // 需要重试的错误码（503 在上面已直接降级，不重复处理）
                    if (in_array($errorCode, [429, 423, 500, 502, 504], true)) {
                        if ($attempt < $maxRetries) {
                            $this->sleepMs($this->retryPolicy->getDelay($attempt));
                            continue;
                        }
                        $this->circuitBreaker->recordFailure();
                        return $this->degradedResponse($result['data']['message'] ?? $result['message'] ?? 'Service error');
                    }

                    // 业务错误（如余额不足、密码错误、参数错误等），保留原始响应让调用方处理
                    return $response;
                }

                // 成功响应
                if ($status === 'success') {
                    $this->circuitBreaker->recordSuccess();
                    return $response;
                }

                // 未知状态，降级
                return $this->degradedResponse('Unknown response status');

            } catch (\Framework\Utils\HttpClientNetworkException $e) {
                $this->logger->error('Network error', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                if ($attempt < $maxRetries) {
                    $this->sleepMs($this->retryPolicy->getDelay($attempt));
                    continue;
                }
                $this->circuitBreaker->recordFailure();
                return $this->degradedResponse('Network error');
            } catch (\Exception $e) {
                $this->logger->error('Request error', [
                    'path' => $path,
                    'error' => $e->getMessage()
                ]);
                $this->circuitBreaker->recordFailure();
                return $this->degradedResponse('Request error');
            }
        }

        $this->circuitBreaker->recordFailure();
        return $this->degradedResponse('Max retries exceeded');
    }

    /**
     * 执行实际请求
     */
    protected function doRequest(string $path, array $data): string
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($path, '/');

        // 登录请求不需要凭证验证
        $isLoginRequest = (strpos($path, 'signin') !== false);

        // 1. 获取绑定凭证（非登录请求需要）
        $credentials = null;
        if (!$isLoginRequest) {
            $credentials = $this->getCredentials();
            if (empty($credentials)) {
                throw new \RuntimeException('Not logged in', 401);
            }
        }

        // 2. 构建请求配置
        if ($isLoginRequest) {
            return $this->doLoginRequest($url, $data);
        }

        // 3. 获取或申请会话密钥
        $session = $this->getOrRefreshSession($credentials);

        // 4. 构建请求数据
        $requestData = $this->buildRequestData($credentials, $data);

        // 5. 使用 session_key 签名
        $requestData['sign'] = SignatureHelper::signRequest($requestData, $session['key']);

        // 6. V4.2: 生成 ECDSA 签名
        $ecdsaSignature = $this->signRequest($requestData);

        // 7. 发送请求
        $headers = [
            'User-Agent: ' . IpHelper::userAgent(),
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Session-Hash: ' . hash('sha256', $session['key']),
            'X-Site-ID: ' . $credentials['site_id'],
        ];

        // V4.2: 附加 ECDSA 签名（业务接口强制要求）
        if (!empty($ecdsaSignature)) {
            $headers[] = 'X-Client-Signature: ' . $ecdsaSignature;
        }

        $config = [
            'method' => 'POST',
            'url' => $url,
            'body' => SecurityHelper::jsonEncode($requestData),
            'timeout' => 30,
            'headers' => $headers,
            'followRedirects' => true,
            'verifySSL' => false,
            'caBundle' => '/tmp/',
            'returnResponse' => false,
        ];

        try {
            if (!Runtime::isSwoole()) {
                set_time_limit(0);
            }
            $client = new \Framework\Utils\HttpClient();
            return (string) $client->request($config);
        } catch (\Throwable $e) {
            $this->logger->error('HTTP request failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * 执行登录请求（不需要凭证）
     */
    protected function doLoginRequest(string $url, array $data): string
    {
        // 确保包含 login_ip
        if (!isset($data['login_ip'])) {
            $data['login_ip'] = $this->getCurrentIp();
        }

        // V4.2: 确保携带 public_key（如果本地已生成）
        $publicKey = $this->getPublicKey();
        if (!empty($publicKey)) {
            $data['public_key'] = $publicKey;
        }

        $requestData = array_merge([
            'time' => time(),
            'nonce' => \Framework\Utils\SafeHelper::randomStr(16),
        ], $data);

        $config = [
            'method' => 'POST',
            'url' => $url,
            'body' => SecurityHelper::jsonEncode($requestData),
            'timeout' => 30,
            'headers' => [
                'User-Agent: ' . IpHelper::userAgent(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'verifySSL' => false,
            'caBundle' => '/tmp/',
        ];

        try {
            $client = new \Framework\Utils\HttpClient();
            return (string) $client->request($config);
        } catch (\Throwable $e) {
            $this->logger->error('Login request failed', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * 构建请求数据（包含绑定字段）
     */
    protected function buildRequestData(array $credentials, array $data): array
    {
        return [
            // 基础字段
            'time' => time(),
            'nonce' => \Framework\Utils\SafeHelper::randomStr(16),

            // 绑定字段（每次请求必须携带）
            'user_id' => $credentials['user_id'],
            'site_id' => $credentials['site_id'],
            'domain' => $credentials['domain'],
            'login_ip' => $this->getCurrentIp(),

            // 访问令牌
            'access_token' => $credentials['access_token'],

            // 业务数据
            'data' => $data,
        ];
    }

    /**
     * 获取绑定凭证
     */
    public function getCredentials(): ?array
    {
        $pluginData = $this->kv->settingGet('plugin_data') ?? null;
        if (empty($pluginData)) return null;
        // plugin_data 是数组格式直接存储
        if (is_array($pluginData)) {
            // 检查必要字段（V4 协议要求的全部字段）
            $requiredFields = ['access_token', 'site_id', 'user_id', 'domain'];
            foreach ($requiredFields as $field) {
                if (empty($pluginData[$field])) return null;
            }

            // ─── 校验 token 是否过期 ───
            $expiresAt = (int)($pluginData['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                $this->logger->warning('Access token expired, clearing credentials');
                $this->logout();
                return null;
            }

            return $pluginData;
        }

        return null;
    }

    /**
     * 获取 ECDSA 私钥（加载或生成）
     *
     * V4.2: 每个 WellCMS 实例生成唯一的 ECDSA P-256 密钥对，
     * 私钥保存在本地文件，永不离开服务器。
     *
     * @return string PEM 格式私钥
     */
    protected function getPrivateKey(): string
    {
        // 优先使用协程安全的状态存储
        $cached = $this->getState('ec_private_key');
        if ($cached !== null && $cached !== '') {
            return $cached;
        }

        $keyPath = $this->getKeyPath();

        // 如果私钥文件已存在，直接读取
        if (is_file($keyPath)) {
            $privKey = file_get_contents($keyPath);
            if ($privKey === false) {
                throw new \RuntimeException('Failed to read existing private key: permission denied or I/O error');
            }
            if ($privKey !== '') {
                $this->setState('ec_private_key', $privKey);
                return $privKey;
            }
            $this->logger->warning('Private key file exists but is empty, regenerating');
        }

        // 生成新的 ECDSA P-256 密钥对
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];

        $privKey = openssl_pkey_new($config);
        if (!$privKey) {
            throw new \RuntimeException('Failed to generate ECDSA key pair: ' . openssl_error_string());
        }

        $privPem = '';
        if (!openssl_pkey_export($privKey, $privPem)) {
            throw new \RuntimeException('Failed to export private key: ' . openssl_error_string());
        }

        // 原子保存私钥文件（权限 600）
        $this->savePrivateKey($privPem, $keyPath);

        $this->setState('ec_private_key', $privPem);
        return $privPem;
    }

    /**
     * 获取 ECDSA 公钥（从私钥派生）
     *
     * @return string PEM 格式公钥
     */
    protected function getPublicKey(): string
    {
        $privateKey = $this->getPrivateKey();
        $keyResource = openssl_pkey_get_private($privateKey);
        if ($keyResource === false) {
            throw new \RuntimeException('Invalid private key format');
        }
        $details = openssl_pkey_get_details($keyResource);

        if (!$details || empty($details['key'])) {
            throw new \RuntimeException('Failed to extract public key from private key');
        }

        return $details['key'];
    }

    /**
     * 对请求参数做 ECDSA 签名
     *
     * @param array $params 请求参数
     * @return string base64 编码的签名
     */
    protected function signRequest(array $params): string
    {
        $privateKey = $this->getPrivateKey();
        $payload = $this->canonicalize($params);

        $signature = '';
        $result = openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$result) {
            throw new \RuntimeException('ECDSA sign failed: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * 规范化请求参数（用于 ECDSA 签名）
     *
     * 【关键】必须与服务端 ClientAuthService::canonicalize() 完全一致
     *
     * @param array $params
     * @return string
     */
    protected function canonicalize(array $params): string
    {
        // 1. 排除签名相关字段（HMAC签名 + 防御性排除ECDSA签名）
        unset($params['sign'], $params['client_signature']);

        // 2. 递归排序
        $sorted = $this->recursiveSort($params);

        // 3. 统一 JSON 选项
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * 递归排序数组键
     *
     * @param mixed $data
     * @return mixed
     */
    protected function recursiveSort($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        // 判断是否关联数组
        if ($this->isAssocArray($data)) {
            ksort($data, SORT_STRING);
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursiveSort($value);
            }
            return $data;
        }

        // 索引数组：保持顺序，递归处理元素
        return array_map([$this, 'recursiveSort'], $data);
    }

    /**
     * 判断是否为关联数组
     */
    protected function isAssocArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * 原子保存私钥到本地文件
     *
     * @param string $privateKeyPem
     * @param string $path
     */
    protected function savePrivateKey(string $privateKeyPem, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $tmpFile = $path . '.tmp.' . uniqid('', true);
        $result = file_put_contents($tmpFile, $privateKeyPem, LOCK_EX);

        if ($result === false) {
            throw new \RuntimeException('Failed to write private key to temp file');
        }

        chmod($tmpFile, 0600);

        if (!rename($tmpFile, $path)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Failed to move private key to final path');
        }
    }

    /**
     * 获取私钥文件路径
     */
    protected function getKeyPath(): string
    {
        return APP_PATH . 'storage/.wellcms/ec_key.pem';
    }

    /**
     * 获取或刷新会话
     */
    protected function getOrRefreshSession(array $credentials): array
    {
        $now = time();

        // 1. 检查内存中的会话
        $sessionKey = $this->getState('session_key');
        $sessionExpires = $this->getState('session_expires', 0);

        if ($sessionKey !== null && ($sessionExpires - $now) > MarketConstants::CLIENT_SESSION_MIN_REMAINING) {
            // 快过期时尝试续约（续约失败不影响当前请求）
            if (($sessionExpires - $now) < MarketConstants::SESSION_RENEW_THRESHOLD) {
                $renewSuccess = $this->renewSession($credentials);
                // 如果续约失败且会话已非常接近过期，申请新会话
                if (!$renewSuccess && ($sessionExpires - $now) < 30) {
                    $this->clearSession();
                    return $this->requestNewSession($credentials);
                }
            }
            return [
                'key' => $sessionKey,
                'user_id' => $this->getState('session_user_id', 0),
            ];
        }

        // 2. 申请新会话
        return $this->requestNewSession($credentials);
    }

    /**
     * 申请新会话
     */
    protected function requestNewSession(array $credentials): array
    {
        $now = time();
        $url = rtrim($this->apiUrl, '/') . '/api/v1/session/request.html';

        // 构建申请数据
        $requestData = [
            'time' => time(),
            'nonce' => \Framework\Utils\SafeHelper::randomStr(16),
            'user_id' => $credentials['user_id'],
            'site_id' => $credentials['site_id'],
            'domain' => $credentials['domain'],
            'login_ip' => $this->getCurrentIp(),
            'access_token' => $credentials['access_token'],
        ];

        // 注意：申请 session 时不需要签名，靠 access_token 验证

        $config = [
            'method' => 'POST',
            'url' => $url,
            'body' => SecurityHelper::jsonEncode($requestData),
            'timeout' => 30,
            'headers' => [
                'Content-Type: application/json',
                'X-Site-ID: ' . $credentials['site_id'],
            ],
            'verifySSL' => false,
            'caBundle' => '/tmp/',
        ];

        try {
            $client = new \Framework\Utils\HttpClient();
            $response = (string) $client->request($config);
        } catch (\Throwable $e) {
            $this->logger->error('Session request failed', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to request session: ' . $e->getMessage(), 500);
        }

        $result = SecurityHelper::jsonDecode($response);

        if (!isset($result['status']) || $result['status'] !== 'success') {
            $errorCode = (int)($result['code'] ?? 500);

            $this->logger->error('Session request failed', [
                'code' => $errorCode,
                'message' => $result['message'] ?? 'Unknown error'
            ]);

            if ($errorCode === 403) {
                $this->kv->settingDelete('plugin_data');
                throw new \RuntimeException('Account suspended or IP changed, please re-login', 403);
            }

            throw new \RuntimeException(
                $result['message'] ?? 'Session request failed',
                $errorCode
            );
        }

        // V4.1+: 使用 session_secret 派生密钥解密 session_key
        $sessionSecret = $credentials['session_secret'] ?? '';
        if (!empty($sessionSecret)) {
            $siteKey = hash('sha256', $sessionSecret . $credentials['site_id']);
        } else {
            // V4.0 兼容回退
            $siteKey = hash('sha256', $this->appConfig['auth_key'] . $credentials['site_id']);
        }

        $sessionKeyEncoded = $result['data']['session_key'] ?? null;
        if ($sessionKeyEncoded === null) {
            throw new \RuntimeException('Session key missing in response');
        }
        $sessionKey = SecurityHelper::decrypt(
            base64_decode((string)$sessionKeyEncoded, true),
            $siteKey,
            'AES-256-CBC'
        );

        if ($sessionKey === false) {
            throw new \RuntimeException('Failed to decrypt session key');
        }

        // 存储到内存
        $expiresIn = (int)($result['data']['expires_in'] ?? MarketConstants::SESSION_TTL);
        $this->setState('session_key', $sessionKey);
        $this->setState('session_expires', $now + $expiresIn);
        $this->setState('session_user_id', (int)$result['data']['user_id']);

        return [
            'key' => $sessionKey,
            'user_id' => (int)$result['data']['user_id'],
        ];
    }

    /**
     * 续约会话
     *
     * @return bool 续约是否成功
     */
    protected function renewSession(array $credentials): bool
    {
        $url = rtrim($this->apiUrl, '/') . '/api/v1/session/renew.html';
        $sessionKey = $this->getState('session_key');

        if ($sessionKey === null) {
            return false;
        }

        $requestData = [
            'time' => time(),
            'nonce' => \Framework\Utils\SafeHelper::randomStr(16),
            'user_id' => $credentials['user_id'],
            'site_id' => $credentials['site_id'],
            'domain' => $credentials['domain'],
            'login_ip' => $this->getCurrentIp(),
            'access_token' => $credentials['access_token'],
        ];

        $requestData['sign'] = SignatureHelper::signRequest($requestData, $sessionKey);

        $config = [
            'method' => 'POST',
            'url' => $url,
            'body' => SecurityHelper::jsonEncode($requestData),
            'timeout' => 10,
            'headers' => [
                'Content-Type: application/json',
                'X-Session-Hash: ' . hash('sha256', $sessionKey),
            ],
            'verifySSL' => false,
            'caBundle' => '/tmp/',
        ];

        try {
            $client = new \Framework\Utils\HttpClient();
            $response = (string) $client->request($config);
            $result = SecurityHelper::jsonDecode($response);

            if (isset($result['status']) && $result['status'] === 'success') {
                $expiresIn = (int)($result['data']['expires_in'] ?? MarketConstants::SESSION_TTL);
                $this->setState('session_expires', time() + $expiresIn);
                $this->logger->info('Session renewed successfully');
                return true;
            }
        } catch (\Throwable $e) {
            // 续约失败，不影响当前请求
            $this->logger->warning('Session renew failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * 清除会话
     */
    protected function clearSession(): void
    {
        $this->unsetState('session_key');
        $this->unsetState('session_expires');
        $this->unsetState('session_user_id');
    }

    /**
     * 获取当前IP
     */
    protected function getCurrentIp(): string
    {
        return IpHelper::ip();
    }

    /**
     * 降级响应
     */
    protected function degradedResponse(string $message): string
    {
        return SecurityHelper::jsonEncode([
            'status' => 'error',
            'data' => [
                'message' => $message,
                'code' => 503,
            ],
        ]);
    }

    /**
     * 获取站点唯一标识
     */
    public function getSiteId(): string
    {
        $key = $this->appConfig['auth_key'] ?? '';
        // 移除动态 IP，使用固定盐值，确保 site_id 稳定
        return md5($key . 'wellcms_store_v4_binding');
    }

    /**
     * 检查是否已登录
     */
    public function isLogged(): bool
    {
        return $this->getCredentials() !== null;
    }

    /**
     * 登出
     */
    public function logout(): void
    {
        $this->clearSession();
        $this->kv->settingDelete('plugin_data');
        $this->kv->settingDelete('pluginData');
    }

    /**
     * 获取公共请求参数
     */
    public function getCommonParams(): array
    {
        return [
            'app_url' => \App\Utils\HttpLink::httpUrlPath(),
            'domain' => IpHelper::host(),
            'site_id' => $this->getSiteId(),
        ];
    }

    /**
     * 批量查询扩展信息
     *
     * @param array $items 扩展目录列表
     * @return array
     */
    public function queryExtensions(array $items): array
    {
        $dirs = [];
        foreach ($items as $item) {
            $dirs[] = is_string($item) ? $item : ($item['dir'] ?? '');
        }

        $dirs = array_filter($dirs);
        if (empty($dirs)) {
            return [];
        }

        $response = $this->request('query.html', ['dirs' => $dirs]);
        $result = json_decode($response, true);

        if (isset($result['status']) && $result['status'] === 'success') {
            return $result['data'] ?? [];
        }

        return [];
    }

    /**
     * 协商 API 版本
     *
     * @return string
     */
    public function negotiateVersion(): string
    {
        return 'v2';
    }

    /**
     * 通知服务端下载完成（清理临时文件）
     *
     * 客户端下载完成后调用，通知服务端清理该用户的下载临时文件
     *
     * @param int $userId 用户ID
     * @return bool 是否成功
     */
    public function notifyDownloadComplete(int $userId): bool
    {
        try {
            $credentials = $this->getCredentials();
            if (empty($credentials)) {
                $this->logger->warning('Cannot notify download complete: not logged in');
                return false;
            }

            // 验证 user_id 一致性
            if ((int)$credentials['user_id'] !== $userId) {
                $this->logger->error('User ID mismatch in notifyDownloadComplete', [
                    'expected' => $credentials['user_id'],
                    'provided' => $userId
                ]);
                return false;
            }

            $response = $this->request('download/complete.html', [
                'user_id' => $userId,
            ]);

            $result = json_decode($response, true);
            $success = isset($result['status']) && $result['status'] === 'success';

            if ($success) {
                /* $this->logger->info('Download complete notified', [
                    'user_id' => $userId,
                    'cleaned_files' => $result['data']['cleaned_files'] ?? 0
                ]); */
            } else {
                $this->logger->warning('Download complete notification failed', [
                    'user_id' => $userId,
                    'response' => $result
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->logger->error('Notify download complete failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
