<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Scheduler;

/**
 * HTTP 回调实现：如果 task->callbackUrl 非空，则把结果 POST 到该 URL
 */
class HttpResultCallback implements \Framework\Scheduler\Interfaces\ResultCallbackInterface
{
    /**
     * Summary of allowedDomains
     * @var array
     */
    protected $allowedDomains = ['api.example.com'];
    /**
     * Summary of allowHttp
     * @var bool
     */
    protected $allowHttp = false;
    /**
     * Summary of timeout
     * @var int
     */
    protected $timeout = 5;
    /**
     * Summary of maxRedirects
     * @var int
     */
    protected $maxRedirects = 3;

    public function notify(\Framework\Scheduler\Task $task, bool $success, float $elapsed, string $errorMsg): void
    {
        $url = $task->callbackUrl ?? null;
        if (!$url) return;

        try {
            $this->validateUrl($url);
        } catch (\Exception $e) {
            $this->logSecurityEvent($task->id, "URL validation failed: " . $e->getMessage());
            return;
        }

        $payload = [
            'taskId'     => $task->id,
            'status'     => $success ? 'success' : 'failed',
            'elapsed'    => round($elapsed, 3),
            'errorMsg'   => $this->sanitizeError($errorMsg), // 错误脱敏
            'retryCount' => $task->retryCount,
            'timestamp'  => time(),
            'signature'  => $this->generateSignature($task->id, $success, $elapsed)
        ];

        $method = strtoupper($task->callbackMethod) === 'GET' ? 'GET' : 'POST';
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        // 带重试机制的HTTP请求
        $this->sendWithRetry($url, $method, $jsonPayload, $task->id);
    }

    private function sendWithRetry(string $url, string $method, string $payload, string $taskId): void
    {
        $retry = 0;
        $maxRetries = 3;

        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';
        $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);

        while ($retry <= $maxRetries) {
            try {
                // 1. 预解析并验证所有 IP，防止 DNS 重绑定攻击
                $ips = gethostbynamel($host);
                if ($ips === false) {
                    throw new \Framework\Exception\BusinessException("Cannot resolve host: {$host}");
                }

                $targetIp = '';
                foreach ($ips as $ip) {
                    if (!$this->isPrivateIp($ip)) {
                        $targetIp = $ip;
                        break;
                    }
                }

                if (!$targetIp) {
                    throw new \Framework\Exception\BusinessException("No valid public IP found for host: {$host}");
                }

                $ch = curl_init();

                $options = [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => $this->timeout,
                    // 安全增强：禁用自动重定向，防止通过 302 跳转探测内网 (SSRF)
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    // 强制 TLS 1.2+ 
                    CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_CUSTOMREQUEST  => $method,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'User-Agent: Scheduler/1.0',
                        'X-Task-ID: ' . $taskId
                    ],
                    // DNS Pinning: 将目标 Host 锁定到预验证的 IP 上
                    CURLOPT_RESOLVE        => ["{$host}:{$port}:{$targetIp}"],
                    CURLOPT_DNS_CACHE_TIMEOUT => 0,
                    CURLOPT_FRESH_CONNECT  => true,
                ];

                // 资源限制：防止大响应体耗尽内存
                $options[CURLOPT_BUFFERSIZE] = 8192;
                $options[CURLOPT_NOPROGRESS] = false;
                $options[CURLOPT_PROGRESSFUNCTION] = function ($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                    return ($downloaded > 1048576) ? 1 : 0; // 限制 1MB
                };

                if ($method === 'POST') {
                    $options[CURLOPT_POSTFIELDS] = $payload;
                } elseif ($method === 'GET') {
                    $queryParams = http_build_query(json_decode($payload, true));
                    $options[CURLOPT_URL] .= (strpos($url, '?') === false ? '?' : '&') . $queryParams;
                }

                curl_setopt_array($ch, $options);

                $response = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $connectInfoIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);

                unset($ch);

                // 二次检查连接 IP（双重保险）
                if ($connectInfoIp && $this->isPrivateIp($connectInfoIp)) {
                    throw new \Framework\Exception\BusinessException("Abort: Connected to private IP: {$connectInfoIp}");
                }

