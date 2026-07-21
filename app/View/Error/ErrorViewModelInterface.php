<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\View\Error;

/**
 * 错误视图模型接口。
 *
 * 本接口定义错误模板（500/404/403/message 等）对 $view 对象的显式契约：
 * 模板只能通过 get()/e()/raw() 访问数据，禁止直接访问容器或其他隐式变量。
 */
interface ErrorViewModelInterface
{
    /**
     * 按点号键取值，支持最多 10 级嵌套。
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * 安全输出，自动 htmlspecialchars。
     */
    public function e(string $key, string $default = ''): string;

    /**
     * 原始输出。
     *
     * @param mixed $default
     * @return mixed
     */
    public function raw(string $key, $default = '');

    /**
     * 注入运行时数据（如 message、code、debug 等）。
     * 运行时数据优先级高于 ViewModel 内置的默认配置值，
     * 确保 build() 构造的动态数据能正确传递给模板。
     *
     * @param array $runtimeData 键值对数组，不深度合并（仅覆盖一级键）。
     */
    public function setRuntimeData(array $runtimeData): void;
}
