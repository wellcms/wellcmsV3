<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7\Factories;

/**
 * Class StreamFactory
 *
 * 实现 PSR‑17 StreamFactoryInterface，支持创建字符串、文件与资源流，
 * 并在 Swoole/Swow 环境下按协程 ID 缓存工厂实例。
 */
class StreamFactory implements \Framework\Http\Interfaces\StreamFactoryInterface
{
    /** @var self[] 协程实例缓存 */
    private static $instances = [];

    /**
     * 获取工厂实例（FPM: 全局单例；协程: 协程级缓存）
     */
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

    /** 从字符串创建可读写流 */
    public function createStream(string $content = ''): \Framework\Http\Interfaces\StreamInterface
    {
        return \Framework\Http\Psr7\Stream::fromString($content);
    }

    /**
     * 用于把文件直接包装为可读／写的流对象的方法。它内部通过 fopen() 打开文件资源，把资源交给实现了 StreamInterface 的 Stream 类来管理。此方法让上层代码无需关心底层资源处理细节，就能方便地在 HTTP 响应或请求体中使用文件内容。大文件／静态资源下载：当向客户端传输几十 MB、数 GB 的 ZIP、视频或镜像文件时，php://temp / php://memory 往往不堪重负，直接对磁盘文件做流式封装最省内存。
     * @param string $filename
     * @param string $mode
     * r	只读，指针在开头	    读      报错
     * r+	可读写，指针在开头	    读写    报错
     * w	只写，清空原有内容	    写      创建新文件
     * w+	可读写，清空原有内容	读写	创建新文件
     * a	只写，指针在末尾	    写	    创建新文件
     * a+	可读写，指针在末尾	    读写	创建新文件
     * x	只写，创建新文件	    写	    文件已存在时报错
     * x+	可读写，创建新文件	    读写	文件已存在时报错
     * @return \Framework\Http\Interfaces\StreamInterface
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): \Framework\Http\Interfaces\StreamInterface
    {
        $resource = @fopen($filename, $mode);
        if (false === $resource) {
            throw new \RuntimeException("Cannot open file: {$filename}");
        }
        return new \Framework\Http\Psr7\Stream($resource);
    }

    /** 若传参为 PHP 原生上传资源（resource），则使用 createStreamFromResource() */
    public function createStreamFromResource($resource): \Framework\Http\Interfaces\StreamInterface
    {
        return new \Framework\Http\Psr7\Stream($resource);
    }
}

/*
//require APP_PATH . 'src/Http/Psr7/Factories/StreamFactory.php';
//require APP_PATH . 'src/Http/Psr7/Factories/ResponseFactory.php';

use Framework\Http\Psr7\Factories\StreamFactory;
use Framework\Http\Psr7\Factories\ResponseFactory;

// 从字符串创建流
$body1 = StreamFactory::getInstance()->createStream('Hello PSR-7');
// 从文件创建流
$body2 = StreamFactory::getInstance()->createStreamFromFile('/path/to/data.txt', 'r');
// 将流注入响应
$response3 = ResponseFactory::getInstance()
    ->createResponse(200)
    ->withBody($body1);

// 测试大文件流
$stream = $streamFactory::getInstance()->createStreamFromFile('4GB-file.iso');
$this->assertEquals(4294967296, $stream->getSize());
*/