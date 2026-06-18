<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7\Factories;

use Framework\Http\Interfaces\UriInterface;

/**
 * Class UriFactory
 *
 * 实现 PSR‑17 UriFactoryInterface，创建 URI 对象并支持协程复用。
 */
class UriFactory implements \Framework\Http\Interfaces\UriFactoryInterface
{
    /** @var self[] 以协程 ID 维度隔离的实例 */
    private static $instances = [];

    public static function getInstance(): self
    {
        // 协程环境：用 CID 作为隔离键，并在协程结束后回收
        $coroClass = "\\Swoole\\Coroutine";
        if (extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $cid = call_user_func([$coroClass, 'getCid']);
            if (!isset(self::$instances[$cid])) {
                self::$instances[$cid] = new self();
                call_user_func([$coroClass, 'defer'], static function () use ($cid) {
                    unset(self::$instances[$cid]);
                });
            }
            return self::$instances[$cid];
        }

        // 非协程环境：经典静态单例
        static $instance = null;
        return $instance ?: $instance = new self();
    }

    /** @inheritDoc */
    public function createUri(string $uri = ''): UriInterface
    {
        return new \Framework\Http\Psr7\Uri($uri);
    }

    /**
     * FPM / CLI 场景使用
     */
    public function createFromGlobals(): UriInterface
    {
        $server = $_SERVER;
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            // 在 Swoole 下，优先从协程上下文中的 server 和 header 提取数据
            if (isset($ctx['server'])) {
                $server = (array)$ctx['server'] + (array)($ctx['header'] ?? []);
            }
        }
        return self::buildUriFromServer($server);
    }

    /**
     * Swoole HTTP 场景使用，避免 $_SERVER 并发污染
     * @param object $req 实际为 \Swoole\Http\Request
     */
    public function createFromRequest(object $req): UriInterface
    {
        /** @noinspection PhpUndefinedFieldInspection */
        return self::buildUriFromServer($req->server + $req->header);
    }

    /**
     * 根据服务器数组构造 URI
     *
     * @param array<string,mixed> $server
     */
    public static function buildUriFromServer(array $server): UriInterface
    {
        // 兼容大小写
        $s = array_change_key_case($server, CASE_LOWER);

        // 协议
        $isHttps = (!empty($s['https']) && $s['https'] !== 'off')
            || (($s['server_port'] ?? $server['SERVER_PORT'] ?? null) == 443)
            || (!empty($s['http_x_forwarded_proto']) && $s['http_x_forwarded_proto'] === 'https');

        $scheme = $isHttps ? 'https' : 'http';

        // 主机
        $host = $s['http_host']
            ?? $s['server_name']
            ?? $s['server_addr']
            ?? 'localhost';

        // Host 可能自带端口，需要拆分
        if (strpos($host, ':') !== false && strpos($host, '[') !== 0) {
            [$hostOnly, $portFromHost] = explode(':', $host, 2);
            $host = $hostOnly;
            $s['server_port'] = $s['server_port'] ?? $portFromHost;
        }

        // 端口（去掉默认端口）
        $port = (int)($s['server_port'] ?? 0);
        if (in_array($port, [0, 80, 443], true)) {
            $port = null;
        }

        // 路径与查询
        $requestUri = $s['request_uri'] ?? '/';
        [$path, $query] = array_pad(explode('?', $requestUri, 2), 2, '');
        $path = rawurldecode($path);

        return (new \Framework\Http\Psr7\Uri())
            ->withScheme($scheme)
            ->withHost($host)
            ->withPort($port)
            ->withPath($path)
            ->withQuery($query);
    }
}

/* // ===== 场景 1：传统 FPM / CLI =====
$uri = UriFactory::getInstance()->createFromGlobals();
echo $uri; // http://localhost/foo?bar=baz

// ===== 场景 2：纯字符串转 Uri =====
$uri2 = UriFactory::getInstance()->createUri('https://example.com/hi');
echo $uri2->getHost(); // example.com

// Swoole HTTP Server（协程）
use Swoole\Http\Server;
use App\UriFactory;

$server = new Server('0.0.0.0', 9501);
$server->on('request', function ($request, $response) {
    // 针对当前协程安全地提取 URI
    $uri = UriFactory::getInstance()->createFromRequest($request);
    $response->end("You hit: " . (string)$uri);
});
$server->start(); */