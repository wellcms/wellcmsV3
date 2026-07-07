<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

use Framework\Http\Interfaces\ResponseInterface;

class RuntimeMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
    }

    /**
     * 运行级别策略映射
     * 0 => 关闭；1 => 管理员可读写；2 => 会员只读；3 => 会员可读写；4 => 所有人只读；5 => 所有人可读写
     *
     * @var array
     */
    private $runLevelStrategies = [
        0 => 'handleClosedMode',
        1 => 'handleAdministratorOnlyMode',
        2 => 'handleGetOnlyMode',
        3 => 'handleRegisteredOnly',
        4 => 'handleSafeMode',
        5 => 'handleNormalMode'
    ];

    private function restrictAccess(\Framework\Http\Interfaces\ServerRequestInterface $request, string $message): ResponseInterface
    {
        // 临时的 stack 压入，确保容器能解析到请求相关的依赖 (如 LanguageLoader)
        \Framework\Http\Psr7\RequestStack::push($request);
        try {
            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage($message, 307);
        } finally {
            \Framework\Http\Psr7\RequestStack::pop();
        }
    }

    /**
     * 根据配置及用户组 ID 判断是否允许当前请求的操作
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    public function runLevel(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        // 特定路由规则，允许的操作
        $rules = [
            'auth' => ['signIn', 'postSignIn', 'signUp', 'postSignUp', 'signOut', 'postVerifyCode', 'sendPasswordLink', 'resetPassword', 'resetCompleted', 'twoStepVerification', 'synSignIn']
        ];

        // hook app_Middleware_Runtime_runlevel_start.php

        // 管理员直接放行
        if (1 === $userGroupId) return true;

        $param0  = $request->getQueryParams()[0] ?? '';
        $param1  = $request->getQueryParams()[1] ?? '';
        foreach ($rules as $route => $actions) {
            if ($param0 === $route && (empty($actions) || in_array($param1, $actions, true))) return true;
        }

        // hook app_Middleware_Runtime_runlevel_after.php

        $level = isset($config['runlevel']) ? (int)$config['runlevel'] : 5;
        $method = isset($this->runLevelStrategies[$level]) ? $this->runLevelStrategies[$level] : 'handleNormalMode';

        // hook app_Middleware_Runtime_runlevel_end.php

        if (method_exists($this, $method)) {
            return $this->$method($request, $config, $userGroupId);
        }
        throw new \RuntimeException("Invalid runlevel strategy: $level");
    }

    /**
     * 关闭模式：直接输出原因信息
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleClosedMode(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        $reason = isset($config['runlevel_reason']) ? $config['runlevel_reason'] : 'Service is closed';
        return $this->restrictAccess($request, $reason);
    }

    /**
     * 管理员专用模式：非管理员拒绝
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleAdministratorOnlyMode(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        $language = $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
        return $this->restrictAccess($request, $language->get('runlevel_reason_1'));
    }

    /**
     * 只读模式（GET 请求）：非 GET 请求拒绝
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleGetOnlyMode(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        // 1. 拦截非会员 (游客)
        if (0 === $userGroupId) {
            $language = $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
            return $this->restrictAccess($request, $language->get('runlevel_reason_2'));
        }

        // 2. 拦截非 GET 操作
        $method = $request->getMethod();
        if ('GET' !== strtoupper($method)) {
            $language = $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
            return $this->restrictAccess($request, $language->get('runlevel_reason_2'));
        }
        return true;
    }

    /**
     * 注册用户模式：仅允许已注册用户操作
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleRegisteredOnly(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        if (0 === $userGroupId) {
            $language = $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
            return $this->restrictAccess($request, $language->get('runlevel_reason_3'));
        }
        return true;
    }

    /**
     * 安全模式：仅允许 GET 请求
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleSafeMode(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId)
    {
        $method = $request->getMethod();
        if ('GET' !== strtoupper($method)) {
            $language = $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
            return $this->restrictAccess($request, $language->get('runlevel_reason_4'));
        }
        return true;
    }

    /**
     * 正常模式：所有操作允许
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     * @param array $config
     * @param int   $userGroupId
     * @return bool|ResponseInterface
     */
    private function handleNormalMode(\Framework\Http\Interfaces\ServerRequestInterface $request, array $config, int $userGroupId): bool{
        return true;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $user = $request->getAttribute('user', null);
            if (empty($user)) {
                $userService = $this->container->get(\App\Services\Auth\UserService::class);
                $userService->captureContext($request);

                // Phase 1: Capture context for Token and Session services
                $this->container->get(\App\Services\Auth\TokenService::class)->captureContext($request);
                $this->container->get(\App\Services\Auth\SessionService::class)->captureContext($request);

                // Phase 2: Capture context for Business Support services
                if ($this->container->has(\App\Services\Storage\UploadService::class)) {
                    $this->container->get(\App\Services\Storage\UploadService::class)->captureContext($request);
                }
                if ($this->container->has(\App\Services\System\IpListService::class)) {
                    $this->container->get(\App\Services\System\IpListService::class)->captureContext($request);
                }

                $user = $userService->getCurrentUser(0);

                // 主程序封禁用户组(group_id=6)快速拦截
                $groupId = (int)($user['group_id'] ?? 0);
                if ($groupId === 6) {
                    throw new \Framework\Exception\BusinessException('well_forum_user_banned', 403);
                }

                // hook app_Middleware_Runtime_process_capture_context.php
            }
            $request = $request->withAttribute('user', $user); // 用户信息挂载

            $appConfig = $this->container->get('appConfig');
            $groupId = isset($user['group_id']) ? (int)$user['group_id'] : 0;
            // 处理 runLevel 返回的响应
            $runLevelResult = $this->runLevel($request, $appConfig, $groupId);
            if ($runLevelResult instanceof ResponseInterface) {
                return $runLevelResult;
            }

            return $handler->handle($request);
        } catch (\Throwable $e) {
            // 路由未命中属于正常 HTTP 语义，不应由 Runtime 层接管
            if ($e instanceof \Framework\Exception\Http\NotFoundException) {
                throw $e;
            }

            // 404 异常不作为系统级 Error 记录，减少噪音
            if (!($e instanceof \Framework\Exception\Http\NotFoundException)) {
                $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
                $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            }

            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (\defined('DEBUG') && \DEBUG >= 2) throw $e;

            // 这里你可以写日志，也可以直接返回错误提示
            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('Runtime verification error: ' . $e->getMessage(), 500);
        }
    }
}