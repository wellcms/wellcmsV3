<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler\Queue;

use Framework\Scheduler\Task;
use Framework\Scheduler\Interfaces\TaskQueueInterface;
use Framework\Scheduler\CircuitBreaker;
use Framework\Scheduler\EventBus;

class CircuitBreakerQueue implements TaskQueueInterface
{
    /** @var TaskQueueInterface */
    private $inner;

    /** @var CircuitBreaker */
    private $breaker;

    /** @var EventBus|null */
    private $eventBus;

    /**
     * @param TaskQueueInterface $inner
     * @param CircuitBreaker     $breaker
     * @param EventBus|null      $eventBus
     */
    public function __construct(
        TaskQueueInterface $inner,
        CircuitBreaker $breaker,
        ?EventBus $eventBus = null
    ) {
        $this->inner = $inner;
        $this->breaker = $breaker;
        $this->eventBus = $eventBus;
    }

    /**
     * 入队前检查熔断
     * 注意: 系统种子（system: 前缀）跳过熔断检查，
     * 防止熔断开启时调度器无法自愈。
     *
     * @param Task $task
     * @throws \RuntimeException
     */
    public function push(Task $task): void
    {
        // C-2 修复: 系统种子跳过熔断，防止自愈死锁
        if ($task->dedupeKey !== '' && strpos($task->dedupeKey, 'system:') === 0) {
            $this->inner->push($task);
            return;
        }

        if ($this->breaker->isOpen($task->className)) {
            $count = $this->breaker->getFailureCount($task->className);
            throw new \RuntimeException(
                'Circuit breaker is open for ' . $task->className
                . ' (' . $count . ' failures in window)'
            );
        }
        $this->inner->push($task);
    }

    /**
     * 失败时记录熔断计数
     *
     * @param Task $task
     */
    public function moveToFailedQueue(Task $task): void
    {
        $this->inner->moveToFailedQueue($task);
        $state = $this->breaker->recordFailure($task->className);

        if ($state === CircuitBreaker::STATE_OPEN && $this->eventBus !== null) {
            $this->eventBus->emit('circuit.opened', [
                'class_name' => $task->className,
                'failure_count' => $this->breaker->getFailureCount($task->className),
            ]);
        }
    }

    /**
     * 成功时清除熔断计数
     *
     * @param Task $task
     */
    public function moveToSuccessQueue(Task $task): void
    {
        $wasOpen = $this->breaker->isOpen($task->className);
        $this->breaker->recordSuccess($task->className);
        $this->inner->moveToSuccessQueue($task);

        if ($wasOpen && $this->eventBus !== null) {
            $this->eventBus->emit('circuit.closed', [
                'class_name' => $task->className,
            ]);
        }
    }

    // ── 以下方法直接透传 ──

    public function pop(): ?Task { return $this->inner->pop(); }
    public function requeue(Task $task): void { $this->inner->requeue($task); }
    public function reschedule(Task $task): void { $this->inner->reschedule($task); }
    public function remove(string $taskId): void { $this->inner->remove($taskId); }
}
