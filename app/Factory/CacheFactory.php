<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Factory;

class CacheFactory extends \Framework\Cache\CacheManager
{
    public function __construct(\App\Services\System\CacheService $cacheService, array $cacheConfig)
    {
        parent::__construct($cacheConfig);
        // 若缓存配置列表为空，则使用mysql缓存。
        if (empty($cacheConfig['stores'])) $this->drivers['mysql'] = $cacheService;
    }
}
