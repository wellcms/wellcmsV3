<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
*/

/**
 * WellCMS Scheduler — 工业级配置
 *
 * 默认全部关闭。按需开启：
 *   v2_enabled → dual_write → circuit_breaker → event_bus → declarative
 * PHP 7.2 兼容。
 *
 * ═══════════════════════════════════════════════════════════════
 * 调优因素清单（根据实际部署环境调整）：
 *
 * 1. 运行模式（FPM vs Swoole）
 *    - FPM 单机：dual_write 需根据请求量开闭，circuit_breaker/event_bus/worker_coordinator 通常关闭
 *    - Swoole 常驻：可按需开启，swoole_workers 应与 CPU 核心数匹配
 *
 * 2. 并发规模
 *    - 低并发（<100 QPS）：dual_write.flush_interval 可增大（60-120s），减少写入频率
 *    - 高并发（>1000 QPS）：缩短 flush_interval（10-20s），考虑开启 circuit_breaker
 *
 * 3. 数据一致性要求
 *    - 允许轻微丢数据：dual_write.enabled = false，依赖内存队列即可
 *    - 要求严格持久化：dual_write.enabled = true，设置合理 compensate_ttl
 *
 * 4. 多实例部署
 *    - 单机：worker_coordinator 关闭
 *    - 多机/多 Worker：开启 worker_coordinator，heartbeat_ttl 应 < zombie_threshold/4
 *
 * 5. 监控需求
 *    - 需要审计追踪：开启 event_bus（consumer = 'consume'）
 *    - 仅需监控看板：开启 event_bus（consumer = 'watch'）
 *    - 无监控需求：event_bus 关闭
 *
 * 6. 可用性要求
 *    - 容忍偶发故障：circuit_breaker 可关闭或少设阈值
 *    - 要求高可用熔断保护：开启 circuit_breaker，failure_threshold 根据峰值估算
 *
 * 7. 基础设施资源
 *    - Redis 可用：event_bus/worker_coordinator 依赖 Redis，确认已部署
 *    - 仅 MySQL：不要开启上述组件
 *    - CPU 核数：swoole_workers ≤ CPU 核心数的 2 倍
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * @return array
 */
return [

    // ══════════════════════════════════════════
    // 总开关
    // ══════════════════════════════════════════
    'v2_enabled' => true,  // bool: 空 false=原始模式, true=启用工业级功能

    // ══════════════════════════════════════════
    // 双写持久化 (PersistenceQueue)
    // ══════════════════════════════════════════
    'dual_write' => [
        'enabled'        => true,  // bool: 启用 MySQL 持久化
        'flush_interval' => 30,     // int:  秒，异步写入刷新间隔 (10-300)
        'compensate_ttl' => 30,     // int:  秒，补偿检查延迟 (10-120)
    ],

    // ══════════════════════════════════════════
    // 多 Worker 协调 (WorkerCoordinator)
    // ══════════════════════════════════════════
    'worker_coordinator' => [
        'enabled'          => false,  // bool: 启用多 Worker 协调
        'heartbeat_ttl'    => 30,     // int:  秒，心跳 TTL (10-120)
        'zombie_threshold' => 120,    // int:  秒，僵尸判定阈值 (60-600)
    ],

    // ══════════════════════════════════════════
    // 熔断器 (CircuitBreaker)
    // ══════════════════════════════════════════
    'circuit_breaker' => [
        'enabled'           => false, // bool: 启用熔断
        'failure_threshold' => 10,    // int:  窗口内失败次数阈值 (3-100)
        'window_seconds'    => 300,   // int:  秒，统计窗口 (60-3600)
        'open_seconds'      => 300,   // int:  秒，熔断持续时间 (60-3600)
        'half_open_ttl'     => 60,    // int:  秒，半开探测间隔 (10-300)
    ],

    // ══════════════════════════════════════════
    // 事件总线 (EventBus)
    // ══════════════════════════════════════════
    'event_bus' => [
        'enabled'    => false,   // bool: 启用事件采集
        'max_events' => 10000,   // int:  Redis List 最大事件数 (1000-100000)
        'consumer'   => 'watch', // 'watch'=监控非破坏读取, 'consume'=审计破坏消费
    ],

    // ══════════════════════════════════════════
    // 声明式调度 (DeclarativeEngine)
    // ══════════════════════════════════════════
    'declarative_scheduling' => [
        'enabled' => false, // bool: 启用声明式调度
        'classes' => [      // @var string[] 实现 ScheduleProviderInterface 的类名
            // 'App\\Jobs\\TempUploadCleanupJob',
            // 'App\\Jobs\\PartitionMaintainJob',
        ],
    ],

    // ══════════════════════════════════════════
    // Swoole 协程超时保护
    // ══════════════════════════════════════════
    'swoole_timeout' => [
        'enabled' => false, // bool: 启用 Swoole 协程超时保护
    ],

    // ══════════════════════════════════════════
    // Swoole 协程 Worker 池
    // ══════════════════════════════════════════
    'swoole_workers' => 4, // int: 并发协程数 (1-64)

];