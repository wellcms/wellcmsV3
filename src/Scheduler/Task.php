<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * 任务实体：描述一件要执行的工作
 */
class Task
{
    /** 以下为必填字段 **/
    /**
     * 任务唯一 ID，字符串
     * @var string
     */
    public $id;
    /**
     * 回调类名（全限定名）
     * @var string
     */
    public $className;
    /**
     * 回调方法名（静态或实例方法）
     * @var string
     */
    public $methodName;
    /**
     * 回调参数，数组
     * @var array
     */
    public $args;
    /**
     * 幂等去重 Key，可选
     * @var string
     */
    public $dedupeKey;

    /**
     * 优先级：整数，值越小优先级越高（比如 0-10）
     * @var int
     */
    public $priority;

    /**
     * 最大重试次数 >= 0
     * @var int
     */
    public $maxRetries;
    /**
     * 重试间隔：秒
     * @var int
     */
    public $retryDelay;
    /**
     * 单次超时设置（秒），0 表示不启用超时
     * @var int
     */
    public $timeout;

    /** 回调配置 **/
    /**
     * 任务执行完成后回调的 HTTP 地址，可选
     * @var string
     */
    public $callbackUrl;
    /**
     * 调用回调 URL 时使用的 HTTP 方法：METHOD_POST(0) / METHOD_GET(1)
     * @var int
     */
    public $callbackMethod;

    /** 状态字段 **/
    /**
     * 当前已重试次数
     * @var int
     */
    public $retryCount;
    /**
     * 任务状态常量值：STATUS_PENDING(0) / RETRYING(1) / RUNNING(2) / SUCCESS(3) / FAILED(4) / CANCELLED(5)
     * @var int
     */
    public $status;
    /**
     * 任务创建时间，Unix timestamp
     * @var int
     */
    public $createdAt;
    /**
     * 任务最后更新时间，Unix timestamp
     * @var int
     */
    public $updatedAt;
    /**
     * 计划执行时间
     * @var int
     */
    public $scheduledAt;
    /**
     * 开始执行时间（PersistenceQueue 使用）
     * @var int
     */
    public $startedAt;
    /**
     * 完成时间（PersistenceQueue 使用）
     * @var int
     */
    public $completedAt;
    /**
     * 错误信息（执行失败时填充）
     * @var string
     */
    public $error;
    public const ALLOWED_JOB_NAMESPACES = ['App\\', 'Framework\\Scheduler\\', 'Plugins\\'];

    // ── 状态常量（MySQL tinyint 映射） ──
    const STATUS_PENDING   = 0;
    const STATUS_RETRYING  = 1;
    const STATUS_RUNNING   = 2;
    const STATUS_SUCCESS   = 3;
    const STATUS_FAILED    = 4;
    const STATUS_CANCELLED = 5;

    // ── HTTP 方法常量（MySQL tinyint 映射） ──
    const METHOD_POST = 0;
    const METHOD_GET  = 1;

    /** @var array 旧版 status 字符串→新版 int 映射 */
    private static $statusMapFromString = [
        'pending'   => self::STATUS_PENDING,
        'retrying'  => self::STATUS_RETRYING,
        'running'   => self::STATUS_RUNNING,
        'success'   => self::STATUS_SUCCESS,
        'failed'    => self::STATUS_FAILED,
        'cancelled' => self::STATUS_CANCELLED,
    ];

    /** @var array 旧版 HTTP method 字符串→新版 int 映射 */
    private static $methodMapFromString = [
        'POST' => self::METHOD_POST,
        'GET'  => self::METHOD_GET,
    ];