                if ($status >= 200 && $status < 300) {
                    return;
                }

                $this->logSecurityEvent($taskId, "Callback failed status: {$status}, error: {$error}");
            } catch (\Exception $e) {
                $this->logSecurityEvent($taskId, "Callback exception: " . $e->getMessage());
            }

            // 指数退避重试逻辑
            $baseDelayUs = 100000;  // 100ms
            $maxBackoffUs = 32000000; // 32s
            $finalDelay = (int)min($maxBackoffUs, (1 << $retry) * $baseDelayUs);
            $jitter = random_int(0, (int)($finalDelay * 0.25));
            usleep($finalDelay + $jitter);
            $retry++;
        }

        $this->logSecurityEvent($taskId, "Callback failed after {$maxRetries} retries");
    }

    protected function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException("Invalid callback URL: {$url}");
        }

        // 强制HTTPS检查（除非明确允许HTTP）
        /* if (!$this->allowHttp && $parts['scheme'] !== 'https') {
            throw new \InvalidArgumentException("HTTPS required for callback: {$url}");
        } */

        // 防止 SSRF 私网访问
        $ip = gethostbyname($parts['host']);
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.|169\.254\.)/', $ip)) {
            throw new \Framework\Exception\BusinessException("阻止回调至内网IP: {$ip}");
        }

        /* // 检查是否在允许域名内
        $allowed = false;
        foreach ($this->allowedDomains as $domain) {
            if (str_ends_with($parts['host'], $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \InvalidArgumentException("Domain not in whitelist: {$parts['host']}");
        } */

        // DNS解析和IP检查
        $this->validateHostIp($parts['host']);
    }

    protected function validateHostIp(string $host): void
    {
        // 解析IPv4和IPv6
        $ips = gethostbynamel($host);
        if ($ips === false) {
            throw new \InvalidArgumentException("Cannot resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new \InvalidArgumentException("Callback to private network blocked: {$ip}");
            }
        }
    }

    protected function isPrivateIp(string $ip): bool
    {
        // IPv4私有地址段
        $privateV4 = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '::1/128',
            'fc00::/7',
            'fe80::/10'
        ];

        foreach ($privateV4 as $cidr) {
            if ($this->ipInRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInRange(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        // 简化IPv6检查
        return $ip === $subnet;
    }

    /**
     * 获取回调签名密钥（静态缓存）
     */
    protected function getSecret(): string
    {
        static $secret;
        if ($secret !== null) {
            return $secret;
        }

        $secret = $_ENV['SCHEDULER_SECRET'] ?? '';
        if (empty($secret)) {
            $cfgFile = defined('APP_PATH') ? APP_PATH . 'config/App.php' : dirname(__DIR__, 2) . '/config/App.php';
            if (file_exists($cfgFile)) {
                $cfg = include $cfgFile;
                $secret = $cfg['auth_key'] ?? '';
            }
        }
        if (empty($secret)) {
            $secret = 'fallback-secret-must-be-changed';
        }
        return $secret;
    }

    protected function generateSignature(string $taskId, bool $success, float $elapsed): string
    {
        $data = $taskId . ($success ? '1' : '0') . $elapsed;
        return hash_hmac('sha256', $data, $this->getSecret());
    }

    /**
     * 错误信息脱敏处理，防止 SQL 片段或系统路径泄露
     */
    private function sanitizeError(string $error): string
    {
        $patterns = [
            '/(SQLSTATE|Syntax error|Column not found|Unknown column|Table.*doesn\'t exist)/i',
            '/(\/[a-z0-9_\-\.\/]+php|[A-Z]:\\\\[a-z0-9_\-\.\\]+php)/i',
        ];
        $clean = preg_replace($patterns, '[SECURE_INFO]', $error);
        return substr($clean, 0, 1000);
    }

    protected function logSecurityEvent(string $taskId, string $message): void
    {
        // 记录到安全日志
        $securityLogger = new \Framework\Scheduler\Logger();
        $securityLogger->log("SECURITY [{$taskId}] {$message}", 'WARNING');
    }
}
