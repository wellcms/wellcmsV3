<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Services\Market;

use App\Services\System\KeyValueService;
use Framework\Utils\Validator;

/**
 * 应用商店降级服务
 * 职责：服务端不可用时提供本地缓存降级
 * 遵循 Skill #16: 非主键聚合统计冗余化
 */
class MarketFallbackService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var KeyValueService */
    protected $kv;
    /** @var MarketCircuitBreaker */
    protected $circuitBreaker;
    /** @var int 本地缓存有效期（秒） */
    protected $cacheTtl;
    /** @var \Framework\Core\Container */
    protected $container;

    public function __construct(
        \Framework\Core\Container $container,
        KeyValueService $kv,
        MarketCircuitBreaker $circuitBreaker,
        int $cacheTtl = 86400
    ) {
        // hook app_Services_Market_MarketFallbackService_construct_start.php
        $this->container = $container;
        $this->kv = $kv;
        $this->circuitBreaker = $circuitBreaker;
        $this->cacheTtl = $cacheTtl;
        // hook app_Services_Market_MarketFallbackService_construct_end.php
    }

    /**
     * 获取插件列表（带降级）
     *
     * @return array
     */
    public function getPluginList(): array
    {
        // 优先检查本地缓存
        $cached = $this->getCachedData('plugin_list');

        // 熔断器关闭，尝试从服务端获取
        if ($this->circuitBreaker->canRequest()) {
            try {
                $marketClient = $this->container->get(MarketClient::class);
                $result = $marketClient->request('list.html');
                $data = json_decode($result, true);

                if (isset($data['status']) && $data['status'] === 'success') {
                    // 缓存到本地
                    $this->cacheData('plugin_list', $data['data'] ?? []);
                    return $data['data'] ?? [];
                }

            } catch (\Exception $e) {
                // HTTP 异常已由 MarketClient 记录熔断状态
            }
        }

        // 降级到本地缓存
        return $cached ?? [];
    }

    /**
     * 获取插件详情（带降级）
     *
     * @param string $dir 插件目录
     * @return array
     */
    public function getPluginDetail(string $dir): array
    {
        Validator::make(['dir' => $dir], ['dir' => 'required|string']);

        $cacheKey = "plugin_detail:{$dir}";
        $cached = $this->getCachedData($cacheKey);

        if ($this->circuitBreaker->canRequest()) {
            try {
                $marketClient = $this->container->get(MarketClient::class);
                $result = $marketClient->request('detail.html', ['dir' => $dir]);
                $data = json_decode($result, true);

                if (isset($data['status']) && $data['status'] === 'success') {
                    $this->cacheData($cacheKey, $data['data'] ?? []);
                    return $data['data'] ?? [];
                }

            } catch (\Exception $e) {
                // HTTP 异常已由 MarketClient 记录熔断状态
            }
        }

        return $cached ?? [];
    }

    /**
     * 批量查询扩展（带降级）
     *
     * @param array $items 每个元素包含: dir, type(plugin/theme), version
     * @return array
     */
    public function queryExtensions(array $items): array
    {
        Validator::make(['items' => $items], ['items' => 'required|array']);

        if (empty($items)) {
            return [];
        }

        $result = [];
        $dirs = [];

        // 收集需要查询的目录
        foreach ($items as $item) {
            $dir = is_string($item) ? $item : ($item['dir'] ?? '');
            if ($dir) {
                $dirs[] = $dir;
            }
        }

        // 尝试从服务端获取
        if ($this->circuitBreaker->canRequest()) {
            try {
                $marketClient = $this->container->get(MarketClient::class);
                $serverData = $marketClient->queryExtensions($items);

                if (!empty($serverData)) {
                    // 缓存每个插件信息
                    // FIX: 服务端 query() 返回 has_bought，不是 is_bought
                    foreach ($serverData as $dir => $data) {
                        $this->cacheData("plugin_detail:{$dir}", $data);
                        $this->cacheData("plugin_bought:{$dir}", $data['has_bought'] ?? false);
                        $this->addToIndex($dir);
                    }

                    return $serverData;
                }

            } catch (\Exception $e) {
                // HTTP 异常已由 MarketClient 记录熔断状态
            }
        }

        // 降级到本地缓存
        foreach ($dirs as $dir) {
            $cachedDetail = $this->getCachedData("plugin_detail:{$dir}");
            if ($cachedDetail) {
                $cachedDetail['is_bought'] = $this->getCachedData("plugin_bought:{$dir}") ?? false;
                $cachedDetail['from_cache'] = true;
                $result[$dir] = $cachedDetail;
            }
        }

        return $result;
    }

    /**
     * 检查购买状态（带降级）
     *
     * @param string $dir 插件目录
     * @return bool 默认未购买（安全策略）
     */
    public function checkBought(string $dir): bool
    {
        Validator::make(['dir' => $dir], ['dir' => 'required|string']);

        $cacheKey = "plugin_bought:{$dir}";
        $cached = $this->getCachedData($cacheKey);

        if ($this->circuitBreaker->canRequest()) {
            try {
                $marketClient = $this->container->get(MarketClient::class);
                // FIX: bought.html 期望参数 dirs（数组），不是 dir（字符串）
                $result = $marketClient->request('bought.html', ['dirs' => [$dir]]);
                $data = json_decode($result, true);

                // FIX: 服务端 bought() 返回 {status: success, data: {dir: {dir, bought, payment_id}}}
                // 原代码错误地检查 $data['code']，该字段不存在
                if (isset($data['status']) && $data['status'] === 'success') {
                    $resultData = $data['data'] ?? [];
                    $item = $resultData[$dir] ?? [];
                    $bought = !empty($item['payment_id']);
                    $this->cacheData($cacheKey, $bought);
                    return $bought;
                }

            } catch (\Exception $e) {
                // HTTP 异常已由 MarketClient 记录熔断状态
            }
        }

        // 降级：默认未购买（安全策略）
        return $cached ?? false;
    }

    /**
     * 获取服务端版本信息（带降级）
     *
     * @return array
     */
    public function getServerVersion(): array
    {
        $cached = $this->getCachedData('server_version');

        if ($this->circuitBreaker->canRequest()) {
            try {
                $marketClient = $this->container->get(MarketClient::class);
                $version = $marketClient->negotiateVersion();

                $data = [
                    'version' => $version,
                    'updated_at' => time(),
                ];

                $this->cacheData('server_version', $data);
                return $data;

            } catch (\Exception $e) {
                // HTTP 异常已由 MarketClient 记录熔断状态
            }
        }

        return $cached ?? ['version' => 'v2', 'updated_at' => 0];
    }

    /**
     * 清除所有缓存
     *
     * @return void
     */
    public function clearCache(): void
    {
        // 删除列表缓存
        $this->kv->delete('fallback:plugin_list');
        $this->kv->delete('fallback:server_version');

        // 获取缓存索引并清理
        $indexKey = 'fallback:index:plugin_keys';
        $keys = $this->kv->get($indexKey) ?? [];
        foreach ($keys as $dir) {
            $this->kv->delete("fallback:plugin_detail:{$dir}");
            $this->kv->delete("fallback:plugin_bought:{$dir}");
        }
        $this->kv->delete($indexKey);
    }

    /**
     * 添加到缓存索引
     *
     * @param string $dir
     * @return void
     */
    protected function addToIndex(string $dir): void
    {
        $indexKey = 'fallback:index:plugin_keys';
        $keys = $this->kv->get($indexKey) ?? [];
        if (!in_array($dir, $keys, true)) {
            $keys[] = $dir;
            $this->kv->set($indexKey, $keys);
        }
    }

    /**
     * 获取降级状态
     *
     * @return array
     */
    public function getStatus(): array
    {
        return [
            'circuit_breaker' => $this->circuitBreaker->getStats(),
            'is_degraded' => $this->circuitBreaker->isDegraded(),
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /**
     * 缓存数据
     *
     * @param string $key
     * @param mixed $data
     * @return void
     */
    protected function cacheData(string $key, $data): void
    {
        $this->kv->set("fallback:{$key}", $data);
    }

    /**
     * 获取缓存数据
     *
     * @param string $key
     * @return mixed
     */
    protected function getCachedData(string $key)
    {
        return $this->kv->get("fallback:{$key}");
    }
}
