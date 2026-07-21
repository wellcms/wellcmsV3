<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7\Factories;

/**
 * Class ServerRequestFactory
 *
 * 实现 PSR‑17 ServerRequestFactoryInterface，
 * 创建 ServerRequest 并兼容 Swoole/Swow 协程环境。
 */
class ServerRequestFactory implements \Framework\Http\Interfaces\ServerRequestFactoryInterface
{
    /** 单例管理，兼容 Swoole 协程 与 FPM */
    private /** @var array */
    static $instances = [];

    public static function getInstance(): self
    {
        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            if ($cid > 0) {
                if (!isset(self::$instances[$cid])) {
                    self::$instances[$cid] = new self();
                    call_user_func([$coroClass, 'defer'], static function () use ($cid): void {
                        unset(self::$instances[$cid]);
                    });
                }
                return self::$instances[$cid];
            }
        }
        // FPM 或 CLI 非协程环境：使用单一静态实例，符合每请求进程隔离
        static $instance = null;
        return $instance ?: $instance = new self();
    }

    /**
     * 根据给定 method、uri 与 serverParams 构造 Request
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): \Framework\Http\Interfaces\ServerRequestInterface
    {
        // URI 对象
        $uriObj = \is_string($uri) ? \Framework\Http\Psr7\Factories\UriFactory::getInstance()->createUri($uri) : $uri;

        $serverKeys = [
            'USER',
            'HOME',
            'HTTP_UPGRADE_INSECURE_REQUESTS',
            'HTTP_CONNECTION',
            'HTTP_ACCEPT_ENCODING',
            'HTTP_ACCEPT_LANGUAGE',
            'HTTP_ACCEPT',
            'HTTP_USER_AGENT',
            'HTTP_HOST',
            'HTTP_REFERER',
            'HTTP_COOKIE',
            'PHP_ADMIN_VALUE',
            'REDIRECT_STATUS',
            'SERVER_NAME',
            'SERVER_PORT',
            'SERVER_ADDR',
            'REMOTE_PORT',
            'REMOTE_ADDR',
            'SERVER_SOFTWARE',
            'GATEWAY_INTERFACE',
            'REQUEST_SCHEME',
            'SERVER_PROTOCOL',
            'DOCUMENT_ROOT',
            'DOCUMENT_URI',
            'REQUEST_URI',
            'SCRIPT_NAME',
            'CONTENT_LENGTH',
            'CONTENT_TYPE',
            'REQUEST_METHOD',
            'QUERY_STRING',
            'SCRIPT_FILENAME',
            'FCGI_ROLE',
            'PHP_SELF',
            'REQUEST_TIME_FLOAT',
            'REQUEST_TIME'
        ];

        // 解析 headers
        $headers = [];
        foreach ($serverParams as $key => $value) {
            $key = (string)$key;
            if (0 === \strpos($key, 'HTTP_')) {
                $name = \substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = $key;
            } else {
                continue;
            }
            $name = \strtr(\ucwords(\strtolower(\strtr($name, '_', ' '))), ' ', '-');
            $headers[$name] = (array)$value;
        }

        // 环境检测与数据采集
        $isSwoole = false;
        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            $isSwoole = $cid > 0;
        }

        // 2. 针对非 CGI 规范（如 Swoole 原生非 HTTP_ 前缀）的请求头进行兜底匹配
        if ($isSwoole) {
            $coroClass = "\\Swoole\\Coroutine";
            $ctx = call_user_func([$coroClass, 'getContext']);
            $ctxHeaders = $ctx['header'] ?? [];
            foreach ($ctxHeaders as $key => $val) {
                $name = \strtr(\ucwords(\strtolower(\strtr((string)$key, '-', ' '))), ' ', '-');
                if (!isset($headers[$name])) {
                    $headers[$name] = (array)$val;
                }
            }
        }

        $raw = '';
        $queryParams = [];
        $parsedBody = [];
        $uploadedFiles = [];
        $cookieParams = [];

        if ($isSwoole) {
            $coroClass = "\\Swoole\\Coroutine";
            $ctx = call_user_func([$coroClass, 'getContext']);
            $raw = $ctx['rawContent'] ?? '';
            $queryParams = $ctx['get'] ?? [];
            $parsedBody = $ctx['post'] ?? [];
            $uploadedFiles = $this->normalizeFiles($ctx['files'] ?? []);
            $cookieParams = $ctx['cookie'] ?? [];
        } else {
            $raw = \file_get_contents('php://input') ?: '';
            $queryParams = $_GET;
            $parsedBody = $_POST;
            $uploadedFiles = $this->normalizeFiles($_FILES);
            $cookieParams = $_COOKIE;
        }

        // 通过 StreamFactory 构造 PSR-7 Stream
        $bodyStream = \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStream($raw);

        // 协议版本
        $protocol = '1.1';
        if (!empty($serverParams['SERVER_PROTOCOL']) && \strpos($serverParams['SERVER_PROTOCOL'], 'HTTP/') === 0) {
            $protocol = \substr($serverParams['SERVER_PROTOCOL'], 5);
        }

        // 实例化 Request 并设置所有属性
        return new \Framework\Http\Psr7\Request(
            $uriObj,
            \strtoupper($method),
            $headers,
            $serverParams,
            $cookieParams,
            $bodyStream,
            $protocol,
            $queryParams,
            $parsedBody,
            $uploadedFiles
        );
    }

    /**
     * 将 PHP 原生 $_FILES 数组转换为 PSR-7 UploadedFileInterface 树。
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $field => $info) {
            if (!\is_array($info)) continue;

            if (isset($info['name']) && \is_array($info['name'])) {
                // 多文件上传适配
                foreach (\array_keys($info['name']) as $i) {
                    $normalized[$field][] = UploadedFileFactory::getInstance()->createUploadedFile(
                        $info['tmp_name'][$i],
                        (int)($info['size'][$i] ?? 0),
                        (int)($info['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        $info['name'][$i] ?? '',
                        $info['type'][$i] ?? ''
                    );
                }
            } else {
                // 单文件
                $normalized[$field] = UploadedFileFactory::getInstance()->createUploadedFile(
                    $info['tmp_name'] ?? '',
                    (int)($info['size'] ?? 0),
                    (int)($info['error'] ?? UPLOAD_ERR_NO_FILE),
                    $info['name'] ?? '',
                    $info['type'] ?? ''
                );
            }
        }
        return $normalized;
    }

    /**
     * 从全局环境自动构造 Request，供 Kernel::run() 使用
     */
    public function createFromGlobals(): \Framework\Http\Interfaces\ServerRequestInterface
    {
        $server = $_SERVER;
        $isSwoole = false;
        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            $isSwoole = $cid > 0;
        }

        if ($isSwoole) {
            $coroClass = "\\Swoole\\Coroutine";
            $ctx = call_user_func([$coroClass, 'getContext']);
            // 在 Swoole 协程下，全局数据源来自协程上下文
            if (isset($ctx['server'])) {
                $server = (array)$ctx['server'] + (array)($ctx['header'] ?? []);
            }
        }

        $method = $server['REQUEST_METHOD'] ?? $server['request_method'] ?? 'GET';
        $uri = \Framework\Http\Psr7\Factories\UriFactory::getInstance()->createFromGlobals();

        return $this->createServerRequest($method, $uri, $server);
    }
}

