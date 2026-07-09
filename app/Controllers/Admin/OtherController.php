<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

use Framework\Cache\Interfaces\CacheInterface;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;
use Framework\Utils\DirectoryHelper;
use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;

class OtherController extends BaseController
{
    use AdminTrait;

    // hook src_admin_controller_AdminOther_start.php

    public function clearCache(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook src_admin_controller_AdminOther_clearCache_start.php

        $csrfToken = $this->getCsrfToken($user['salt']);
        // 获取导航栏信息
        $menu = $this->getAdminMenu();

        // hook src_admin_controller_AdminOther_clearCache_after.php

        $page_link_string = 'admin/other/clearCache'; // 当前页链接字符串
        $data = [
            'header' => [
                'title' => $this->language->get('clear_cache'),
                'keywords' => $this->language->get('clear_cache'),
                'description' => $this->language->get('clear_cache'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'other', 'child' => 'clear'],
            'extra' => $extra,
            'csrf_token' => $csrfToken,
            'page_link' => $this->urlGenerator->url($page_link_string, $extra),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('admin/other/postClearCache', $extra),
            'language' => [
                'clear_cache' => $this->language->get('clear_cache'),
                'memory_cache' => $this->language->get('memory_cache'),
                'temporary_directory' => $this->language->get('temporary_directory'),
                'submit' => $this->language->get('submit'),
            ]
        ];

        // hook src_admin_controller_AdminOther_clearCache_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'other_clear'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    public function postClearCache(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $cache = $this->container->get(CacheInterface::class);

        $memoryCache = RequestUtils::param('memory', 0);
        $temporaryDirectory = RequestUtils::param('directory', 0);

        // hook src_admin_controller_AdminOther_postCache_start.php

        if ($memoryCache) {
            $cache->clear();

            // 如果配置了 Redis 缓存，同时清理 scheduler 残留的 dedupeKey（通过连接池）
            try {
                $cacheConfig = $this->container->get('cacheConfig');
                if (!empty($cacheConfig['stores']['redis']) && $cache instanceof \Framework\Cache\CacheManager) {
                    $redis = $cache->original('redis');
                    if ($redis instanceof \Framework\Cache\Drivers\RedisCache) {
                        $keys = $redis->keys('scheduler:dedupe:*');
                        if (!empty($keys)) {
                            $redis->del($keys);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // scheduler 不可用时静默跳过
            }

            // 深度清理：重置容器中所有有状态服务的内存数据 (解决 StatefulTrait 污染)
            $instances = $this->container->getInstances();
            foreach ($instances as $name => $instance) {
                // 排除核心多语言服务，防止清理后翻译失效导致显示原始代码
                if ($instance === $this->language || strpos($name, 'LocaleManager') !== false) {
                    continue;
                }

                if (is_object($instance) && method_exists($instance, 'clearStates')) {
                    $instance->clearStates();
                }
            }
        }

        if ($temporaryDirectory) {
            DirectoryHelper::rmdirRecursive($this->appConfig['tmp_path']);

            DirectoryHelper::rmdirRecursive(APP_PATH . 'public/static/');
            // 同步清理 OPcache
            if (function_exists('opcache_get_status') && opcache_get_status(false)) {
                opcache_reset();
            }
        }

        // hook src_admin_controller_AdminOther_postCache_end.php

        return $this->successMessage($this->language->get('clear_success'), 0, $this->urlGenerator->url('admin/other/clearCache'), 2);
    }

    // hook src_admin_controller_AdminOther_end.php
}
