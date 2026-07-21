<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

final class Node
{
    /** @var string */
    public $nodeId;

    /** @var string */
    public $shard;

    /** @var array 原始连接参数（含 driver） */
    public $config;

    /** @var int */
    public $weight = 1;

    /** @var array */
    public $tags = [];

    // 运行时状态
    /** @var int */
    public $errorCount = 0;

    /** @var int */
    public $fusedUntil = 0;

    /** @var int */
    public $activeConnections = 0;

    /** @var int */
    public $totalConnections = 0;

    /** @var int */
    public $activeQueries = 0;
}
