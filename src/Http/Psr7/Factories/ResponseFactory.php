<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7\Factories;

/**
 * Class ResponseFactory
 *
 * 实现 PSR‑17 ResponseFactoryInterface，创建 Response 对象并支持协程复用。
 */
class ResponseFactory implements \Framework\Http\Interfaces\ResponseFactoryInterface
{
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
        static $instance = null;
        return $instance ?: $instance = new self();
    }

    /** @inheritDoc */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): \Framework\Http\Interfaces\ResponseInterface
    {
        return new \Framework\Http\Psr7\Response($code, [], \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStream($reasonPhrase));
    }
}


/* 
require APP_PATH . 'src/Http/Psr7/Factories/ResponseFactory.php';

use Framework\Http\Psr7\Factories\ResponseFactory;

// 创建响应工厂
$factory = ResponseFactory::getInstance();

// 生成200响应
$response = $factory->createResponse(200)
    ->withHeader('Content-Type', 'text/html')
    ->withProtocolVersion('1.1');

// 写入响应体
$response->getBody()->write('<h1>Hello World</h1>');

// 输出响应
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    header("$name: " . implode(', ', $values));
}

echo $response->getBody();
echo '<hr>';
echo $response->getStatusCode(); // 200
echo '<hr>';
echo $response->getReasonPhrase(); // "OK"
echo '<hr>';
echo $response->getHeaderLine('Content-Type'); // "text/html"
echo '<hr>';
echo $response->getProtocolVersion();


// --自定义状态码与协程安全-----------------------
// 在 Swoole 协程内获取协程级别工厂
$factory  = ResponseFactory::getInstance();
$response = $factory->createResponse(404, 'Not Found');
echo $response->getStatusCode(); // 404
echo '<hr>';
echo $response->getReasonPhrase(); // Not Found

//添加头与更换协议版本
$response2 = $response
    ->withHeader('Content-Type', 'application/json')
    ->withAddedHeader('X-Debug', 'true')
    ->withProtocolVersion('1.1');

assert($response2->getProtocolVersion() === '1.1');
assert($response2->getHeaderLine('Content-Type') === 'application/json');


// --创建下载文件-----------------------
$filePath = APP_PATH . 'v3.0_2025.04.29.zip';
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found');
}

// 1. 从文件创建流
$stream   = StreamFactory::getInstance()->createStreamFromFile($filePath, 'r');

// 2. 构造带文件流的响应
$response = ResponseFactory::getInstance()
    ->createResponse(200)
    ->withHeader('Content-Type', 'application/zip')
    ->withHeader('Content-Disposition', 'attachment; filename="file.zip"')
    ->withBody($stream);

// 3. 输出响应头
header('HTTP/1.1 ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
foreach ($response->getHeaders() as $name => $values) {
    header($name . ': ' . implode(',', $values));
}

// 4. 输出文件内容
//echo $response->getBody()->getContents();

*/