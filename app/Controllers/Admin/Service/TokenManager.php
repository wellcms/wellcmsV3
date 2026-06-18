<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Controllers\Admin\Service;

use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Utils\{IpHelper, SecurityHelper, CookieHelper};

/**
 * 后台令牌管理器
 * 负责后台登录状态的加密校验与续期
 */
class TokenManager
{
    use \Framework\Core\Traits\StatefulTrait;

    const TOKEN_EXPIRY = 3600;
    const TOKEN_REFRESH = 1800;
    const COOKIE_PREFIX = 'admin_token';

    /** @var array */
    protected $adminBindIp;
    /** @var array */
    protected $sessionConfig;
    /** @var string */
    protected $authKey;

    public function __construct(array $appConfig, array $sessionConfig)
    {
        $this->adminBindIp = $appConfig['admin_bind_ip'] ?? 0;
        $this->sessionConfig = $sessionConfig;
        $this->authKey = $appConfig['auth_key'] ?? '';
    }

    private function getRequest(): ServerRequestInterface
    {
        $request = \Framework\Http\Psr7\RequestStack::getCurrent();
        if (!$request) {
            throw new \RuntimeException('Request context missing in TokenManager');
        }
        return $request;
    }

    private function getUser(): array
    {
        $user = $this->getRequest()->getAttribute('user');
        if (empty($user)) {
            throw new \RuntimeException('User context missing in TokenManager');
        }
        return $user;
    }

    public function adminTokenCheck(): bool
    {
        $request = $this->getRequest();
        $adminToken = $request->getCookieParams()[$this->sessionConfig['pre'] . self::COOKIE_PREFIX] ?? '';
        if (empty($adminToken)) return false;

        $tokenData = $this->decodeToken($adminToken);
        if (empty($tokenData)) return false;

        if (!$this->isTokenValid($tokenData)) return false;

        $this->refreshTokenIfNeeded($tokenData);
        return true;
    }

    private function decodeToken(string $token): ?array
    {
        $key = $this->generateEncryptionKey();
        $tokenData = SecurityHelper::decodeToken($key, $token);
        if (!is_array($tokenData) || !isset($tokenData['ip'], $tokenData['time'])) return null;
        return $tokenData;
    }

    private function generateEncryptionKey(): string
    {
        $request = $this->getRequest();
        $user = $this->getUser();

        $ipPart = $this->adminBindIp ? IpHelper::ip($request->getServerParams()) : '';
        $userAgent = $request->getServerParams()['HTTP_USER_AGENT'] ?? '';
        $salt = SecurityHelper::decrypt($user['salt'], $this->authKey);
        return hash_hmac('sha256', $ipPart . md5($userAgent), $salt);
    }

    private function isTokenValid(array $tokenData): bool
    {
        return $this->isIpValid($tokenData) && !$this->isTokenExpired($tokenData);
    }

    private function isIpValid(array $tokenData): bool
    {
        return !$this->adminBindIp || $tokenData['ip'] === IpHelper::ip();
    }

    private function isTokenExpired(array $tokenData): bool
    {
        return (time() - $tokenData['time']) > self::TOKEN_EXPIRY;
    }

    private function refreshTokenIfNeeded(array $tokenData): void
    {
        if ((time() - $tokenData['time']) > self::TOKEN_REFRESH) {
            $this->adminTokenSet();
        }
    }

    public function adminTokenSet(): void
    {
        $request = $this->getRequest();
        $user = $this->getUser();

        $userAgent = $request->getServerParams()['HTTP_USER_AGENT'] ?? '';
        $tokenData = [
            'user_id' => $user['id'],
            'ip' => IpHelper::ip($request->getServerParams()),
            'ua'  => md5($userAgent),
            'time' => time()
        ];

        $key = $this->generateEncryptionKey();
        $adminToken = (string)SecurityHelper::generateToken($key, $tokenData);

        $this->setAdminCookie($adminToken, time() + self::TOKEN_EXPIRY);
    }

    public function adminTokenClean(): void
    {
        $this->setAdminCookie('', time() - 86400);
    }

    private function setAdminCookie(string $value, int $expiry): void
    {
        CookieHelper::set(self::COOKIE_PREFIX, $value, $expiry, $this->sessionConfig);
    }
}
