<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Routing;

interface UrlGeneratorInterface
{
    /**
     * 生成 URL
     *
     * @param string $route   路由标识，如 'user-home-1'
     * @param array  $params  查询参数
     * @return string
     */
    public function url(string $route, array $params = []);
}
