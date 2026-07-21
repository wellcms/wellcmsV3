<?php

declare(strict_types=1);

namespace Framework\Scheduler\Jobs;

class CallbackJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \Framework\Scheduler\Logger */
    private $logger;

    /** @var \Framework\Scheduler\HttpResultCallback|null */
    private $httpCallback;

    /**
     * @param \Framework\Scheduler\Logger|null               $logger
     * @param \Framework\Scheduler\HttpResultCallback|null    $httpCallback 从容器注入，未提供时降级为直接实例化
     */
    public function __construct(
        ?\Framework\Scheduler\Logger $logger = null,
        ?\Framework\Scheduler\HttpResultCallback $httpCallback = null
    ) {
        $this->logger = $logger ?? new \Framework\Scheduler\Logger();
        $this->httpCallback = $httpCallback;
    }

    /**
     * 执行回调通知
     * @param array $taskData 原始任务数据的数组形式
     * @param bool $isSuccess 任务是否成功
     * @param float $elapsed 耗时
     * @param string $error 错误信息
     */
    public function handle(array $taskData, bool $isSuccess, float $elapsed, string $error): void
    {
        // 重建 Task 对象，以便 HttpResultCallback 使用
        // 注意：这里需要 Task::fromArray 支持，或者根据你的 Task 结构手动构造
        try {
            $task = \Framework\Scheduler\Task::fromArray($taskData);

            // 调用 HttpResultCallback 发送请求，优先使用容器注入的实例
            $callback = $this->httpCallback ?? new \Framework\Scheduler\HttpResultCallback();
            $callback->notify($task, $isSuccess, $elapsed, $error);
        } catch (\Throwable $e) {
            // 回调发送失败，记录日志，通常不需要再次抛出异常（避免回调死循环）
            // 也可以根据需求决定是否重试
            $this->logger->log('Async callback failed: ' . $e->getMessage(), 'ERROR');
        }
    }
}