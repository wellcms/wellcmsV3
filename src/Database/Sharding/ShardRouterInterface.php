<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Sharding;

/**
 * 分片路由接口
 */
interface ShardRouterInterface
{
    /**
     * 根据表名和分片键值计算目标 shard
     *
     * @param string $table 表名
     * @param mixed $shardValue 分片键值
     * @return string shard 标识
     */
    public function route(string $table, $shardValue): string;

    /**
     * 获取某表对应的所有 shard 标识（用于广播查询）
     *
     * @param string $table
     * @return string[]
     */
    public function allShards(string $table): array;
}
