<?php

declare(strict_types=1);

namespace App\Middleware;

use Framework\Http\Interfaces\MiddlewareInterface;
use Framework\Http\Interfaces\RequestHandlerInterface;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;

class RequestIdMiddleware implements MiddlewareInterface
{
    /** @var string */
    private $headerName;

    public function __construct(string $headerName = 'X-Request-Id')
    {
        $this->headerName = $headerName;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine($this->headerName);
        if ($requestId === '') {
            $requestId = $this->generateRequestId();
        }

        $request = $request->withAttribute('request_id', $requestId);
        $response = $handler->handle($request);

        return $response->withHeader($this->headerName, $requestId);
    }

    /**
     * 生成唯一请求 ID。
     * 优先使用 CSPRNG，降级使用 uniqid + mt_rand 组合提供足够熵值。
     * 注意：request_id 仅用于分布式追踪，不需要密码学强度。
     */
    private function generateRequestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            // 降级：microtime + uniqid + mt_rand 组合，熵值足够用于请求追踪
            return md5(uniqid((string)microtime(true), true) . mt_rand());
        }
    }
}
