<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

final class IpHelper
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var array<string,mixed> FPM mode state storage */
    private static $fpmState = [];

    /**
     * Internal state resolver to handle both Swoole and FPM correctly
     */
    private static function stateHandle()
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Override getState for FPM static persistence
     * @param null $default
     * @return array
     */
    protected function getState(string $name, $default = null)
    {
        if (defined('SWOOLE_VERSION') && \extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            if (call_user_func([$coroClass, 'getCid']) > 0) {
                $ctx = call_user_func([$coroClass, 'getContext']);
                $key = static::class . ':' . $name;
                return $ctx[$key] ?? $default;
            }
        }
        return self::$fpmState[$name] ?? $default;
    }

    /**
     * Override setState for FPM static persistence
     */
    protected function setState(string $name, $value): void
    {
        if (defined('SWOOLE_VERSION') && \extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            if (call_user_func([$coroClass, 'getCid']) > 0) {
                $ctx = call_user_func([$coroClass, 'getContext']);
                $key = static::class . ':' . $name;
                $ctx[$key] = $value;
                return;
            }
        }
        self::$fpmState[$name] = $value;
    }

    /**
     * 设置当前请求上下文的 IP（快照注入）
     */
    /**
     * 统一解析 server 参数来源：
     * 1. 调用方显式传入
     * 2. Swoole 协程上下文中的 server 数据
     * 3. 全局 $_SERVER（非协程环境的最终回退）
     */
    private static function resolveServer(array $server = []): array
    {
        if (!empty($server)) {
            return $server;
        }

        if (\extension_loaded('swoole')) {
            $coroClass = "\\Swoole\\Coroutine";
            $cid = (int)call_user_func([$coroClass, 'getCid']);
            if ($cid > 0) {
                $ctx = call_user_func([$coroClass, 'getContext']);
                $ctxServer = (array)($ctx['server'] ?? []);
                if (!empty($ctxServer)) {
                    return $ctxServer;
                }
            }
        }

        return $_SERVER ?? [];
    }

    public static function setContextIp(string $ip): void
    {
        self::stateHandle()->setState('contextIp', $ip);
    }

    public static function setContextUa(string $ua): void
    {
        self::stateHandle()->setState('contextUa', $ua);
    }

    public static function userAgent(array $server = []): string
    {
        $ua = self::stateHandle()->getState('contextUa');
        if ($ua) return (string)$ua;

        $source = self::resolveServer($server);
        return (string)($source['HTTP_USER_AGENT'] ?? '');
    }

    public static function setContextHost(string $host): void
    {
        self::stateHandle()->setState('contextHost', $host);
    }

    public static function host(array $server = []): string
    {
        $host = self::stateHandle()->getState('contextHost');
        if ($host) return (string)$host;

        $source = self::resolveServer($server);
        return (string)($source['HTTP_HOST'] ?? '');
    }

    public static function setContextLang(string $lang): void
    {
        self::stateHandle()->setState('contextLang', $lang);
    }

    public static function acceptLanguage(array $server = []): string
    {
        $lang = self::stateHandle()->getState('contextLang');
        if ($lang) return (string)$lang;

        $source = self::resolveServer($server);
        return (string)($source['HTTP_ACCEPT_LANGUAGE'] ?? '');
    }

    public static function setContextScheme(string $scheme): void
    {
        self::stateHandle()->setState('contextScheme', $scheme);
    }

    public static function scheme(array $server = []): string
    {
        $scheme = self::stateHandle()->getState('contextScheme');
        if ($scheme) return (string)$scheme;

        $source = self::resolveServer($server);
        $https = $source['HTTPS'] ?? 'off';
        $proto = $source['HTTP_X_FORWARDED_PROTO'] ?? '';
        return ((isset($source['SERVER_PORT']) && 443 == $source['SERVER_PORT']) || 'https' === strtolower($proto) || (strtolower((string)$https) !== 'off')) ? 'https' : 'http';
    }

    public static function setContextPort(int $port): void
    {
        self::stateHandle()->setState('contextPort', $port);
    }

    public static function port(array $server = []): int
    {
        $port = self::stateHandle()->getState('contextPort');
        if (null !== $port) return (int)$port;

        $source = self::resolveServer($server);
        return (int)($source['SERVER_PORT'] ?? 80);
    }

    public static function setContextScript(string $script): void
    {
        self::stateHandle()->setState('contextScript', $script);
    }

    public static function scriptName(array $server = []): string
    {
        $script = self::stateHandle()->getState('contextScript');
        if ($script) return (string)$script;

        $source = self::resolveServer($server);
        return (string)($source['PHP_SELF'] ?? $source['SCRIPT_NAME'] ?? '');
    }

    public static function ip(array $server = []): string
    {
        $contextIp = self::stateHandle()->getState('contextIp');
        if ($contextIp && self::validIp((string)$contextIp)) {
            return (string)$contextIp;
        }

        // 2. 尝试从传入的 server 数组或 resolveServer() 解析
        $source = self::resolveServer($server);

        foreach (
            [
                'HTTP_CDN_SRC_IP',
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_CLIENT_IP',
                'HTTP_X_CLIENT_IP',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'REMOTE_ADDR'
            ] as $h
        ) {
            $ip = $source[$h] ?? '';
            if (empty($ip)) continue;

            $ip = trim(explode(',', $ip)[0]);
            if (self::validIp($ip)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    // 仅验证 IPv4 地址
    //filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    // 仅验证 IPv6 地址
    //filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    // 排除私有 IP 范围（如 192.168.x.x）
    //filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
    // 排除保留 IP 范围（如 127.x.x.x）
    //filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE);

    // 验证 IP 地址格式是否合法​​
    public static function validIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 检查是否为私有地址范围
     */
    public static function isPrivate(string $ip): bool
    {
        if (!self::validIp($ip)) return false;

        $addr = inet_pton($ip);
        if ($addr === false) return false;

        // IPv4
        if (strlen($addr) === 4) {
            $long = unpack('N', $addr)[1];
            return ($long & 0xFF000000) === 0x0A000000   // 10.0.0.0/8
                || ($long & 0xFFF00000) === 0xAC100000  // 172.16.0.0/12
                || ($long & 0xFFFF0000) === 0xC0A80000  // 192.168.0.0/16
                || ($long & 0xFF000000) === 0x7F000000; // 127.0.0.0/8
        }

        // IPv6
        $firstByte = ord($addr[0]);
        return ($firstByte & 0xFE) === 0xFC  // fc00::/7 (ULA)
            || ($firstByte === 0xFE && (ord($addr[1]) & 0xC0) === 0x80) // fe80::/10 (Link-Local)
            || $ip === '::1';
    }

    /**
     * 规范化 IP 地址 - 同时返回字符串格式和二进制格式
     * @param string $ip IP 地址（支持字符串或二进制格式）
     * @return array ['ip' => string, 'ip_bin' => string]
     */
    public static function normalizeIp(string $ip): array
    {
        $result = self::detect($ip);
        if (!in_array($result, ['IPV4_BINARY', 'IPV6_BINARY'])) {
            $ip2bin = self::ip2bin($ip);
        } else {
            $ip2bin = $ip;
            $ip = self::bin2ip($ip2bin);
        }

        return [$ip, $ip2bin];
    }


    // 示例：查询 192.168.1.0/24 范围内的帖子
    // $start_ip = '192.168.1.0';
    // $end_ip = '192.168.1.255';

    // $start_bin = IpHelper::ip2bin($start_ip);
    // $end_bin = IpHelper::ip2bin($end_ip);

    // 使用查询构造器的范围查询
    // $list = $db->query('forum_thread', [
    //     'create_ip' => ['>=' => $start_bin, '<=' => $end_bin]
    // ]);
    /**
     * 将IP地址转换为原始二进制字节流 (IPv4: 4字节, IPv6: 16字节)
     * 高性能替代原位字符串方案
     */
    public static function ip2bin(string $ip): string
    {
        return (string)@inet_pton($ip);
    }

    /**
     * 还原原始二进制字节流为 IP (解耦原有位字符串命名逻辑)
     */
    public static function bin2ip($bin): string
    {
        if (empty($bin)) return '';

        if (is_resource($bin)) {
            $bin = stream_get_contents($bin);
        }

        if (!is_string($bin)) return '0.0.0.0';

        // If it's already a valid IP string, return it directly to ensure idempotency
        if (filter_var($bin, FILTER_VALIDATE_IP)) {
            return $bin;
        }

        // Handle PostgreSQL hex format: \x7f000001
        if (strpos($bin, '\\x') === 0) {
            $bin = substr($bin, 2);
            $bin = hex2bin($bin);
        } elseif (preg_match('/^[a-f0-9]{8,32}$/i', $bin) && strlen($bin) % 2 === 0) {
            // Potential hex string from some DB configurations
            $bin = hex2bin($bin);
        }

        $ip = (string)@inet_ntop($bin);
        return $ip ?: '0.0.0.0';
    }

    /**
     * 高性能解析 CIDR 格式网段 (如 192.168.1.0/24)
     * 使用二进制掩码运算而非位字符串拼接
     */
    public static function parseCidrRange(string $cidr): array
    {
        if (strpos($cidr, '/') === false) {
            $bin = self::ip2bin($cidr);
            return ['start' => $bin, 'end' => $bin];
        }

        [$ip, $mask] = explode('/', $cidr);
        $mask = (int)$mask;
        $addr = inet_pton($ip);
        if ($addr === false) return ['start' => '', 'end' => ''];

        $len = strlen($addr);
        $totalBits = $len * 8;
        if ($mask < 0 || $mask > $totalBits) return ['start' => '', 'end' => ''];

        $start = $addr;
        $end = $addr;

        for ($i = 0; $i < $len; $i++) {
            $bitOffset = $i * 8;
            if ($mask <= $bitOffset) {
                // 完全在掩码外，起始全0，结尾全1
                $start[$i] = chr(0);
                $end[$i] = chr(255);
            } elseif ($mask < $bitOffset + 8) {
                // 掩码落在此字节内
                $relativeMask = $mask - $bitOffset;
                $byteMask = (255 << (8 - $relativeMask)) & 255;
                $start[$i] = chr(ord($addr[$i]) & $byteMask);
                $end[$i] = chr(ord($addr[$i]) | (~$byteMask & 255));
            }
            // mask >= bitOffset + 8: 掩码覆盖整个字节，保持不变
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * 检测 IP 格式
     * @param string $ip
     * @return string
     */
    public static function detect($ip)
    {
        // 1. 优先判断是否为正常的字符串 IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'IPV4_STRING';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'IPV6_STRING';
        }

        // 2. 判断是否为二进制 IP
        // 注意：二进制数据可能包含不可见字符，必须使用 strlen 检查字节长度
        $len = strlen($ip);

        // IPv4 二进制固定为 4 字节
        if ($len === 4) {
            // 尝试转回字符串验证有效性 (抑制潜在的警告)
            $converted = @inet_ntop($ip);
            if ($converted !== false) {
                return 'IPV4_BINARY';
            }
        }

        // IPv6 二进制固定为 16 字节
        if ($len === 16) {
            $converted = @inet_ntop($ip);
            if ($converted !== false) {
                return 'IPV6_BINARY';
            }
        }

        return 'UNKNOWN';
    }

    // IPv4转整型
    public static function longIp(string $ip = ''): string
    {
        $ip = $ip ? $ip : self::ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            return (string)($long & 0xFFFFFFFF); // 无符号处理
        }
        return self::ip2longV6($ip);
    }

    // 整型转IP
    public static function safeLong2ip(int $longIp): string
    {
        if (!is_numeric($longIp)) {
            return htmlspecialchars((string)$longIp, ENT_QUOTES);
        }

        if (strlen((string)$longIp) > 10 || $longIp > 0xFFFFFFFF) {
            return self::long2ipV6((string)$longIp);
        }
        return long2ip((int)$longIp);
    }

    // IPv6转整型
    private static function ip2longV6(string $ip): string
    {
        self::checkBigNumberExtension();

        $ipBin = inet_pton($ip);
        $hex = bin2hex($ipBin);

        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }

        $dec = '0';
        foreach (str_split($hex, 8) as $part) { // 分块处理
            $dec = bcmul($dec, '4294967296', 0); // 2^32
            $dec = bcadd($dec, (string)hexdec($part), 0);
        }
        return $dec;
    }

    // 整型转IPv6
    private static function long2ipV6(string $dec): string
    {
        self::checkBigNumberExtension();

        if (extension_loaded('gmp')) {
            $hex = str_pad(gmp_strval(gmp_init($dec, 10), 16), 32, '0', STR_PAD_LEFT);
        } else {
            $hex = '';
            while (bccomp($dec, '0') > 0) {
                $hex = dechex((int)bcmod($dec, '65536')) . $hex; // 16位分段
                $dec = bcdiv($dec, '65536', 0);
            }
            $hex = str_pad($hex, 32, '0', STR_PAD_LEFT);
        }

        $ip = implode(':', str_split($hex, 4));
        return inet_ntop(inet_pton($ip));
    }

    //$ip = '192.168.1.100';
    //echo maskIp($ip); // 输出: 192.168.*.*
    public static function maskIp(string $ip): string
    {
        return preg_replace('/(\d+\.\d+)\.\d+\.\d+/', '$1.*.*', $ip) ?: $ip;
    }

    public static function maskIpv6(string $ipv6): string
    {
        // 归一化后遮盖中间段
        $addr = @inet_pton($ipv6);
        if ($addr === false) return $ipv6;
        $ipv6 = inet_ntop($addr);

        return preg_replace('/((?:[a-f0-9]{1,4}:){2})(?:[a-f0-9]{1,4}:){2}(.*)/i', '$1****:$2', $ipv6) ?: $ipv6;
    }

    // 严格验证
    //echo getIpVersion('192.168.1.1'); // 输出: IPv4
    //echo getIpVersion('2001:0db8:85a3::8a2e:0370:7334'); // 输出: IPv6
    //echo getIpVersion('invalid_ip'); // 输出: Invalid IP
    /**
     * @return array
     */
    public static function getIpVersion(string $ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'IPv4';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'IPv6';
        } else {
            return 'Invalid IP';
        }
    }

    private static function checkBigNumberExtension(): void
    {
        if (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
            throw new \RuntimeException('IPV6 conversion requires GMP or BCMath extension');
        }
    }
}