    public function __construct(
        string $id,
        string $className,
        string $methodName,
        array $args = [],
        int $priority = 5,
        int $maxRetries = 0,
        int $retryDelay = 0,
        int $timeout = 0,
        string $callbackUrl = '',
        $callbackMethod = self::METHOD_POST,
        bool $validateClass = true
    ) {
        // 前置验证：使用 Task::sanitizeXxx() 方法确保数据安全性和一致性
        $this->id             = self::sanitizeId($id);
        $this->className      = $validateClass ? self::sanitizeClassName($className) : self::sanitizeClassNameFormat($className);
        $this->methodName     = self::sanitizeMethodName($methodName);
        $this->args           = self::sanitizeArgs($args);
        $this->priority       = self::sanitizePriority($priority);
        $this->maxRetries     = self::sanitizeRetryCount($maxRetries);
        $this->retryDelay     = self::sanitizeRetryDelay($retryDelay);
        $this->timeout        = self::sanitizeTimeout($timeout);
        $this->callbackUrl    = self::sanitizeUrl($callbackUrl);
        $this->callbackMethod = self::sanitizeHttpMethod($callbackMethod);

        $this->retryCount     = 0;
        $this->status         = self::STATUS_PENDING;
        $this->createdAt      = time();
        $this->updatedAt      = time();
        $this->scheduledAt    = time();
        $this->dedupeKey      = '';
        $this->error          = '';
    }

    /**
     * 序列化为数组（用于队列存储）
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'className'      => $this->className,
            'methodName'     => $this->methodName,
            'args'           => $this->args,
            'priority'       => $this->priority,
            'maxRetries'     => $this->maxRetries,
            'retryDelay'     => $this->retryDelay,
            'timeout'        => $this->timeout,
            'callbackUrl'    => $this->callbackUrl,
            'callbackMethod' => $this->callbackMethod,
            'retryCount'     => $this->retryCount,
            'status'         => $this->status,
            'createdAt'      => $this->createdAt,
            'updatedAt'      => $this->updatedAt,
            'scheduledAt'    => $this->scheduledAt,
            'dedupeKey'      => $this->dedupeKey,
            'error'          => $this->error,
        ];
    }

    /**
     * 从数组反序列化
     */
    public static function fromArray(array $data, bool $strictClassValidation = true): Task
    {
        // 1. 验证必需字段
        $required = ['id', 'className', 'methodName'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new \InvalidArgumentException("Missing or invalid required field: {$field}");
            }
        }

        // 2. 根据 strict 模式选择校验级别
        $className = $strictClassValidation
            ? self::sanitizeClassName($data['className'])
            : self::sanitizeClassNameFormat($data['className']);

        // 3. 创建基础任务对象
        $task = new Task(
            self::sanitizeId($data['id']),
            $className,
            self::sanitizeMethodName($data['methodName']),
            self::sanitizeArgs($data['args'] ?? []),
            self::sanitizePriority($data['priority'] ?? 5),
            self::sanitizeRetryCount($data['maxRetries'] ?? 0),
            self::sanitizeRetryDelay($data['retryDelay'] ?? 0),
            self::sanitizeTimeout($data['timeout'] ?? 0),
            self::sanitizeUrl($data['callbackUrl'] ?? ''),
            self::sanitizeHttpMethod($data['callbackMethod'] ?? self::METHOD_POST),
            $strictClassValidation
        );

        // 3. 设置状态字段
        $task->retryCount = self::sanitizeRetryCount($data['retryCount'] ?? 0);
        $task->status = self::sanitizeStatus($data['status'] ?? self::STATUS_PENDING);
        $task->createdAt = self::sanitizeTimestamp($data['createdAt'] ?? time());
        $task->updatedAt = self::sanitizeTimestamp($data['updatedAt'] ?? time());
        $task->scheduledAt = self::sanitizeTimestamp($data['scheduledAt'] ?? time());
        $task->dedupeKey = (string)($data['dedupeKey'] ?? '');
        $task->error = (string)($data['error'] ?? '');

