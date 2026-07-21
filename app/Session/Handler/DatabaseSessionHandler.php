<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Session\Handler;

class DatabaseSessionHandler implements \Framework\Session\SessionHandlerInterface
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \App\Services\Auth\SessionService */
    private $sessionService;
    /** @var \App\Services\Auth\SessionDataService */
    private $sessionDataService = null;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache = null;
    /** @var array */
    private $cacheConfig;
    /** @var bool */
    private $writeCacheOn;
    /** @var int */
    private $delayUpdate;
    /** @var int */
    private $timeWindow;
    /** @var int */
    private $maxLifetime;

    public function __construct(\App\Services\Auth\SessionService $sessionService, \App\Services\Auth\SessionDataService $sessionDataService, \Framework\Cache\Interfaces\CacheInterface $cache, array $cacheConfig, array $sessionConfig)
    {
        $this->sessionService = $sessionService;
        $this->sessionDataService = $sessionDataService;
        $this->cache = $cache;
        $this->cacheConfig = $cacheConfig;
        $this->writeCacheOn = (bool)($sessionConfig['session_write_cache_on'] ?? false);
        $this->delayUpdate = (int)($sessionConfig['delay_update'] ?? 30);
        $this->timeWindow = (int)($sessionConfig['time_window'] ?? 60);
        $this->maxLifetime = (int)($sessionConfig['online_hold_time'] ?? 1800);
    }

    /**
     * @param string $save_path
     * @param string $session_name
     * @return bool
     */
    public function open($save_path, $session_name): bool{
        return true;
    }

    /**
     * 快照捕获：在 Session 启动时主动存入当前 Request 信息
     * 避免 Shutdown 阶段 RequestStack 弹出后无法获取数据
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $serverParams = $request->getServerParams();
        $ip = \Framework\Utils\IpHelper::ip($serverParams);
        $this->setState('capturedIp', $ip);
        $this->setState('capturedUa', $serverParams['HTTP_USER_AGENT'] ?? '');

        // 优先使用 REQUEST_URI，回退到 PSR-7 getUri()
        $url = $serverParams['REQUEST_URI'] ?? '';
        if (empty($url)) {
            $uri = $request->getUri();
            $url = $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : '');
        }

        if ($url) {
            $url = \Framework\Utils\SecurityHelper::urldecode($url);
            // 过滤噪音 URL (Filter out noise URLs: images, scripts, devtools probes)
            $noiseExtensions = ['json', 'map', 'ico', 'png', 'jpg', 'jpeg', 'css', 'js', 'gif', 'svg'];
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($ext, $noiseExtensions, true) || strpos($path, '/.well-known/') === 0) {
                // 如果是噪音，不更新上下文 URL，保持上一次有效记录
                return;
            }
        }

        // 保证哪怕是一级目录也能捕获
        $this->setState('capturedUrl', $url ?: '/');
    }

    public function close(): bool{
        return true;
    }

    public function read($session_id): string
    {
        if (empty($session_id)) $session_id = session_id();

        if (!empty($this->cacheConfig['stores']) && $this->cache) {
            $data = $this->cache->cacheWithLock(
                'SessionHandler:' . $session_id,
                'lock:Session:' . $session_id,
                function () use ($session_id) {
                    $result = $this->sessionService->read($session_id);
                    if (!empty($result) && 1 === (int)$result['bigdata']) {
                        $sessionData = $this->sessionDataService->read($session_id);
                        return array_merge($result, $sessionData);
                    }
                    return $result;
                },
                5,
                $this->maxLifetime
            );

            if ($data) {
                return (string)($data['data'] ?? '');
            }
        }

        $result = $this->sessionService->read($session_id);
        if (!empty($result) && 1 === (int)$result['bigdata']) {
            $result = $this->sessionDataService->read($session_id);
        }

        return (string)($result['data'] ?? '');
    }

    public function write($session_id, $session_data): bool
    {
        $lastWrittenSid = $this->getState('lastWrittenSid');
        if ($this->getState('alreadyWritten') && $lastWrittenSid === $session_id) return true;

        $this->setState('alreadyWritten', true);
        $this->setState('lastWrittenSid', $session_id);

        $capturedIp = $this->getState('capturedIp');
        $capturedUa = $this->getState('capturedUa');
        $capturedUrl = $this->getState('capturedUrl');

        $userId = $this->getState('userId', 0);
        // 如果状态中没有 userId，尝试从序列化数据中提取（适用于登录后的第一次写入）
        if (!$userId) {
            // 兼容 JSON 新格式与 PHP serialize 旧格式
            $decoded = json_decode($session_data, true);
            if (is_array($decoded) && isset($decoded['user_id'])) {
                $userId = (int)$decoded['user_id'];
            } else {
                // 降级兼容旧数据（PHP serialize 格式）
                if (preg_match('/"user_id";i:(\d+);/', $session_data, $m)) {
                    $userId = (int)$m[1];
                } elseif (preg_match('/"user_id";s:\d+:"(\d+)";/', $session_data, $m)) {
                    $userId = (int)$m[1];
                }
            }
        }

        $now = time();
        $len = strlen($session_data);

        // 获取现有数据
        $result = $this->sessionService->read($session_id);

        $sessionData = [
            'id' => (string)$session_id,
            'user_id' => (int)$userId,
            'request_count' => 1,
            'url' => (string)($capturedUrl ?? ($result['url'] ?? '')), // SSOT: 仅在有新捕获时更新，否则保留现有的
            'ip' => $capturedIp ?? \Framework\Utils\IpHelper::ip() ?? '0.0.0.0',
            'ip_count' => 0,
            'useragent' => (string)($capturedUa ?? ($result['useragent'] ?? '')),
            'data' => (string)$session_data,
            'bigdata' => 0,
            'created_at' => $now,
            'start_date' => $now,
            'updated_at' => $now,
        ];

        if (mb_strlen($sessionData['url'], 'UTF-8') > 64) {
            $sessionData['url'] = mb_substr($sessionData['url'], 0, 64, 'UTF-8');
        }

        if ($this->writeCacheOn && !empty($this->cacheConfig['stores']) && $this->cache) {
            $result = $this->cache->cacheWithLock(
                'SessionHandler:' . $session_id,
                'lock:Session:' . $session_id,
                function () use ($session_id) {
                    $result = $this->sessionService->read($session_id);
                    if (!empty($result) && 1 === (int)$result['bigdata']) {
                        $sessionData = $this->sessionDataService->read($session_id);
                        return array_merge($result, $sessionData);
                    }
                    return $result;
                },
                5,
                $this->maxLifetime
            );
        } else {
            $result = $this->sessionService->read($session_id);
            $expiry = time() - $this->maxLifetime;
            // 判断是否会话过期
            if (!empty($result) && $result['updated_at'] <= $expiry && 1 === (int)$result['bigdata']) {
                $sessionData = $this->sessionDataService->read($session_id);
                $result = array_merge($result, $sessionData);
            }
        }

        if (empty($result)) {
            if ($len > 255) {
                try {
                    $this->sessionDataService->insert(['id' => $session_id, 'updated_at' => $now, 'data' => $session_data]);
                    $sessionData['bigdata'] = 1;
                    $sessionData['data'] = '';
                } catch (\Throwable $e) {
                    // 如果大对象表写入失败，尝试降级回原表存储（如果长度允许）或忽略
                }
            }

            try {
                $this->sessionService->insert($sessionData);
            } catch (\Throwable $e) {
                // 如果插入失败可能是由于并发，由 session_write_close 逻辑决定是否需要继续
            }
            return true;
        }

        unset($sessionData['created_at'], $sessionData['created_at_fmt'],$sessionData['updated_at_fmt']);

        // 请求间隔时间，在规定时间内小于限制请求次数，中间件自动加入和黑名单并缓存
        $timeElapsed = $now - $result['start_date'];
        if ($timeElapsed > $this->timeWindow) {
            $sessionData['request_count'] = 1;
            $sessionData['ip_count'] = 0;
            $sessionData['start_date'] = $now;
        } else {
            unset($sessionData['start_date']);
            $sessionData['request_count'] = $result['request_count'] + 1;
        }

        list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($sessionData['ip']);
        if ($ip2bin !== $result['ip']) {
            $sessionData['ip_count'] = $result['ip_count'] + 1;
        }

        // 延迟更新逻辑，小于30秒非重要数据不进行更新()
        $delayOn = $this->delayUpdate > 0 && ($now - ($result['updated_at'] ?? 0) < $this->delayUpdate);

        // 启用延迟更新后，同个URL请求，过滤不重要的数据。
        if ($delayOn && $sessionData['url'] === ($result['url'] ?? '')) unset($sessionData['url'], $sessionData['updated_at'], $sessionData['start_date'], $sessionData['useragent'], $sessionData['request_count']);

        if ($len <= 255) {
            $sessionData['bigdata'] = 0;
            $diff = array_diff_assoc($sessionData, $result);

            // update
            $affected = 0;
            if (!empty($diff)) {
                try {
                    $affected = $this->sessionService->update($session_id, $diff);
                } catch (\Throwable $e) {
                    $affected = 0;
                }
            }

            // 若update未命中，自动insert
            if ($affected === 0) {
                try {
                    $this->sessionService->insert($sessionData);
                } catch (\Throwable $e) {
                    // ignore二次插入异常
                }
            }

            if ($result && 1 === (int)$result['bigdata']) $this->sessionDataService->delete($session_id);
        } else {
            $sessionData['bigdata'] = 1;
            $sessionData['data'] = '';
            $diff = array_diff_assoc($sessionData, $result);

            // $session_data
            // [核心改进] 先写数据表，再写主表，防止 bigdata=1 时数据未就绪
            if (empty($result) || 0 === (int)$result['bigdata']) {
                try {
                    $this->sessionDataService->insert(['id' => $session_id, 'updated_at' => $now, 'data' => $session_data]);
                } catch (\Throwable $e) {
                    // 可能是并发冲突或残留记录，尝试更新
                    $this->sessionDataService->update($session_id, ['updated_at' => $now, 'data' => $session_data]);
                }
            } else {
                try {
                    $this->sessionDataService->update($session_id, ['updated_at' => $now, 'data' => $session_data]);
                } catch (\Throwable $e) {
                    // 极端情况下主键丢失，尝试找回
                    $this->sessionDataService->insert(['id' => $session_id, 'updated_at' => $now, 'data' => $session_data]);
                }
            }

            // [核心改进] 后写主表
            if (!empty($diff)) {
                try {
                    $this->sessionService->update($session_id, $diff);
                } catch (\Throwable $e) {
                    // 失败则说明记录可能被清理，尝试重新插入
                    try {
                        $this->sessionService->insert($sessionData);
                    } catch (\Throwable $e2) {
                    }
                }
            }

            $sessionData['data'] = $session_data;
        }

        if ($this->writeCacheOn && !empty($this->cacheConfig['stores']) && $this->cache) {
            $sessionData['updated_at'] = $now;
            $this->cache->set('SessionHandler:' . $session_id, array_merge($result, $sessionData), $this->maxLifetime);
        }

        return true;
    }

    public function destroy($session_id): bool{
        $this->sessionService->delete($session_id);
        $this->sessionDataService->delete($session_id);
        if (!empty($this->cacheConfig['stores'])) $this->cache->delete($session_id);
        return true;
    }

    public function gc($maxlifetime): int
    {
        $expiry = time() - $maxlifetime;
        $batch = 5000;
        $page = 1;
        $toDel = [];

        do {
            $rows = $this->sessionService->find(['updated_at' => ['<' => $expiry]], [], $page, $batch);
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $toDel[] = $row['id'];
                if (!empty($this->cacheConfig['stores'])) {
                    $this->cache->delete('SessionHandler:' . $row['id']);
                    $this->cache->delete($row['id']);
                }
            }
            $page++;
        } while (count($rows) === $batch);

        $count = count($toDel);
        if (!empty($toDel)) {
            $this->sessionService->delete($toDel);
            $this->sessionDataService->delete($toDel);
        }

        return $count;
    }

    public function sessionCache(string $sessionId)
    {
        // 从 DB 读取
        $session = $this->sessionService->readByCache($sessionId);
        if ($session) {
            if (1 === (int)$session['bigdata']) {
                $sessionData = $this->sessionDataService->read($sessionId);
                $this->setState('sessionData', array_merge($session, $sessionData));
                return $sessionData['data'] ?? '';
            }

            $this->setState('sessionData', $session);
            return $session['data'] ?? '';
        }
    }
}
