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

        $data = array_merge([
            'long_ip' => $capturedIp,
            'user_agent' => $userAgent,
            'query_string' => $queryString,
            'time' => time()
        ], $extraData);

        $authKey = $this->appConfig['auth_key'] ?? '';
        $safeKey = empty($salt) ? $authKey : (SecurityHelper::decrypt($salt, $authKey) ?: $authKey);

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

        $userAgent = $this->getState('capturedUa', '');
        $capturedIp = $this->getState('capturedIp', '0.0.0.0');

        // 1. 校验 IP
        if (($payload['long_ip'] ?? 0) !== $capturedIp) return false;

        // 2. 校验 UserAgent
        if (($payload['user_agent'] ?? '') !== $userAgent) return false;

        // 3. 校验有效期
        if (time() - ($payload['time'] ?? 0) > $ttl) return false;

        // 4. 校验 QueryString (防跨站加强)
        if ($checkQuery) {
            if (!$this->getState('isAjax')) {
                $refererQuery = $this->getState('refererQuery', '');
                if ($refererQuery !== '') {
                    if (($payload['query_string'] ?? '') !== $refererQuery) return false;
                }
            }
        }

        return true;
    }
}
