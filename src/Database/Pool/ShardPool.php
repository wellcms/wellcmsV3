<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

final class ShardPool
{
    /** @var string */
    public $shard;

    /** @var \PDO|null */
    public $master = null;

    /** @var int */
    public $transactionLevel = 0;

    /** @var bool */
    public $shouldRollback = false;

    /** @var Node[] */
    public $nodes = [];

    /**
     * @var array 连接元数据列表，格式同旧版 $this->slaves：
     * [
     *   ['connection'=>PDO, 'last_used'=>int, 'in_use'=>bool, 'node_id'=>string, 'weight'=>int]
     * ]
     */
    public $connections = [];

    /**
     * @var array 活跃连接注册表 [spl_object_id => ['node_id' => ..., 'created_at' => ...]]
     */
    public $connectionRegistry = [];
}