        return $task;
    }

    // ========== 安全验证方法 ==========

    public static function sanitizeId(string $id): string
    {
        if (strlen($id) > 128) {
            throw new \InvalidArgumentException('Invalid task ID format');
        }
        return $id;
    }

    /**
     * 格式级校验：类名字符格式 + 命名空间白名单
     * 存储层反序列化使用此级别
     */
    public static function sanitizeClassNameFormat(string $className): string
    {
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/', $className)) {
            throw new \InvalidArgumentException('Invalid class name format');
        }

        $allowed = false;
        foreach (self::ALLOWED_JOB_NAMESPACES as $ns) {
            if (strpos($className, $ns) === 0) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new \InvalidArgumentException('Class not in allowed namespace');
        }

        return $className;
    }

    /**
     * 执行级校验：类是否存在 + 是否实现 JobInterface
     * TaskExecutor 执行前使用此级别
     */
    public static function validateClassExecutable(string $className): void
    {
        if (!class_exists($className)) {
            throw new \RuntimeException("Class does not exist: {$className}");
        }
        $reflection = new \ReflectionClass($className);
        if (!$reflection->implementsInterface(\Framework\Scheduler\Interfaces\JobInterface::class)) {
            throw new \RuntimeException("Class must implement JobInterface: {$className}");
        }
    }

    /**
     * 完整校验：格式 + 执行级校验
     */
    public static function sanitizeClassName(string $className): string
    {
        $className = self::sanitizeClassNameFormat($className);
        self::validateClassExecutable($className);
        return $className;
    }

    public static function sanitizeMethodName(string $method): string
    {
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $method)) {
            throw new \InvalidArgumentException('Invalid method name format');
        }
        return $method;
    }

    /**
     * @param array $args
     */
    public static function sanitizeArgs($args): array
    {
        if (!is_array($args)) {
            return [];
        }

        // 限制参数大小
        $jsonSize = strlen(json_encode($args, JSON_UNESCAPED_UNICODE));
        if ($jsonSize > 4096) { // 4KB限制
            throw new \InvalidArgumentException('Task arguments too large');
        }

        return $args;
    }

    public static function sanitizePriority(int $priority): int
    {
        $priority = (int)$priority;
        return max(0, min(10, $priority)); // 限制在 0-10 范围
    }

    /**
     * @param int $count
     */
    public static function sanitizeRetryCount($count): int
    {
        $count = (int)$count;
        return max(0, min(100, $count)); // 最大重试100次
    }

    public static function sanitizeRetryDelay(int $delay): int
    {
        $delay = (int)$delay;
        return max(0, min(86400, $delay)); // 最大延迟1天
    }

    /**
     * @param int $timeout
     */
    public static function sanitizeTimeout(int $timeout): int
    {
        $timeout = (int)$timeout;
        return max(0, min(3600, $timeout)); // 最大超时1小时
    }

    /**
     * @param string $url
     */
    public static function sanitizeUrl($url): string
    {
        $url = (string)$url;
        if ($url === '') {
            return '';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid callback URL format');
        }

        return $url;
    }

    /**
     * 规范化 HTTP 方法，兼容旧版字符串和新版 int
     *
     * @param int|string $method  METHOD_POST(0) / METHOD_GET(1) 或旧版 'POST' / 'GET'
     * @return int
     */
    public static function sanitizeHttpMethod($method): int
    {
        if (is_int($method) || is_numeric($method)) {
            $method = (int)$method;
            return in_array($method, [self::METHOD_POST, self::METHOD_GET], true) ? $method : self::METHOD_POST;
        }
        $method = strtoupper((string)$method);
        return isset(self::$methodMapFromString[$method]) ? self::$methodMapFromString[$method] : self::METHOD_POST;
    }

    /**
     * 规范化任务状态，兼容旧版字符串和新版 int
     *
     * @param int|string $status   STATUS_* 常量值或旧版 'pending' / 'running' / 'failed' / 'success' / 'retrying' / 'cancelled'
     * @return int
     */
    public static function sanitizeStatus($status): int
    {
        if (is_int($status) || is_numeric($status)) {
            $status = (int)$status;
            $allowed = [self::STATUS_PENDING, self::STATUS_RETRYING, self::STATUS_RUNNING, self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELLED];
            return in_array($status, $allowed, true) ? $status : self::STATUS_PENDING;
        }
        $status = (string)$status;
        return isset(self::$statusMapFromString[$status]) ? self::$statusMapFromString[$status] : self::STATUS_PENDING;
    }

    /**
     * @param int $timestamp
     */
    public static function sanitizeTimestamp($timestamp): int
    {
        $timestamp = (int)$timestamp;
        $now = time();
        // 允许过去1年到未来1年的时间戳
        if ($timestamp < ($now - 31536000) || $timestamp > ($now + 31536000)) {
            return $now;
        }
        return $timestamp;
    }
}
