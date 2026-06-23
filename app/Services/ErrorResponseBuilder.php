<?php

declare(strict_types=1);

namespace App\Services;

use Framework\Utils\LoggerContext;
use App\Utils\PathHelper;
use Framework\Core\Container;
use Framework\Exception\BusinessException;
use Framework\Exception\ExceptionInterface;
use Framework\Exception\ValidationException;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Http\Response;

class ErrorResponseBuilder
{
    /** @var Container */
    private $container;

    /** @var bool */
    private $debug;

    /** @var array */
    private $errorConfig;

    public function __construct(Container $container, bool $debug = false, array $errorConfig = [])
    {
        $this->container = $container;
        $this->debug = $debug;
        $this->errorConfig = $errorConfig;
    }

    /**
     * 构建错误响应。
     *
     * 决策树：
     * 1. API/AJAX 请求 → 始终 JSON（含 debug 信息）
     * 2. 非调试 + BusinessException → 主题 message.htm
     * 3. 其他 → 系统错误视图（debug → error_debug.htm / 生产 → 500.htm）
     */
    public function build(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $statusCode = $this->resolveStatusCode($e);

        // 审计修订：移除布尔型 `success` 字段，遵循 Iron Law #11
        // 统一使用 `status` 字符串字段作为唯一状态指示器
        $data = [
            'status'    => 'error',
            'code'      => $e->getCode() ?: $statusCode,
            'message'   => $this->resolvePublicMessage($e),
            'timestamp' => time(),
        ];

        if ($e instanceof ValidationException) {
            $data['errors'] = $e->getErrors();
        }

        if ($this->debug) {
            $data['debug'] = [
                'request_id' => LoggerContext::get('request_id', ''),
                'exception'  => \get_class($e),
                'file'       => PathHelper::relative($e->getFile()),
                'line'       => $e->getLine(),
                'trace'      => PathHelper::relativeTrace($e->getTrace()),
                'sql'        => \Framework\Database\Collector\QueryCollector::getLoggedQueries(),
            ];
        }

        if ($this->isApiRequest($request)) {
            return $this->createJsonResponse($data, $statusCode);
        }

        // 非调试模式下业务错误继续走主题 message.htm
        if (!$this->debug && $e instanceof BusinessException) {
            try {
                return $this->createThemeMessageResponse($data, $request);
            } catch (\Throwable $t) {
                $this->logInternalError('Theme message render failed', $t);
            }
        }

        return $this->createSystemErrorResponse($data, $statusCode);
    }

    private function resolveStatusCode(\Throwable $e): int
    {
        if ($e instanceof ExceptionInterface) {
            return $e->getStatusCode();
        }
        return 500;
    }

    private function resolvePublicMessage(\Throwable $e): string
    {
        if ($this->debug) {
            return $e->getMessage();
        }

        if ($e instanceof BusinessException || $e instanceof ValidationException) {
            return $e->getMessage();
        }

        return isset($this->errorConfig['generic_message'])
            ? $this->errorConfig['generic_message']
            : 'Internal Server Error';
    }

    /**
     * 审计修订：统一 API 请求判定逻辑。
     * 与 ExceptionHandler::expectsJson() 保持完全一致的检测维度：
     * - X-Requested-With: XMLHttpRequest
     * - ?api= 查询参数
     * - Accept: application/json 或 application/javascript
     * - Route meta api: true
     */
    private function isApiRequest(ServerRequestInterface $request): bool
    {
        $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
        $xrw = $request->getHeaderLine('X-Requested-With');
        $accept = $request->getHeaderLine('Accept');
        $meta = $request->getAttribute('_route_meta', []);

        return ($xrw && strtolower(trim($xrw)) === 'xmlhttprequest')
            || (!empty($params['api']))
            || strpos(strtolower($accept), 'application/json') !== false
            || strpos(strtolower($accept), 'application/javascript') !== false
            || (!empty($meta['api']));
    }

    private function createThemeMessageResponse(array $data, ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->container->has(\App\Controllers\Base\ResponseFormatter::class)) {
            throw new \RuntimeException('ResponseFormatter not available');
        }

        /** @var \App\Controllers\Base\ResponseFormatter $formatter */
        $formatter = $this->container->get(\App\Controllers\Base\ResponseFormatter::class);

        $fullData = array_merge($data, [
            'url'   => '',
            'delay' => 3,
            'data'  => [
                'title'       => 'System Notice',
                'keywords'    => 'Notice',
                'description' => 'Operation Notice',
                'redirect'    => null,
                'modal'       => 1,
            ],
        ]);

        $templatePath = '';
        if ($this->container->has(\App\Controllers\Base\TemplateManager::class)) {
            $templatePath = $this->container
                ->get(\App\Controllers\Base\TemplateManager::class)
                ->template(false, 'message', '', $request);
        }

