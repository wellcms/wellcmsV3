<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * WellCMS 3.0 工业级 Swoole 服务封装
 */
class SwooleServer
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var array */
    private $options;
    /** @var object */
    private $server;
    /** @var \Framework\Core\Container */
    private $container;
    /** @var \Framework\Http\Interfaces\KernelInterface */
    private $kernel;
    /** @var \Framework\Http\Psr7\ResponseSender */
    private $responseSender;
    /** @var \Framework\Http\Psr7\Factories\ServerRequestFactory */
    private $requestFactory;

    public function __construct(string $host = '0.0.0.0', int $port = 9501, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->options = array_merge([
            'worker_num' => function_exists('\\swoole_cpu_num') ? call_user_func('\\swoole_cpu_num') : 4,
            'open_tcp_nodelay' => true,
            'max_request' => 10000,
            'daemonize' => false,
            'reload_async' => true,
            'max_wait_time' => 30,
            'pid_file' => \Framework\Utils\Runtime::getPidFile(),
            'log_file' => \APP_PATH . 'storage/logs/swoole.log',
            'enable_coroutine' => true,
        ], $options);
    }

    /**
     * 启动服务
     */
    public function start(): void
    {
        // 0. 核心引擎预热（工业级必须：采集钩子、合并覆盖文件）
        $containerCache = \APP_PATH . 'storage/tmp/container.php';
        \App\Core\Compile::init($containerCache);

        // 1. 初始化容器 (预热静态依赖)
        $this->container = \App\Bootstrap::init();

        // 2. 容器性能优化：加载预编译定义 (重要)
        if (\file_exists($containerCache) && (!\defined('DEBUG') || (int)\DEBUG === 0)) {
            $defs = require $containerCache;
            $this->container->loadDefinitions($defs);
        }

        $serverClass = "\\Swoole\\Http\\Server";
        $this->server = new $serverClass($this->host, $this->port);
        $this->server->set($this->options);

        // 注册事件
        $this->server->on("Start", [$this, "onStart"]);
        $this->server->on("ManagerStart", [$this, "onManagerStart"]);
        $this->server->on("WorkerStart", [$this, "onWorkerStart"]);
        $this->server->on("WorkerError", [$this, "onWorkerError"]);
        $this->server->on("WorkerStop", [$this, "onWorkerStop"]);
        $this->server->on("Request", [$this, "onRequest"]);

        $this->printBanner();

        $this->server->start();
    }

    private function printBanner(): void
    {
        $v = \defined('SWOOLE_VERSION') ? \SWOOLE_VERSION : 'Unknown';
        echo "\033[32m";
        echo "--------------------------------------------------\n";
        echo " WellCMS 3.0 Swoole Server \n";
        echo "--------------------------------------------------\n";
        echo " Host:Port:  {$this->host}:{$this->port}\n";
        echo " Swoole:     v{$v}\n";
        echo " WorkerNum:  {$this->options['worker_num']}\n";
        echo " Daemonize:  " . ($this->options['daemonize'] ? 'True' : 'False') . "\n";
        echo "--------------------------------------------------\n";
        echo "\033[0m";
    }

    /** @param object $server */
    public function onStart(object $server): void
    {
        $this->setProcessName("well-server: master");
        /** @noinspection PhpUndefinedFieldInspection */
        echo "Master PID: {$server->master_pid}\n";
        /** @noinspection PhpUndefinedFieldInspection */
        echo "Manager PID: {$server->manager_pid}\n";
    }

    /** @param object $server */
    public function onManagerStart(object $server): void
    {
        $this->setProcessName("well-server: manager");
    }

    /**
     * @param object $server
     * @param int $workerId
     */
    public function onWorkerStart(object $server, int $workerId): void
    {
        /** @noinspection PhpUndefinedFieldInspection */
        if ($server->taskworker) {
            $this->setProcessName("well-server: task_worker");
        } else {
            $this->setProcessName("well-server: worker");

            // 预解析无状态核心服务，实现“单请求零解析”优化
            $this->kernel = $this->container->get(\Framework\Http\Interfaces\KernelInterface::class);
            $this->responseSender = $this->container->get(\Framework\Http\Psr7\ResponseSender::class);
            $this->requestFactory = $this->container->get(\Framework\Http\Psr7\Factories\ServerRequestFactory::class);
        }
    }

    private function setProcessName(string $name): void
    {
        if (PHP_OS !== 'Darwin' && function_exists('\\swoole_set_process_name')) {
            @call_user_func('\\swoole_set_process_name', $name);
        }
    }

    /**
     * @param object $swooleRequest
     * @param object $swooleResponse
     */
    public function onRequest(object $swooleRequest, object $swooleResponse): void
    {
        // 1. 建立协程上下文 (Context Isolation)
        $coroClass = "\\Swoole\\Coroutine";
        $ctx = call_user_func([$coroClass, 'getContext']);
        $ctx['swoole_response'] = $swooleResponse;
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['header'] = $swooleRequest->header;
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['server'] = $swooleRequest->server;
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['get'] = $swooleRequest->get ?? [];
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['post'] = $swooleRequest->post ?? [];
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['files'] = $swooleRequest->files ?? [];
        /** @noinspection PhpUndefinedFieldInspection */
        $ctx['cookie'] = $swooleRequest->cookie ?? [];
        /** @noinspection PhpUndefinedMethodInspection */
        $ctx['rawContent'] = $swooleRequest->rawContent();

        // 2. 构造 PSR-7 请求对象 (使用预解析的工厂)
        $psrRequest = $this->requestFactory->createFromGlobals();

        try {
            // 3. 执行业务逻辑 (使用预解析的 Kernel)
            $psrResponse = $this->kernel->handle($psrRequest);

            // 4. 发送响应 (使用预解析的 Sender)
            $this->responseSender->send($psrResponse);
        } catch (\Throwable $e) {
            // 异常兜底处理：Swoole 模式下必须直接写入 Response
            $ctx = call_user_func([$coroClass, 'getContext']);
            $swooleResponse = $ctx['swoole_response'] ?? null;
            if ($swooleResponse && is_a($swooleResponse, "\\Swoole\\Http\\Response")) {
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->status(500);
                $msg = \defined('DEBUG') && \DEBUG ? $e->getMessage() : 'Internal Server Error';
                /** @noinspection PhpUndefinedMethodInspection */
                $swooleResponse->end($msg);
            } else {
                // 极端 fallback：Response 对象丢失时回退到 stderr
                fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            }
        }
    }

    /**
     * @param object $server
     * @param int $workerId
     * @param int $workerPid
     * @param int $exitCode
     * @param int $signal
     */
    public function onWorkerError(object $server, int $workerId, int $workerPid, int $exitCode, int $signal): void
    {
        error_log("[Swoole Worker Error] workerId={$workerId}, pid={$workerPid}, exitCode={$exitCode}, signal={$signal}");
    }

    /**
     * @param object $server
     * @param int $workerId
     */
    public function onWorkerStop(object $server, int $workerId): void
    {
        error_log("[Swoole Worker Stop] workerId={$workerId}");
    }
}