/* 
// FPM / CLI 场景
use Framework\Http\Psr7\Factories\ServerRequestFactory;

$factory  = ServerRequestFactory::getInstance();
$request  = $factory->createFromGlobals();

// 演示读取信息
echo $request->getMethod(), PHP_EOL;          // GET / POST …
echo (string)$request->getUri(), PHP_EOL;     // 完整 URI
print_r($request->getHeaders());              // 所有请求头

// Swoole 场景（HTTP Server）
use Swoole\Http\Server;
use Framework\Http\Psr7\Factories\ServerRequestFactory;

$serv = new Server("0.0.0.0", 9501);

$serv->on('request', function ($req, $res) {
    // 把 raw body 塞进协程上下文，供 Factory 读取
    \Swoole\Coroutine::getContext()['rawContent'] = $req->rawContent();

    $factory     = ServerRequestFactory::getInstance();
    $psrRequest  = $factory->createServerRequest(
        $req->server['request_method'],
        ($req->header['scheme'] ?? 'http') . '://' . $req->header['host'] . $req->server['request_uri'],
        $req->server + $req->header + ['RAW_BODY' => $req->rawContent()]
    );

    // …用 $psrRequest 继续走框架/中间件
    $res->end("OK");
});

$serv->start();



// 获取客户端 IP
$ip = $request->getAttribute('client_ip');

// 获取服务器端口
$port = $request->getAttribute('server_port');

// 获取 Content-Type
$contentType = $request->getHeaderLine('Content-Type');

// 获取 Content-Length
$contentLength = $request->getHeaderLine('Content-Length');

// 获取当前链接（URI）
$currentUrl = (string)$request->getUri();

// 获取来源链接（Referer）
$referer = $request->getHeaderLine('Referer');

// 获取 USER_AGENT
$request->getHeaderLine('User-Agent'); */