        return $formatter->createFormatter($fullData, $templatePath, $request);
    }

    private function createSystemErrorResponse(array $data, int $statusCode): ResponseInterface
    {
        $templateName = $this->debug ? 'error_debug.htm' : '500.htm';
        $templateFile = defined('APP_PATH') ? APP_PATH . 'app/views/htm/' . $templateName : '';

        if ($templateFile && file_exists($templateFile)) {
            return $this->renderView($templateFile, $data, $statusCode);
        }

        return $this->createFallbackHtmlResponse($data, $statusCode);
    }

    /**
     * 审计修订：完整实现点号多级键解析的视图容器，
     * 确保 500.htm 模板中的 $view->get('website.current.view') 等点号键正常工作。
     * 同时增加 Compile::include() 的 try/catch + ob 清理保护。
     */
    private function renderView(string $templateFile, array $data, int $statusCode): ResponseInterface
    {
        $view = new class($data) {
            private $data;
            private $cache = [];

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            /**
             * 支持点号多级键访问。
             * 例如 $view->get('website.current.view', '/views/')
             *
             * @param mixed $default
             * @return mixed
             */
            public function get(string $key, $default = null)
            {
                $cacheKey = 'get-' . $key;
                if (isset($this->cache[$cacheKey])) {
                    return $this->cache[$cacheKey];
                }

                // 一级键快速路径
                if (false === strpos($key, '.')) {
                    $this->cache[$cacheKey] = isset($this->data[$key]) ? $this->data[$key] : $default;
                    return $this->cache[$cacheKey];
                }

                // 多级键完整解析
                $result = $this->resolveNestedKey($key);
                $this->cache[$cacheKey] = ($result !== null) ? $result : $default;
                return $this->cache[$cacheKey];
            }

            /**
             * 递归解析点号分隔的多级键。
             * 安全阈值：最多 10 层，防止异常深度遍历。
             *
             * @return mixed|null
             */
            private function resolveNestedKey(string $key)
            {
                if (isset($this->cache['nested-' . $key])) {
                    return $this->cache['nested-' . $key];
                }

                $keys = explode('.', $key);
                if (count($keys) > 10) {
                    return null;
                }

                $current = $this->data;
                foreach ($keys as $segment) {
                    if (!is_array($current) || !array_key_exists($segment, $current)) {
                        $this->cache['nested-' . $key] = null;
                        return null;
                    }
                    $current = $current[$segment];
                }

                $this->cache['nested-' . $key] = $current;
                return $current;
            }

            /**
             * 安全输出（自动 htmlspecialchars），支持多级键。
             *
             * @param mixed $default
             * @return string
             */
            public function e(string $key, $default = '')
            {
                $value = $this->get($key, $default);
                return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            /**
             * 原始输出，支持多级键。
             *
             * @param mixed $default
             * @return mixed
             */
            public function raw(string $key, $default = '')
            {
                return $this->get($key, $default);
            }
        };

        try {
            ob_start();
            include \App\Core\Compile::include($templateFile);
            $body = ob_get_clean() ?: '';
        } catch (\Throwable $renderError) {
            // 审计修订：确保 ob 缓冲在任何异常路径下都被清理
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->logInternalError('Error view render failed', $renderError);
            return $this->createFallbackHtmlResponse($data, $statusCode);
        }

        $response = new Response($statusCode);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function createFallbackHtmlResponse(array $data, int $statusCode): ResponseInterface
    {
        $html = "<html><head><title>Operation Notice</title><meta charset='utf-8'></head>";
        $html .= "<body style='font-family:sans-serif;padding:2rem;'>";
        $html .= "<h2 style='color:#dc3545;'>Notice</h2>";
        $html .= "<p>" . htmlspecialchars($data['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";

        if ($this->debug && isset($data['debug'])) {
            $html .= "<hr><pre style='background:#f4f4f4;padding:1rem;overflow:auto;'>";
            $debugText = ($data['debug']['exception'] ?? '') . "\n"
                . ($data['debug']['file'] ?? '') . ' : ' . ($data['debug']['line'] ?? '') . "\n\n"
                . json_encode($data['debug']['trace'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $html .= htmlspecialchars($debugText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "</pre>";
        }

        $html .= "</body></html>";

        $response = new Response($statusCode);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 审计修订：增加 json_encode() 失败检测与降级处理。
     */
    private function createJsonResponse(array $data, int $statusCode): ResponseInterface
    {
        $response = new Response($statusCode);
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PARTIAL_OUTPUT_ON_ERROR
                | ($this->debug ? JSON_PRETTY_PRINT : 0)
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        // json_encode 失败时降级为安全 JSON
        if ($json === false) {
            $error = json_last_error_msg();
            error_log('ErrorResponseBuilder JSON Encode Error: ' . $error);
            $fallback = [
                'status'    => 'error',
                'code'      => 500,
                'message'   => 'Internal JSON Error',
                'timestamp' => time(),
            ];
            $json = json_encode($fallback, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }

        $response->getBody()->write((string)$json);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function logInternalError(string $message, \Throwable $e): void
    {
        try {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error($message . ': ' . $e->getMessage(), [
                'file' => PathHelper::relative($e->getFile()),
                'line' => $e->getLine(),
                'type' => \get_class($e),
            ]);
        } catch (\Throwable $t) {
            error_log($message . ' fallback: ' . $t->getMessage());
        }
    }
}
