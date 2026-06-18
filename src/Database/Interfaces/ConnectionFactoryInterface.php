<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Interfaces;

use PDO;

interface ConnectionFactoryInterface
{
    /**
     * 按原始节点配置创建物理连接，绕过任何缓存与随机选择。
     *
     * @param array $nodeConfig 节点原始配置（传值，方法内部可安全补全 driver）
     */
    public function createConnectionFromConfig(array $nodeConfig): PDO;
}
