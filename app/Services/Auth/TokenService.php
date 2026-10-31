<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Framework\Utils\IpHelper;
use Framework\Utils\SecurityHelper;

/**
 * 令牌核心服务
 * 提供统一的 Token 生成与验证逻辑，支持协程环境
 */
class TokenService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var array 应用配置 */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * 捕获请求上下文
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $serverParams = $request->getServerParams();
        $this->setState('capturedSession', $request->getAttribute(\Framework\Session\SessionInterface::class));
        $this->setState('capturedUa', $serverParams['HTTP_USER_AGENT'] ?? '');
        $this->setState('capturedIp', IpHelper::ip($serverParams));
        $this->setState('capturedQuery', $serverParams['QUERY_STRING'] ?? '');

        $meta = $request->getAttribute('_route_meta', []);
        // 预判 AJAX 状态
        $httpXRequestedWith = $serverParams['HTTP_X_REQUESTED_WITH'] ?? '';
        $queryParams = $request->getQueryParams();
        $isAjax = strtolower(trim($httpXRequestedWith)) === 'xmlhttprequest' || isset($queryParams['api']) || (!empty($meta['api']));
        $this->setState('isAjax', $isAjax);

        // 预处理 Referer Query
        $referer = $serverParams['HTTP_REFERER'] ?? '';
        $refererQuery = $referer !== '' ? (string)parse_url($referer, PHP_URL_QUERY) : '';
        $this->setState('refererQuery', $refererQuery);
    }

    /**
     * 生成令牌 (CSRF/API)
     *
     * @param string $salt 用户盐值
     * @param array $extraData 附加数据
     * @return string
     */
    public function generateToken(string $salt = '', array $extraData = []): string
    {
        $userAgent = $this->getState('capturedUa', '');
        $queryString = $this->getState('capturedQuery', '');
        $capturedIp = $this->getState('capturedIp', '0.0.0.0');

        $session = $this->getState('capturedSession');
        if (!$session instanceof \Framework\Session\SessionInterface || $session->getId() === '') {
            throw new \LogicException('A current session is required to generate a form token.');
        }

        $data = array_merge([
            'long_ip' => $capturedIp,
            'user_agent' => $userAgent,
            'query_string' => $queryString,
            'time' => time()
        ], $extraData);

        if (!is_string($data['user_agent']) || !is_string($data['query_string'])) {
            throw new \InvalidArgumentException('Token request bindings must be strings.');
        }

        // 请求头和查询串是原始字节；摘要避免 JSON 编码改变生成/验证的绑定值。
        $data['token_version'] = 3;
        $data['user_agent_hash'] = hash('sha256', $data['user_agent']);
        $data['query_string_hash'] = hash('sha256', $data['query_string']);
        unset($data['user_agent'], $data['query_string']);

        $authKey = $this->appConfig['auth_key'] ?? '';
        $safeKey = empty($salt) ? $authKey : (SecurityHelper::decrypt($salt, $authKey) ?: $authKey);

        // 上传共用校验：绑定会话，不以 CDN 开关跳过令牌验证。
        $data['session_hash'] = hash_hmac('sha256', 'form-session:' . $session->getId(), $safeKey);
        return (string)SecurityHelper::generateToken($safeKey, $data);
    }

    /**
     * 验证令牌
     *
     * @param string $token 待验证令牌
     * @param string $salt 用户盐值
     * @param int $ttl 有效期(秒)
     * @param bool $checkQuery 是否校验 QueryString (通常用于非 AJAX 的 CSRF 验证)
     * @return bool
     */
    public function verifyToken(string $token, string $salt, int $ttl = 1800, bool $checkQuery = false): bool
    {
        if (empty($token)) return false;

        $authKey = $this->appConfig['auth_key'] ?? '';
        $safeKey = empty($salt) ? $authKey : (SecurityHelper::decrypt($salt, $authKey) ?: $authKey);

        $payload = SecurityHelper::decodeToken($safeKey, $token);
        if (!$payload || !is_array($payload)) return false;

        // 已签发的无版本令牌继续使用原始字符串绑定，未知版本不得降级验证。
        $version = array_key_exists('token_version', $payload) ? $payload['token_version'] : 1;
        if ($version !== 1 && $version !== 2 && $version !== 3) return false;

        $userAgent = $this->getState('capturedUa', '');
        $capturedIp = $this->getState('capturedIp', '0.0.0.0');

        // 新版表单令牌绑定当前会话；已签发旧版继续保持原 IP 绑定。
        if ($version === 3) {
            $session = $this->getState('capturedSession');
            $sessionHash = $payload['session_hash'] ?? null;
            if (!$session instanceof \Framework\Session\SessionInterface || $session->getId() === '' || !is_string($sessionHash)) return false;
            if (!hash_equals($sessionHash, hash_hmac('sha256', 'form-session:' . $session->getId(), $safeKey))) return false;
        } elseif (($payload['long_ip'] ?? 0) !== $capturedIp) {
            return false;
        }

        // 2. 校验 UserAgent
        if ($version === 2 || $version === 3) {
            $uaHash = $payload['user_agent_hash'] ?? null;
            if (!is_string($uaHash) || !hash_equals($uaHash, hash('sha256', $userAgent))) return false;
        } elseif (($payload['user_agent'] ?? '') !== $userAgent) {
            return false;
        }

        // 3. 校验有效期
        $issuedAt = $payload['time'] ?? null;
        if (!is_int($issuedAt) || time() - $issuedAt > $ttl) return false;

        // 4. 校验 QueryString (防跨站加强)
        if (!$checkQuery || $this->getState('isAjax')) return true;

        $refererQuery = $this->getState('refererQuery', '');
        if ($refererQuery === '') return true;
        if ($version === 1) return ($payload['query_string'] ?? '') === $refererQuery;

        $queryHash = $payload['query_string_hash'] ?? null;
        return is_string($queryHash) && hash_equals($queryHash, hash('sha256', $refererQuery));
    }
}
