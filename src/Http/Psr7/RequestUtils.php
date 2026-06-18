<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Psr7;

class RequestUtils
{
    protected static function getRequest(): ?\Framework\Http\Interfaces\ServerRequestInterface
    {
        return \Framework\Http\Psr7\RequestStack::getCurrent();
    }

    /**
     * @param array $data
     * @param null $key
     * @param null $defval
     * @param null $filterOptions
     */
    protected static function fetch($data, $key = null, $defval = null, bool $filter = true, $filterOptions = null)
    {
        if (null === $key) {
            $result = [];
            foreach ($data as $k => $v) {
                $result[$k] = self::transform($v, $defval, $filter, $filterOptions);
            }
            return $result;
        } else {
            if (!isset($data[$key])) return $defval;
            return self::transform($data[$key], $defval, $filter, $filterOptions);
        }
    }

    /**
     * transform 方法根据默认值类型转换、过滤原始数据。
     * 规则：
     * - defval 为 null => 返回原始数据（不转义）。
     * - defval 为数字（例如 0） => 强制将值转换为数字。
     * - defval 为浮点数（例如 0.0） => 强制将值转换为浮点数。
     * - defval 为bool（例如 false） => 强制将值转换为布尔值。
     * - defval 为 ''（空字符串） => 强制转换为字符串，并按安全过滤器过滤。
     * - defval 为 []（空数组） => 强制转换为字符串，并按安全过滤器过滤。
     * - defval 为 [0]（数组） => 强制转换为数字。
     *
     * @param mixed $value   获取的原始值
     * @param mixed $defval 默认值，决定目标类型
     * @param bool  $filter  是否执行安全过滤（true 表示过滤，false 表示返回原始值）
     * @param mixed $filterOptions 传递给 filter_var() 的选项
     * 邮箱验证 FILTER_VALIDATE_EMAIL
     * URL验证 FILTER_VALIDATE_URL
     * IP验证 FILTER_VALIDATE_IP
     * 应用 addslashes() FILTER_SANITIZE_MAGIC_QUOTES
     * @return mixed 处理后的结果
     */
    protected static function transform($value, $defval, $filter, $filterOptions)
    {
        // 若默认值为 null，则不进行过滤处理，直接返回原始值
        if (null === $defval) return $value;

        // 遍历数组中每个值
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::transform($v, $defval, $filter, $filterOptions);
            }
            return $result;
        }

        // 根据参数强制转换
        if (is_int($defval)) return (int)$value;

        if (is_bool($defval)) return is_bool($value) ? $value : (bool)$value;

        if (is_float($defval)) return (float)$value;

        // 字符串
        if (is_string($defval)) {
            $value = (string)$value;
            if ($filter) {
                $value = $filterOptions ? filter_var($value, $filterOptions) : htmlspecialchars($value, ENT_QUOTES);
            }
            return $value;
        }

        // 数组参数处理（默认 []、[''] 或 [0]）
        if (is_array($defval)) {
            // 根据默认数组第一个元素判断类型：数字型 [0] 表示数字数组，否则字符串数组
            $first = count($defval) > 0 ? reset($defval) : '';
            return self::transform($value, $first, $filter, $filterOptions);
        }
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function param($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if (!$request) return $defval;

        return self::fetch(array_merge(
            $request->getCookieParams() ?? [],
            $request->getQueryParams() ?? [],
            $request->getParsedBody() ?? [],
            $request->getAttributes() ?? []
        ), $key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     * @return array
     */
    public static function get($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if (!$request) return $defval;
        return self::fetch($request->getQueryParams(), $key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function post($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if (!$request) return $defval;
        return self::fetch($request->getParsedBody() ?? [], $key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function put($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if ($request->getMethod() !== 'PUT') return $defval;

        $raw = (string)$request->getBody();
        $data = [];
        $server = $request->getServerParams();
        if (isset($server['CONTENT_TYPE']) && stripos($server['CONTENT_TYPE'], 'application/json') !== false) {
            $data = json_decode($raw, true) ?? [];
        } else {
            parse_str($raw, $data);
        }

        if (empty($data)) return $defval;
        return self::fetch($data, $key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function input($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $value = self::post($key, null);
        if ($value === null) $value = self::get($key, null);
        return $value ?? self::put($key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function cookie($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if (!$request) return $defval;
        return self::fetch($request->getCookieParams(), $key, $defval, $filter, $filterOptions);
    }

    /**
     * @param null $key
     * @param string $defval
     * @param null $filterOptions
     */
    public static function server($key = null, $defval = '', bool $filter = true, $filterOptions = null)
    {
        $request = self::getRequest();
        if ($request) {
            return self::fetch($request->getServerParams(), $key, $defval, $filter, $filterOptions);
        }

        // 第二级回退：Swoole 协程上下文中的 server 数据
        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            if ($cid > 0) {
                $ctx = call_user_func([$coroClass, 'getContext']);
                $server = (array)($ctx['server'] ?? []);
                if (!empty($server)) {
                    return self::fetch($server, $key, $defval, $filter, $filterOptions);
                }
            }
        }

        // 第三级回退：非协程环境的全局变量
        if (isset($_SERVER)) {
            return self::fetch($_SERVER, $key, $defval, $filter, $filterOptions);
        }

        return $defval;
    }

    public static function all(): array
    {
        $request = self::getRequest();
        if (!$request) return [];
        return array_merge(
            $request->getCookieParams() ?? [],
            $request->getQueryParams() ?? [],
            $request->getParsedBody() ?? [],
            ['user' => $request->getAttributes()['user'] ?? '', '_route_meta' => $request->getAttributes()['_route_meta'] ?? []]
        );
    }

    public static function getOriginalRequest(): ?\Framework\Http\Interfaces\ServerRequestInterface
    {
        return self::getRequest();
    }
}
