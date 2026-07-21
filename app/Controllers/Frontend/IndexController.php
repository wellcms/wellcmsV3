<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\RequestUtils;

class IndexController extends \App\Controllers\Base\BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    // hook app_Controllers_Frontend_IndexController_start.php

    public function index(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user', []);
        $extra = [];

        // hook app_Controllers_Frontend_IndexController_index_start.php

        // 默认列表形式
        $index_default = 1;
        // hook app_Controllers_Frontend_IndexController_index_default.php
        /* if (1 == $index_default) {
        } */

        $navigation = $this->getNavigation();
        $stats = $this->container->get(\App\Services\Stats\RuntimeStats::class);
        $sessionService = $this->container->get(\App\Services\Auth\SessionService::class);

        // hook app_Controllers_Frontend_IndexController_index_before.php

        $page_link_string = '/'; // 当前页链接字符串
        $data = [
            'header' => [
                'sitename' => $this->appConfig['sitename'],
                'title' => $this->appConfig['title'],
                'keywords' => $this->appConfig['title'],
                'description' => $this->appConfig['title'],
            ],
            'extra' => $extra,
            'navigation' => $navigation,
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'runtime_data' => [
                'users'        => $stats->getTotal('users'),
                'users_online' => $sessionService->onlineCount(),
            ],
            'language' => [],
        ];

        // hook app_Controllers_Frontend_IndexController_index_end.php

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'index'];
        return $this->render($routeMeta['layout'], $data, false);
    }

    // hook app_Controllers_Frontend_IndexController_end.php
}
