<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use Framework\Core\Container;

/**
 * ScriptRunner
 *
 * 负责执行 PHP 升级脚本，与插件/主题的 runActionScript 机制统一。
 * 脚本内可通过 $this->container 访问容器，与 plugins/well_forum/upgrade.php 同源。
 */
class ScriptRunner
{
    /** @var Container */
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * 执行 PHP 升级脚本
     *
     * @param string $scriptPath 脚本绝对路径
     * @throws \Throwable
     */
    public function run(string $scriptPath): void
    {
        if (!is_file($scriptPath)) {
            return;
        }

        include \App\Core\Compile::include($scriptPath);
    }
}
