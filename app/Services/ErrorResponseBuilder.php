<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\PathHelper;
use App\View\Error\ConfiguredErrorViewModel;
use App\View\Error\ErrorViewModelInterface;
use App\View\Error\ErrorViewRenderer;
use Framework\Core\Container;
use Framework\Exception\BusinessException;
use Framework\Exception\ExceptionInterface;
use Framework\Exception\ValidationException;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Http\Response;
use Framework\Utils\LoggerContext;

class ErrorResponseBuilder
{
    /** @var Container */
    private $container;

    /** @var bool */
    private $debug;

    /** @var array */
    private $errorConfig;

    /** @var ErrorViewRenderer|null */
    private $renderer;

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
     * 从容器获取 ErrorViewRenderer，带防御性兜底。
     */
    private function getRenderer(): ErrorViewRenderer
    {
        if ($this->renderer === null) {
            try {
                if ($this->container->has(ErrorViewRenderer::class)) {
                    $this->renderer = $this->container->get(ErrorViewRenderer::class);
                }
            } catch (\Throwable $e) {
                error_log('ErrorResponseBuilder::getRenderer fallback: ' . $e->getMessage());
            }
            if ($this->renderer === null) {
                $this->renderer = new ErrorViewRenderer();
            }
        }
        return $this->renderer;
    }

    /**
     * 渲染系统错误视图。
     * 复用统一 ErrorViewRenderer，注入 ConfiguredErrorViewModel + 运行时数据。
     */
    private function renderView(string $templateFile, array $data, int $statusCode): ResponseInterface
    {
        $view = $this->getErrorViewModelWithRuntimeData($data);
        $body = $this->getRenderer()->render($templateFile, $view);

        $response = new Response($statusCode);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 获取 ErrorViewModel 并注入运行时数据。
     * ViewModel 使用分层设计：配置层（appConfig 默认值）+ 运行时层（build() 的数据）。
     * 运行时数据优先级高于配置默认值，确保 message、debug、timestamp 等动态字段正确传递。
     */
    private function getErrorViewModelWithRuntimeData(array $runtimeData): ErrorViewModelInterface
    {
        try {
            $viewModel = $this->container->get(ErrorViewModelInterface::class);
            if ($viewModel instanceof ConfiguredErrorViewModel) {
                $viewModel->setRuntimeData($runtimeData);
            }
            return $viewModel;
        } catch (\Throwable $e) {
            error_log('ErrorResponseBuilder::getErrorViewModelWithRuntimeData fallback: ' . $e->getMessage());
            return new ConfiguredErrorViewModel([], $runtimeData);
        }
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
