<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Routing;

/**
 * 生成URL
 */
class CoreUrlGenerator implements \Framework\Http\Routing\UrlGeneratorInterface
{
    /**
     * Summary of uri
     * @var \Framework\Http\Interfaces\UriInterface
     */
    protected $uri;
    /**
     * Summary of urlRewriteMode
     * @var int
     */
    protected $urlRewriteMode;

    public function __construct(\Framework\Http\Interfaces\UriInterface $uri, int $urlRewriteMode)
    {
        $this->uri = $uri;
        $this->urlRewriteMode = (int)$urlRewriteMode;
    }

    public function url(string $route, array $query = []): string
    {

        $path = $this->buildPath($route);

        if (!empty($query)) {
            $sep = false === strpos($path, '?') ? '?' : '&';
            $path .= $sep . http_build_query($query);
        }

        return $path;
    }

    // 'user/home/1' 不支持二级目录
    private function buildPath(string $route): string
    {
        $route && $route = trim($route, '/');
        if (empty($route)) return '/';

        // hook src_Http_Rsr7_Url_buildPath_start.php
        switch ($this->urlRewriteMode) {
            case 0: // ?user-home-1.html
                return '?' . strtr($route, '/', '-') . '.html';
            case 1: // user-home-1.html
                return strtr($route, '/', '-') . '.html';
            case 2: // /user/home/1.html
                return '/' . $route . '.html';
            case 3: // /user/home/1
                return '/' . $route;
            default: // ?user-home-1.html
                return '?' . strtr($route, '/', '-') . '.html';
        }
        // hook src_Http_Rsr7_Url_buildPath_end.php
    }
}

/*
require APP_PATH . 'src/Http/Psr7/Uri.php';
require APP_PATH . 'src/Http/Routing/CoreUrlGenerator.php';
use Framework\Http\Psr7\Uri;
use Framework\Http\Routing\CoreUrlGenerator;

$uri = new Uri('http://www.wellcms.com');
$generator = new CoreUrlGenerator($uri, 2);
echo $generator->url('user/home', ['userId' => 1]);
// http://www.wellcms.com/user/home.html?userId=1
*/
