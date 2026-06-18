<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Session\Service;

class SessionManager
{
    /** @var \App\Session\Handler\DatabaseSessionHandler */
    private $handler;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var array */
    private $config;
    /** @var string 锁定的 Session Cookie 名称 */
    private $sessionCookieName;
    /** @var bool */
    private $configured = false;

    public function __construct(\App\Session\Handler\DatabaseSessionHandler $handler, \Framework\Cache\Interfaces\CacheInterface $cache)
    {
        $this->handler = $handler;
        $this->cache = $cache;
    }

    public function configure(array $cfg): void
    {
        $this->config = $cfg;
        // 立即锁定全量 Cookie 名称，确保 SSOT (Single Source of Truth)
        $this->sessionCookieName = ($cfg['pre'] ?? 'well_') . 'session_id';

        if ($this->configured || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            $this->configured = true;
            return;
        }

        ini_set('session.name', $this->sessionCookieName);
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_domain', $cfg['cookie_domain'] ?? '');
        ini_set('session.cookie_path', $cfg['cookie_path'] ?? '/');
        ini_set('session.cookie_secure', !empty($cfg['cookie_secure']) ? '1' : '0');
        ini_set('session.cookie_httponly', !empty($cfg['httponly']) ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string)($cfg['online_hold_time'] ?? 1800));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', (string)($cfg['gc_divisor'] ?? 1000));   // 每1000次有1次GC
        ini_set('session.use_strict_mode', '1');
        // sid_length = 48、sid_bits_per_character = 6（64 字符集：0-9a-zA-Z,-）生成的session_id 为48个字符，银行级/国防级系统使用此配置。常规 Web 系统使用32/4即可。
        ini_set('session.sid_length', '32');
        ini_set('session.sid_bits_per_character', '4');
        ini_set('session.serialize_handler', 'php_serialize'); // 强制使用 php_serialize 确保 FPM 和 Swoole 序列化一致

        // 同站策略（如有需要）
        if (PHP_VERSION_ID >= 70300 && isset($cfg['cookie_samesite'])) {
            $sameSite = $cfg['cookie_samesite']; // 'Lax' / 'Strict' / 'None'
            ini_set('session.cookie_samesite', $sameSite);

            if ('None' === $sameSite) {
                // 确保安全属性，否则 cookie 会被丢弃
                ini_set('session.cookie_secure', '1');
            }
        }

        // manual shutdown – avoid double write
        session_set_save_handler($this->handler, false);
        //function_exists('chdir') && chdir(APP_PATH); // 相对路径时可能会丢失当前目录，绝对路径不存在此问题，主程序和官方插件默认都是绝对路径。
        if (!$this->isSwoole()) {
            register_shutdown_function('session_write_close');
        }
        $this->configured = true;
    }

    /**
     * 启动并返回 Session 对象 (兼容 FPM/Swoole)
     */
    public function startSession(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Session\SessionInterface
    {
        if (!$this->configured) {
            throw new \RuntimeException("SessionManager must be configured before starting session.");
        }

        $sessionId = $request->getCookieParams()[$this->sessionCookieName] ?? '';

        if (empty($sessionId)) {
            $sessionId = bin2hex(random_bytes(16));
            $data = [];
        } else {
            $raw = $this->handler->read($sessionId);
            $data = $this->unserialize($raw);
        }

        // FPM 环境下手动执行 GC（后台调度模式下由 Job 负责清理，此处跳过）
        if (!$this->isSwoole() && !defined('SCHEDULER_MODE')) {
            $cfg = $this->config;
            $lastGc = (int)($this->cache->get('session_gc_last_time') ?: 0);
            if (time() - $lastGc >= (int)($cfg['gc_recycle_time'] ?? 600)) {
                $maxLifetime = (int)($cfg['online_hold_time'] ?? 1800);
                $this->handler->gc($maxLifetime);
                $this->cache->set('session_gc_last_time', time());
            }
        }

        return new \Framework\Session\Session($sessionId, $data);
    }

    /**
     * 持久化 Session 数据
     */
    public function saveSession(\Framework\Session\SessionInterface $session): void
    {
        // 0. 如果 Session 发生了 ID 重生，物理清理旧 ID 记录以免数据散落
        if ($oldSid = $session->getOldId()) {
            $this->handler->destroy($oldSid);
            $session->clearOldId();
        }

        // 1. 持久化数据到 Handler
        $this->handler->write($session->getId(), $this->serialize($session->all()));

        // 2. 下发 Cookie (使用已锁定的全量名称)
        if (!headers_sent()) {
            $expiry = time() + (int)($this->config['online_hold_time'] ?? 1800);
            // 这里传空 pre，因为 $this->sessionCookieName 已经包含前缀
            $cfg = $this->config;
            $cfg['pre'] = '';
            \Framework\Utils\CookieHelper::set($this->sessionCookieName, $session->getId(), $expiry, $cfg);
        }
    }

    public function serialize(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function unserialize(string $data): array
    {
        if (empty($data)) return [];

        // 优先使用 JSON 解析（新格式，无反序列化攻击面）
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 降级兼容：旧数据仍为 PHP serialize 格式
        // allowed_classes=false 彻底阻断反序列化对象实例化攻击面
        $legacy = @unserialize($data, ['allowed_classes' => false]);
        return is_array($legacy) ? $legacy : [];
    }

    protected function isSwoole(): bool
    {
        if (!\extension_loaded('swoole')) return false;
        $cid = (int)call_user_func(['\Swoole\Coroutine', 'getCid']);
        return $cid > 0;
    }

    /**
     * 手动触发快照捕获
     */
    public function collectContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $this->handler->captureContext($request);
    }
}
