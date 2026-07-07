<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Frontend;

class ErrorController extends \App\Controllers\Base\BaseController
{
    public function error403(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface
    {
        $data = $this->messageDataFarmat('error', "We can't find that page.", 403, '/', 0);
        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => '403'];
        return $this->render($routeMeta['layout'], $data);
    }

    public function error404(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface
    {
        $data = $this->messageDataFarmat('error', "We can't find that page.", 404, '/', 0);
        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => '404'];
        return $this->render($routeMeta['layout'], $data);
    }

    public function error500(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface
    {
        $data = $this->messageDataFarmat('error', "Internal Server Error.", 500, '/', 0);
        $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => '500'];
        return $this->render($routeMeta['layout'], $data);
    }
}
