<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\View\Error;

/**
 * 容器未就绪时使用的安全默认错误视图模型。
 *
 * 设计约束：
 * - 不依赖容器、不依赖配置、不依赖任何外部服务；
 * - 支持点号键解析，与现有错误模板契约保持一致；
 * - 内部缓存避免同一次渲染中重复解析；
 * - 接受运行时数据注入，作为启动期失败时的尽力而为补充。
 */
class DefaultErrorViewModel implements ErrorViewModelInterface
{
    /** @var array */
    private $data;

    /** @var array */
    private $cache = [];

    /**
     * @param array $runtimeData 运行时数据，仅覆盖一级键。
     */
    public function __construct(array $runtimeData = [])
    {
        $this->data = [
            'website' => [
                'current' => [
                    'view' => '/views/',
                ],
                'static_version' => '',
            ],
            'data' => [
                'redirect' => [
                    'url' => '/',
                ],
            ],
        ];

        // 运行时数据仅覆盖一级键，避免意外覆盖整个 data 或 website 分支
        foreach ($runtimeData as $key => $value) {
            $this->data[$key] = $value;
        }
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

        // 一级键快速路径
        if (false === strpos($key, '.')) {
            $this->cache[$cacheKey] = array_key_exists($key, $this->data) ? $this->data[$key] : $default;
            return $this->cache[$cacheKey];
        }

        // 多级键完整解析
        $result = $this->resolveNestedKey($key);
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
        foreach ($runtimeData as $key => $value) {
            $this->data[$key] = $value;
        }
        // 运行时数据变更后清空缓存
        $this->cache = [];
    }

    /**
     * 递归解析点号分隔的多级键。
     * 安全阈值：最多 10 层，防止异常深度遍历。
     *
     * @return mixed|null
     */
    private function resolveNestedKey(string $key)
    {
        $cacheKey = 'nested-' . $key;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $keys = explode('.', $key);
        if (count($keys) > 10) {
            $this->cache[$cacheKey] = null;
            return null;
        }

        $current = $this->data;
        foreach ($keys as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $this->cache[$cacheKey] = null;
                return null;
            }
            $current = $current[$segment];
        }

        $this->cache[$cacheKey] = $current;
        return $current;
    }
}
