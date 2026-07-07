<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Admin;

class UploadController extends \App\Controllers\Base\BaseController
{
    use \App\Traits\Admin\AdminTrait;

    // hook app_Controllers_Admin_UploadController_start.php

    /**
     * 临时文件清理页面
     * GET /admin/TempCleanup
     */
    public function index(
        \Framework\Http\Interfaces\ServerRequestInterface $request
    ): \Framework\Http\Interfaces\ResponseInterface
    {
        $currentUser = $request->getAttribute('user', []);
        $menu = $this->getAdminMenu();
        $page_link_string = 'admin/TempCleanup';

        $data = [
            'header' => [
                'title' => $this->language->get('temp_cleanup_menu'),
                'keywords' => $this->language->get('temp_cleanup_menu'),
                'description' => $this->language->get('temp_cleanup_menu'),
            ],
            'menu' => $menu,
            'menu_fixed' => ['parent' => 'other', 'child' => 'temp_cleanup'],
            'csrf_token' => $this->getCsrfToken($currentUser['salt'] ?? ''),
            'page_link' => $this->urlGenerator->url($page_link_string),
            'page_link_string' => $page_link_string,
            'action' => $this->urlGenerator->url('admin/TempCleanup'),
            'language' => [
                'temp_cleanup_menu' => $this->language->get('temp_cleanup_menu'),
                'temp_cleanup_run' => $this->language->get('temp_cleanup_run'),
                'temp_cleanup_confirm' => $this->language->get('temp_cleanup_confirm'),
                'temp_cleanup_description' => $this->language->get('temp_cleanup_description'),
            ],
        ];

        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'temp_cleanup'];
        return $this->render($routeMeta['layout'], $data, true);
    }

    /**
     * 手动触发临时文件清理
     * POST /admin/TempCleanup
     */
    public function tempCleanup(
        \Framework\Http\Interfaces\ServerRequestInterface $request
    ): \Framework\Http\Interfaces\ResponseInterface
    {
        $result = $this->container->get(\App\Services\Storage\TempCleanupService::class)->clean();
        return $this->responseFormatter->jsonResponseFormat(array(
            'status' => 'success',
            'code' => 0,
            'message' => $this->language->get('temp_cleanup_success'),
            'data' => $result,
            'timestamp' => time(),
        ));
    }

    // hook app_Controllers_Admin_UploadController_end.php
}