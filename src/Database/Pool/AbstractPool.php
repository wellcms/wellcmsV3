<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

use Framework\Exception\Infra\PoolException;
use Framework\Logger\LoggerInterface;

/**
 * 连接池抽象基类
 *
 * 封装节点管理、事务状态、健康检查纯逻辑等公共能力。
 * FPM 与协程子类分别实现各自的连接获取/释放/扩缩容策略。
 */
abstract class AbstractPool implements PoolInterface
{
    use CoroutineAwareTrait;

    /** @var \Framework\Database\Interfaces\DatabaseInterface 驱动实例 **/
    protected $driver;

    /** @var \Framework\Database\Interfaces\ConnectionFactoryInterface **/
    protected $factory;

    /** @var array 默认主库配置 */
    protected $masterConfig;

    /** @var array [shard => ShardPool] */
    protected $shardPools = [];

    /** @var \Framework\Database\Pool\LoadBalancer\LoadBalancerInterface 从库选取策略 */
    protected $loadBalancer;

    /** @var int 最大连接数 **/
    protected $maxConnections;
    /** @var int 最小连接数 **/
    protected $minConnections;

    /** @var int 超时时间（秒） **/
    protected $timeout;

    /** @var float 利用率阈值 **/
    protected $threshold;
    /** @var float 扩缩容阈值 **/
    protected $adjustThreshold;

    /** @var int 统计指标 **/
    protected $totalRequests = 0;
    /** @var int 成功请求数 **/
    protected $successful = 0;
    /** @var int 失败请求数 **/
    protected $failed = 0;
    /** @var int 总等待时间 **/
    protected $totalWait = 0;

    /** @var int 正在建立中的连接数，用于防止冷启动连接风暴 */
    protected $establishingConnections = 0;

    /** @var callable|null 连接验证回调函数 */
    private $connectionValidationCallback;

    /** @var LoggerInterface|null */
    protected $logger;

    public function __construct(
        \Framework\Database\Interfaces\DatabaseInterface $driver,
        array $masterConfig,
        array $slavesConfig,
        ?\Framework\Database\Pool\LoadBalancer\LoadBalancerInterface $loadBalancer = null,
        int $minConnections = 2,
        int $maxConnections = 8,
        int $timeout = 5,
        int $threshold = 20,
        float $adjustThreshold = 0.75,
        ?callable $connectionValidationCallback = null,
        ?LoggerInterface $logger = null
    ) {
        if (empty($masterConfig)) {
            throw new \RuntimeException("Master config required");
        }
        if (!($driver instanceof \Framework\Database\Interfaces\ConnectionFactoryInterface)) {
            throw new \InvalidArgumentException('Driver must implement ConnectionFactoryInterface');
        }
        $this->driver = $driver;
        $this->factory = $driver;
        $this->masterConfig = $masterConfig;
        $this->loadBalancer = $loadBalancer;
        $this->minConnections = $minConnections;
        $this->maxConnections = $maxConnections;
        $this->timeout = $timeout;
        $this->adjustThreshold = $adjustThreshold;
        $this->threshold = $threshold;
        $this->logger = $logger;

        $this->connectionValidationCallback = $connectionValidationCallback ?? function ($conn) {
            try {
                return ($conn instanceof \PDO) ? $conn->getAttribute(\PDO::ATTR_SERVER_VERSION) : false;
            } catch (\Throwable $e) {
                return false;
            }
        };

        foreach ($slavesConfig as $idx => $cfg) {
            $shard = $cfg['shard'] ?? '';
            $pool = $this->getShardPool($shard);

            $node = new Node();
            $node->nodeId = $cfg['node_id'] ?? ('auto-node-' . $idx);
            $node->shard = $shard;
            $node->config = $cfg;
            $node->weight = (int)($cfg['weight'] ?? 1);
            $node->tags = (array)($cfg['tags'] ?? []);

            $pool->nodes[] = $node;
        }
    }

    /**
     * 获取或创建指定 shard 的 ShardPool
     */
    protected function getShardPool(string $shard): ShardPool
    {
        if (!isset($this->shardPools[$shard])) {
            $pool = new ShardPool();
            $pool->shard = $shard;
            $this->shardPools[$shard] = $pool;
        }
        return $this->shardPools[$shard];
    }

