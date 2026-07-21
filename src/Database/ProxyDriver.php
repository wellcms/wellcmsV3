<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database;

use PDO;
use PDOStatement;
use Throwable;
use Framework\Database\Driver\PdoDriver;
use Framework\Database\Pool\PoolInterface;

/**
 * ProxyDriver
 *
 * 继承自 PdoDriver，将底层的 connect() 重写为从连接池获取连接。
 * 上层代码依然调用 insert()/query()/transaction() 等方法，
 * 但所有的 PDO 实例都来自注入的 PoolInterface。
 */
class ProxyDriver extends PdoDriver
{
    /**
     * @var PoolInterface
     */
    public $pool;

    /** @var array FPM 环境下 PDO 对象级别的隔离级别映射 [spl_object_id => level] */
    protected $isolationLevels = [];

    /**
     * 构造时注入配置和连接池
     *
     * @param array          $appConfig 与 PdoDriver 相同的数据库配置
     * @param PoolInterface  $pool   实现了主从分离、读写分离的连接池
     */
    public function __construct(array $appConfig, PoolInterface $pool)
    {
        parent::__construct($appConfig);
        $this->pool = $pool;
    }

    /**
     * 重写父类 connect，所有 CRUD 调用所需的 PDO
     * 都由连接池提供，而非重新 new PDO(...)
     *
     * @param string $role  'master' 或 'slave'
     * @param string $shard 可选的分表标识
     * @return PDO
     */
    public function connect(string $role = 'master', string $shard = ''): PDO
    {
        // 如果当前处在一个其他分片的事务中，严禁切换到当前分片（跨分片隔离机制）
        if ($this->pool instanceof \Framework\Database\Pool\AbstractPool) {
            $this->pool->checkCrossShardTransaction($shard);
        }

        $pdo = $this->pool->getConnection($role, $shard);

        // 惰性校准隔离级别，防止连接池中的状态污染
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $targetLevel = $ctx->dbIsolationLevel ?? $this->defaultIsolationLevel;

            // 将隔离级别映射存入协程上下文，避免进程级 static 共享
            $isolationLevels = $ctx->dbIsolationLevels ?? [];
            $oid = \spl_object_id($pdo);
            $currentLevel = $isolationLevels[$oid] ?? null;

            if ($currentLevel !== $targetLevel) {
                // 如果在事务中且是 PostgreSQL，跳过校准以防 25P02 覆盖原始错误
                // 事务启动时 beginTransaction 已经处理过隔离级别了
                if (!$this->pool->inTransaction($shard)) {
                    try {
                        $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
                        $isolationLevels[$oid] = $targetLevel;
                        $ctx->dbIsolationLevels = $isolationLevels;
                    } catch (\Throwable $e) {
                        try {
                            $this->pool->releaseConnection($pdo);
                        } catch (\Throwable $releaseEx) {
                            // ignore
                        }
                        throw $e;
                    }
                }
            }
        } else {
            // FPM 环境下使用对象级映射进行隔离级别校准
            $targetLevel = $this->defaultIsolationLevel;
            $oid = \spl_object_id($pdo);
            $currentLevel = $this->isolationLevels[$oid] ?? null;

            if ($currentLevel !== $targetLevel) {
                if (!$this->pool->inTransaction($shard)) {
                    try {
                        $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
                        $this->isolationLevels[$oid] = $targetLevel;
                    } catch (\Throwable $e) {
                        try {
                            $this->pool->releaseConnection($pdo);
                        } catch (\Throwable $releaseEx) {
                            // ignore
                        }
                        unset($this->isolationLevels[$oid]);
                        throw $e;
                    }
                }
            }
        }

        return $pdo;
    }

    /**
     * 重写父类 release，将连接还给池子
     */
    protected function release(PDO $pdo): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $oid = \spl_object_id($pdo);
            $isolationLevels = $ctx->dbIsolationLevels ?? [];
            unset($isolationLevels[$oid]);
            $ctx->dbIsolationLevels = $isolationLevels;
        } else {
            unset($this->isolationLevels[\spl_object_id($pdo)]);
        }
        $this->pool->releaseConnection($pdo);
    }

    public function beginTransaction(string $shard = ''): bool
    {
        $result = $this->pool->beginTransaction($shard);
        $this->setTransactionState(true);

        // 仅在事务层级从 0 -> 1 时设置隔离级别，避免嵌套事务重复执行 SET SESSION
        $ctx = self::coroContext();
        if ($ctx !== null && $this->pool->getTransactionLevel($shard) === 1) {
            $targetLevel = $ctx->dbIsolationLevel ?? $this->defaultIsolationLevel;
            $pdo = $this->pool->getConnection('master', $shard);
            $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
            $isolationLevels = $ctx->dbIsolationLevels ?? [];
            $isolationLevels[\spl_object_id($pdo)] = $targetLevel;
            $ctx->dbIsolationLevels = $isolationLevels;
        } elseif ($ctx === null && $this->pool->getTransactionLevel($shard) === 1) {
            $targetLevel = $this->defaultIsolationLevel;
            $pdo = $this->pool->getConnection('master', $shard);
            $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
            $this->isolationLevels[\spl_object_id($pdo)] = $targetLevel;
        }

        return $result;
    }

    public function commit(string $shard = ''): bool
    {
        $result = $this->pool->commit($shard);
        $this->setTransactionState(false);
        return $result;
    }

    public function rollback(string $shard = ''): bool
    {
        $result = $this->pool->rollback($shard);
        $this->setTransactionState(false);
        return $result;
    }

    public function inTransaction(string $shard = ''): bool
    {
        return $this->pool->inTransaction($shard);
    }

    /**
     * 重写隔离级别设置，确保 FPM 连接池模式下后续 connect() 能感知到变更并重新校准
     */
    public function setIsolationLevel(string $level, string $shard = ''): void
    {
        parent::setIsolationLevel($level, $shard);
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $ctx->dbIsolationLevel = $level;
        }
        $this->defaultIsolationLevel = $level;
    }

    /**
     * 重写执行逻辑，挂载性能追踪与故障上报钩子
     */
    protected function logAndExec(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $this->pool->trackQueryStart($pdo);
        try {
            return parent::logAndExec($pdo, $sql, $params);
        } catch (Throwable $e) {
            $this->pool->reportError($pdo, $e);
            throw $e;
        } finally {
            $this->pool->trackQueryEnd($pdo);
        }
    }
}
