<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Sharding;

/**
 * 一致性哈希分片路由器
 */
class ConsistentHashRouter implements ShardRouterInterface
{
    /** @var int 虚拟节点数 */
    protected $replicas = 150;

    /** @var array 排序后的哈希环 [hash => shard] */
    protected $ring = [];

    /** @var array 排序后的哈希键列表，用于二分查找 */
    protected $ringKeys = [];

    /** @var array 已注册的 shard 列表 */
    protected $shards = [];

    /** @var bool 是否已排序 */
    protected $sorted = false;

    /**
     * @param array $shards shard 标识列表
     * @param int $replicas 每个物理节点的虚拟节点数
     */
    public function __construct(array $shards = [], int $replicas = 150)
    {
        $this->replicas = max(1, $replicas);
        foreach ($shards as $shard) {
            $this->addShard((string)$shard);
        }
    }

    /**
     * 添加一个 shard 到哈希环
     */
    public function addShard(string $shard): void
    {
        if (isset($this->shards[$shard])) {
            return;
        }
        $this->shards[$shard] = true;
        for ($i = 0; $i < $this->replicas; $i++) {
            $hash = $this->hash("{$shard}:{$i}");
            $this->ring[$hash] = $shard;
        }
        $this->sorted = false;
    }

    /**
     * 二分查找定位最近的虚拟节点
     */
    protected function binarySearch(array $keys, int $hash): int
    {
        $low = 0;
        $high = count($keys) - 1;
        while ($low <= $high) {
            $mid = (int)(($low + $high) / 2);
            if ($keys[$mid] >= $hash) {
                $high = $mid - 1;
            } else {
                $low = $mid + 1;
            }
        }
        return $low;
    }

    /**
     * 从哈希环移除一个 shard
     */
    public function removeShard(string $shard): void
    {
        if (!isset($this->shards[$shard])) {
            return;
        }
        unset($this->shards[$shard]);
        for ($i = 0; $i < $this->replicas; $i++) {
            $hash = $this->hash("{$shard}:{$i}");
            unset($this->ring[$hash]);
        }
        $this->sorted = false;
    }

    /**
     * 计算路由
     */
    public function route(string $table, $shardValue): string
    {
        if (empty($this->ring)) {
            throw new \RuntimeException('ConsistentHashRouter: no shards available');
        }
        if (!$this->sorted) {
            ksort($this->ring, SORT_NUMERIC);
            $this->ringKeys = array_keys($this->ring);
            $this->sorted = true;
        }

        $hash = $this->hash((string)$shardValue);
        $idx = $this->binarySearch($this->ringKeys, $hash);
        if ($idx < count($this->ringKeys)) {
            return $this->ring[$this->ringKeys[$idx]];
        }
        // 环尾回绕到第一个节点
        return reset($this->ring);
    }

    /**
     * 返回所有 shard
     */
    public function allShards(string $table): array
    {
        return array_keys($this->shards);
    }

    /**
     * 32 位一致性哈希（兼容 PHP 7.2+）
     */
    protected function hash(string $key): int
    {
        // crc32 在 PHP 7.2+ 返回无符号整数，需转换为有符号 32 位整数保证排序一致性
        $crc = crc32($key);
        if ($crc & 0x80000000) {
            $crc = -((~$crc & 0xFFFFFFFF) + 1);
        }
        return $crc;
    }
}