    /**
     * 在指定 shard 中按 nodeId 查找 Node
     * @return array
     */
    protected function findNode(string $shard, string $nodeId)
    {
        $pool = $this->getShardPool($shard);
        foreach ($pool->nodes as $node) {
            if ($node->nodeId === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    abstract public function getConnection(string $role = 'slave', string $shard = ''): \PDO;

    abstract public function releaseConnection(\PDO $connection): void;

    abstract public function preWarm(int $num, string $shard = ''): void;

    abstract public function closeAll(): void;

    abstract public function adjustPoolSize(string $shard = ''): void;

    abstract public function checkAndRecoverConnections(string $shard = ''): void;

    abstract protected function calcUtilization(string $shard = ''): float;

    abstract protected function getConnectionCount(string $shard): int;

    abstract public function evictCurrentConnection(string $shard = ''): void;

    abstract public function stats(): array;

    public function beginTransaction(string $shard = ''): bool
    {
        $this->checkCrossShardTransaction($shard);

        $level = $this->getTransactionLevel($shard);

        if ($level === 0) {
            $pdo = $this->getMaster($shard);
            $pdo->beginTransaction();
            $this->setShouldRollback(false, $shard);
        }

        $this->setTransactionLevel($level + 1, $shard);

        return true;
    }

    public function commit(string $shard = ''): bool
    {
        $level = $this->getTransactionLevel($shard);
        if ($level <= 0) {
            return false;
        }

        $level--;
        $this->setTransactionLevel($level, $shard);

        if ($level === 0) {
            $pdo = $this->getMaster($shard);
            try {
                if ($this->getShouldRollback($shard)) {
                    $pdo->rollBack();
                    $this->clearMaster($shard);
                    throw new \RuntimeException('Database transaction failed: marked as rollback only due to inner failure.');
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $this->clearMaster($shard);
                throw $e;
            }
            $this->clearMaster($shard);
        }

        return true;
    }

    public function rollback(string $shard = ''): bool
    {
        $level = $this->getTransactionLevel($shard);
        if ($level <= 0) {
            return false;
        }

        $this->setShouldRollback(true, $shard);
        $level--;
        $this->setTransactionLevel($level, $shard);

        if ($level === 0) {
            $pdo = $this->getMaster($shard);
            try {
                $pdo->rollBack();
            } catch (\Throwable $e) {
                $this->clearMaster($shard);
                throw $e;
            }
            $this->clearMaster($shard);
        }

        return true;
    }

    public function inTransaction(string $shard = ''): bool
    {
        return $this->getTransactionLevel($shard) > 0;
    }

    public function getTransactionLevel(string $shard = ''): int
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $levels = $ctx->dbTransactionLevels ?? [];
            return $levels[$shard] ?? 0;
        }
        $pool = $this->getShardPool($shard);
        $level = $pool->transactionLevel;
        if ($level > 0) {
            $master = $pool->master;
            if (!$master instanceof \PDO) {
                $pool->transactionLevel = 0;
                $pool->shouldRollback = false;
                return 0;
            }
            try {
                if (!$master->inTransaction()) {
                    $pool->transactionLevel = 0;
                    $pool->shouldRollback = false;
                    return 0;
                }
            } catch (\PDOException $e) {
                try {
                    $this->releaseConnection($master);
                } catch (\Throwable $releaseEx) {
                    // ignore
                }
                $pool->master = null;
                $pool->transactionLevel = 0;
                $pool->shouldRollback = false;
                return 0;
            }
        }
        return $level;
    }

    public function checkCrossShardTransaction(string $shard): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $levels = $ctx->dbTransactionLevels ?? [];
            foreach ($levels as $s => $level) {
                if ($s !== $shard && $level > 0) {
                    throw new \RuntimeException("Cross-shard query violation: Active transaction on '{$s}', attempted operation on '{$shard}'.");
                }
            }
        } else {
            foreach ($this->shardPools as $s => $pool) {
                if ($s !== $shard && $pool->transactionLevel > 0) {
                    throw new \RuntimeException("Cross-shard query violation: Active transaction on '{$s}', attempted operation on '{$shard}'.");
                }
            }
        }
    }

