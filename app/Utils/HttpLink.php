<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Utils;

use Framework\Utils\IpHelper;

class HttpLink
{
    // return http://domain.com OR https://domain.com
    public static function httpUrl(): string
    {
        return IpHelper::scheme() . '://' . IpHelper::host();
    }

    // 获取 http://xxx.com/path/
    public static function httpUrlPath( int $urlRewriteOn= 0): string
    {
        $host = IpHelper::host();
        $php_self = IpHelper::scriptName();
        
        $len = strrpos($php_self, '//');
        false === $len and $len = strrpos($php_self, '/');
        $path = substr($php_self, 0, $len);
        $urlRewriteOn <= 2 and $path = $path . '/';
        $http = IpHelper::scheme();
        
        return $http . '://' . $host . $path;
    }
}
