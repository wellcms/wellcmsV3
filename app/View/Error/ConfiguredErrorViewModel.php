<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\View\Error;

/**
 * 容器就绪时使用的配置驱动错误视图模型。
 *
 * 采用双层数据设计：
 * - 配置层：从 appConfig 读取的默认视图数据（如 website.current.view、data.redirect.url）；
 * - 运行时层：build() 方法传入的错误数据（如 message、code、debug、timestamp）。
 *
 * 运行时数据优先级高于配置默认值，但仅覆盖一级键，避免意外覆盖整个分支。
 */
class ConfiguredErrorViewModel implements ErrorViewModelInterface
{
    /** @var array 配置默认值骨架 */
    private $defaultData;

    /** @var array 运行时数据 */
    private $runtimeData = [];

    /** @var array */
    private $cache = [];

    /**
     * @param array $defaultData 来自 appConfig 的默认视图数据。
     * @param array $runtimeData 来自 ErrorResponseBuilder::build() 的运行时数据。
     */
    public function __construct(array $defaultData, array $runtimeData = [])
    {
        $this->defaultData = $defaultData;
        $this->runtimeData = $runtimeData;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $cacheKey = 'get-' . $key;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // 一级键快速路径：运行时数据优先
        if (false === strpos($key, '.')) {
            if (array_key_exists($key, $this->runtimeData)) {
                $this->cache[$cacheKey] = $this->runtimeData[$key];
                return $this->cache[$cacheKey];
            }
            $this->cache[$cacheKey] = array_key_exists($key, $this->defaultData) ? $this->defaultData[$key] : $default;
            return $this->cache[$cacheKey];
        }

        // 多级键：先尝试运行时数据，再回退默认数据
        $result = $this->resolveNestedKey($this->runtimeData, $key);
        if ($result !== null) {
            $this->cache[$cacheKey] = $result;
            return $result;
        }

        $result = $this->resolveNestedKey($this->defaultData, $key);
        $this->cache[$cacheKey] = ($result !== null) ? $result : $default;
        return $this->cache[$cacheKey];
    }

    public function e(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function raw(string $key, $default = '')
    {
        return $this->get($key, $default);
    }

    public function setRuntimeData(array $runtimeData): void
    {
        $this->runtimeData = $runtimeData;
        $this->cache = [];
    }

    /**
     * 在指定数据池中解析点号分隔的多级键。
     *
     * @return mixed|null 返回 null 表示未找到（与合法 null 值不做区分，够用即可）。
     */
    private function resolveNestedKey(array $data, string $key)
    {
        $keys = explode('.', $key);
        if (count($keys) > 10) {
            return null;
        }

        $current = $data;
        foreach ($keys as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
