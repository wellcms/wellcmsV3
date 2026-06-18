<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

class ResponseSender
{
    /**
     * 将 PSR-7 Response 发送到客户端
     */
    public function send(\Framework\Http\Interfaces\ResponseInterface $response): void
    {
        // 1. 环境检测：检查协程上下文中是否存在 Swoole Response
        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            $ctx = call_user_func([$coroClass, 'getContext']);
            /** @var object|null $swooleResponse */
            $swooleResponse = $ctx['swoole_response'] ?? null;

            if ($swooleResponse && is_a($swooleResponse, "\\Swoole\\Http\\Response")) {
                // 设置状态码
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->status($response->getStatusCode());

                // 设置响应头
                foreach ($response->getHeaders() as $name => $values) {
                    foreach ($values as $value) {
                        /** @noinspection PhpUndefinedMethodInspection */
                        $swooleResponse->header($name, (string)$value);
                    }
                }
 
                // 发送内容：优先使用流式发送以支持大文件，否则回退到全量发送
                /** @var \Framework\Http\Interfaces\StreamInterface $body */
                $body = $response->getBody();

                if ($body->isReadable() && $body->getSize() > 2 * 1024 * 1024) {
                    // 大于 2MB 的内容使用 chunked 发送
                    $body->rewind();
                    while (!$body->eof()) {
                        $chunk = $body->read(8192); // 8KB chunks
                        if ($chunk !== '') {
                            /** @noinspection PhpUndefinedMethodInspection */
                            $swooleResponse->write($chunk);
                        }
                    }
                    /** @noinspection PhpUndefinedMethodInspection */
                    $swooleResponse->end();
                } else {
                    /** @noinspection PhpUndefinedMethodInspection */
                    $swooleResponse->end((string)$body);
                }
                return;
            }
        }

        // 2. 传统 FPM/CLI 模式
        // 输出状态行
        if (!headers_sent()) {
            header(
                'HTTP/' . $response->getProtocolVersion() . ' ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase(),
                true,
                $response->getStatusCode()
            );
        }

        // 输出 headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}", false);
            }
        }

        // 输出 body
        echo (string)$response->getBody();
    }
}