    protected function setTransactionLevel(int $level, string $shard = ''): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $levels = $ctx->dbTransactionLevels ?? [];
            $levels[$shard] = $level;
            $ctx->dbTransactionLevels = $levels;
        } else {
            $this->getShardPool($shard)->transactionLevel = $level;
        }
    }

    protected function getShouldRollback(string $shard = ''): bool
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $flags = $ctx->dbShouldRollback ?? [];
            return $flags[$shard] ?? false;
        }
        return $this->getShardPool($shard)->shouldRollback;
    }

    protected function setShouldRollback(bool $shouldRollback, string $shard = ''): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $flags = $ctx->dbShouldRollback ?? [];
            $flags[$shard] = $shouldRollback;
            $ctx->dbShouldRollback = $flags;
        } else {
            $this->getShardPool($shard)->shouldRollback = $shouldRollback;
        }
    }

    protected function clearMaster(string $shard = ''): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $masters = $ctx->dbMasters ?? [];
            if (isset($masters[$shard])) {
                $pdo = $masters[$shard];
                $this->releaseConnection($pdo);
                unset($masters[$shard]);
                $ctx->dbMasters = $masters;
            }
        } else {
            $pool = $this->getShardPool($shard);
            if ($pool->master) {
                $this->releaseConnection($pool->master);
                $pool->master = null;
            }
        }
    }

    /**
     * 检查主库和从库的健康状态
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function logError(string $message, array $context = []): void
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->error($message, $context);
        } else {
            error_log($message);
        }
    }

    public function checkHealth(string $shard = ''): array
    {
        $status = ['master' => false, 'slaves' => []];

        try {
            $pdo = $this->getMaster($shard);
            $this->validateConnection($pdo);
            $status['master'] = true;
        } catch (\Throwable $e) {
            $this->logError("Master DB health check failed for shard '{$shard}'", ['exception' => $e]);
            // 清理协程/FPM 上下文中可能已缓存的失效主连接
            $this->evictCurrentConnection($shard);
        } finally {
            $this->clearMaster($shard);
        }

        $shardPool = $this->getShardPool($shard);
        foreach ($shardPool->nodes as $node) {
            $testPdo = null;
            try {
                $testPdo = $this->factory->createConnectionFromConfig($node->config);
                $this->validateConnection($testPdo);
                $status['slaves'][$node->nodeId] = true;
            } catch (\Throwable $e) {
                $this->logError("Slave node '{$node->nodeId}' health check failed", ['exception' => $e]);
                $status['slaves'][$node->nodeId] = false;
            } finally {
                $testPdo = null;
            }
        }

        return $status;
    }

    public function monitor(): void
    {
        foreach ($this->shardPools as $shard => $pool) {
            $count = $this->getConnectionCount($shard);
            $util = $this->calcUtilization($shard);
            if ($count >= $this->threshold || $util > 0.95) {
                $this->logError("DB Pool high load: shard={$shard}, count={$count}, util={$util}");
            }
        }
    }

    public function setConnectionValidationCallback(callable $callback): void
    {
        $this->connectionValidationCallback = $callback;
    }

    protected function validateConnection(\PDO $connection): bool
    {
        $callback = $this->connectionValidationCallback;
        if ($callback && is_callable($callback)) {
            return (bool)call_user_func($callback, $connection);
        }
        try {
            $connection->exec('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取主连接
     * @param string $shard
     * @return \PDO
     */
    protected function getMaster(string $shard = ''): \PDO
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $masters = $ctx->dbMasters ?? [];
            if (!isset($masters[$shard])) {
                $masters[$shard] = $this->driver->createFreshConnection('master', $shard);
                $ctx->dbMasters = $masters;
            }
            return $masters[$shard];
        }

        $pool = $this->getShardPool($shard);
        if (!$pool->master) {
            $pool->master = $this->driver->createFreshConnection('master', $shard);
        }
        return $pool->master;
    }

    abstract public function reportError(\PDO $connection, \Throwable $e): void;

    abstract public function trackQueryStart(\PDO $connection): void;

    abstract public function trackQueryEnd(\PDO $connection): void;
}
