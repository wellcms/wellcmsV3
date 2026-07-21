<?php

declare(strict_types=1);

namespace Framework\Scheduler\Interfaces;

/**
 * 僵尸任务检测接口
 *
 * v3.2 新增: 抽象 Worker 僵尸检测与标记。
 * 实现见 App\Services\Scheduler\Detection\ZombieHandler。
 * PHP 7.2 兼容。
 */
interface ZombieDetectorInterface
{
    /**
     * 检测僵尸任务
     *
     * 阈值由实现层 configure() 注入，接口无需感知具体值。
     *
     * @return array [['id' => string, 'class_name' => string], ...]
     */
    public function detectZombies(): array;

    /**
     * 标记僵尸任务为失败
     *
     * @param string $taskId
     */
    public function markZombieTask(string $taskId): void;
}
