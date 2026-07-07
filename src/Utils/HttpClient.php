<?php

declare(strict_types=1);

namespace Framework\Utils;

use Framework\Http\Interfaces\{ResponseInterface, StreamInterface};
use Framework\Http\Psr7\{Response, Stream};

class HttpClient
{
    public const MAX_CONNECTIONS    = 100;
    public const DEFAULT_TIMEOUT    = 30;
    public const CONNECTION_TIMEOUT = 10;

    // 支持扩展自定义HTTP方法（可配置）
    private /** @var array */
    static $supportedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    private const LOCK_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.http_client.lock';

    /** @var array */
    private static $pool = [];
    /** @var object|false|null  Semaphore handle (\SysvSemaphore in PHP 8+) */
    private static $sem = null;
    /** @var object|null  Swoole coroutine mutex */
    private static $coMutex = null;
    /** @var resource|null  File‑lock fallback handle */
    private static $fileLockHandle = null;

    /** @var array<string,mixed> */
    private $defaultOptions = [
        'method'          => 'GET',
        'timeout'         => self::DEFAULT_TIMEOUT,
        'followRedirects' => true,
        'maxRedirects'    => 5,
        'verifySSL'       => null,
        'caBundle'        => null,
        'throwOnError'    => true,
        'returnResponse'  => false,
        'headers'         => [],
        'cookie'          => null,
        'body'            => null,
        'acceptEncoding'  => true,
        // 支持HTTP2选项
        'http2'           => false,
        // 允许显式指定应用环境，解耦全局状态依赖
        'appEnv'          => null,
    ];

    /**
     * @param array<string,mixed> $opt
     * @return ResponseInterface|string
     * @throws HttpClientException
     */
    public function request(array $opt)
    {
        $opt = $this->mergeOptions($opt);
        $this->validateOptions($opt);

        if (empty($opt['url'])) throw new \InvalidArgumentException('URL is required');

        $url  = $this->sanitizeUrl($opt['url']);
        $resp = $this->hasCurl() ? $this->executeCurlRequest($url, $opt) : $this->executeStreamRequest($url, $opt);

        if (!$resp->getStatusCode() && $opt['throwOnError']) throw new HttpClientNetworkException('Network error');

        if ($resp->getStatusCode() >= 400 && $opt['throwOnError']) throw new HttpClientHttpException('HTTP ' . $resp->getStatusCode(), $resp->getStatusCode());

        return $opt['returnResponse'] ? $resp : (string)$resp->getBody();
    }

    /**
     * 并发 GET 请求（cURL only）。
     *
     * @param string[]            $urls
     * @param array<string,mixed> $options
     * @return ResponseInterface[]
     * @throws HttpClientException
     */
    public function multiGet(array $urls, array $options = []): array
    {
        if ($this->isSwooleCoroutine()) {
            $responses = [];
            foreach ($urls as $idx => $url) {
                $responses[$idx] = $this->request(['url' => $url, 'returnResponse' => true] + $options);
            }
            return $responses;
        }

        if (!$this->hasCurl()) throw new HttpClientNetworkException('cURL extension required for multiGet');

        $opt   = $this->mergeOptions($options + ['method' => 'GET']);
        $this->validateOptions($opt);
        $mh    = curl_multi_init();
        $hdr   = $handles = $errBuf = [];

        try {
            foreach ($urls as $idx => $raw) {
                $url       = $this->sanitizeUrl($raw);
                $hdr[$idx] = [];
                $ch        = $this->acquire();

                curl_setopt($ch, CURLOPT_HEADER, true); // 必须返回头部以便手动分离
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $this->configureCurlHandle($ch, $url, $opt);

                // 使用多行header支持同名header（如Set-Cookie）
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $header) use (&$hdr, $idx) {
                    $len    = strlen($header);
                    $header = trim($header);
                    if ($header === '' || strpos($header, ':') === false) return $len;
                    [$n, $v] = explode(':', $header, 2);
                    $n = $this->normalizeHeaderName($n);
                    $hdr[$idx][$n][] = trim($v);
                    return $len;
                });

                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            $running = null;
            do {
                $stat = curl_multi_exec($mh, $running);
                if ($stat !== CURLM_OK) break;
                if ($running) curl_multi_select($mh, 0.05);
            } while ($running);

            $responses = [];
            $partialErr = [];

            foreach ($handles as $i => $ch) {
                $response = curl_multi_getcontent($ch);

                // 一次性请求，避免二次curl_exec
                $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
                $err      = curl_error($ch);

                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headersRaw = substr($response, 0, $headerSize);
                $body       = substr($response, $headerSize);

                // 手动解析Content-Encoding
                preg_match('/Content-Encoding:\s*([^\r\n]+)/i', $headersRaw, $matches);
                $decEnc = strtolower($matches[1] ?? '');

                // 增强压缩解码，兼容zlib/deflate不同行为
                if ($decEnc === 'gzip' && function_exists('gzdecode')) {
                    $body = @gzdecode($body) ?: $body;
                } elseif ($decEnc === 'deflate') {
                    $body = @gzdecode($body) ?: @gzinflate($body) ?: $body;
                }
                // transfer-encoding: chunked
                if (stripos($headersRaw, 'Transfer-Encoding: chunked') !== false) {
                    $body = $this->decodeChunked($body);
                }

                if ($err) $partialErr[$i] = $err;
                $responses[$i] = new Response($status, $hdr[$i], $this->createStream($body));
            }

            ksort($responses);

            // 多路复用：部分成功/部分失败，返回全部，异常详情单独附加
            if ($partialErr && $opt['throwOnError']) {
                throw new HttpClientMultiException('multiGet partial errors', $partialErr, $responses);
            }

            return $responses;
        } finally {
            foreach ($handles as $ch) {
                if (is_resource($ch) || (class_exists('\CurlHandle') && $ch instanceof \CurlHandle)) {
                    @curl_multi_remove_handle($mh, $ch);
                    $this->release($ch);
                }
            }
            if (isset($mh)) curl_multi_close($mh);
        }
    }

    /** @param array<string,mixed> $opt */
    private function validateOptions(array $opt): void
    {
        // 可配置http方法
        if (!in_array(strtoupper($opt['method']), static::$supportedMethods, true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method');
        }
        foreach (['timeout', 'maxRedirects'] as $k) {
            if (!is_int($opt[$k]) && !ctype_digit((string) $opt[$k])) {
                throw new \InvalidArgumentException("$k must be int");
            }
        }
        foreach (['followRedirects', 'acceptEncoding', 'throwOnError', 'http2'] as $k) {
            if (!is_bool($opt[$k])) {
                throw new \InvalidArgumentException("$k must be bool");
            }
        }
        if ($opt['verifySSL'] !== null && !is_bool($opt['verifySSL'])) {
            throw new \InvalidArgumentException('verifySSL must be bool|null');
        }
        if ($opt['caBundle'] !== null && !is_string($opt['caBundle'])) {
            throw new \InvalidArgumentException('caBundle must be string|null');
        }
    }

    private function sanitizeUrl(string $raw): string
    {
        $raw = trim($raw);
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        if (function_exists('idn_to_ascii')) {
            $raw = preg_replace_callback(
                '#^(https?://)([^/]+)#i',
                static function ($m) {
                    $host = $m[2];
                    $ascii = @idn_to_ascii($host, IDNA_DEFAULT, \defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
                    return $ascii ? $m[1] . $ascii : $m[0];
                },
                $raw
            );
        }
        if (!filter_var($raw, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Malformed URL: ' . $raw);
        }
        return $raw;
    }

    /** 尝试加锁，成功返回 true */
    private function tryLock(): bool
    {
        $coroClass = "\\Swoole\\Coroutine";
        $mutexClass = "\\Swoole\\Coroutine\\Mutex";
        if (
            class_exists($coroClass) &&
            call_user_func([$coroClass, 'getCid']) > 0 &&
            class_exists($mutexClass)
        ) {
            if (!self::$coMutex) {
                self::$coMutex = new $mutexClass();
            }
            self::$coMutex->lock();
            return true;
        }
        if (function_exists('sem_get')) {
            if (self::$sem === null) {
                // 使用 @ 抑制未安装扩展时的警告
                self::$sem = @\sem_get(\ftok(__FILE__, 'c'));
            }
            if (self::$sem !== false && @\sem_acquire(self::$sem)) {
                return true;
            }
        }
        $h = @fopen(self::LOCK_FILE, 'c');
        if ($h && flock($h, LOCK_EX)) {
            self::$fileLockHandle = $h;
            return true;
        }
        return false;
    }

    private function unlock(): void
    {
        if (self::$coMutex) {
            self::$coMutex->unlock();
            return;
        }
        if (self::$sem) {
            @\sem_release(self::$sem);
        }
        if (self::$fileLockHandle) {
            flock(self::$fileLockHandle, LOCK_UN);
            fclose(self::$fileLockHandle);
            self::$fileLockHandle = null;
        }
    }

    /**
     * 判断当前是否处于 Swoole 协程环境
     */
    private function isSwooleCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /** Acquire curl handle */
    private function acquire()
    {
        // 协程环境下：不池化、不加锁，依赖 Swoole 底层连接复用
        if ($this->isSwooleCoroutine()) {
            return curl_init();
        }

        // 非协程环境（FPM / CLI 非协程）：保留原有连接池机制
        if ($this->tryLock()) {
            $h = self::$pool ? array_pop(self::$pool) : curl_init();
            $this->unlock();
            return $h;
        }
        return curl_init();
    }

    /** @param resource|\CurlHandle $ch */
    private function release($ch): void
    {
        // 协程环境下：直接关闭，不回收
        if ($this->isSwooleCoroutine()) {
            if (is_resource($ch) || (class_exists('\CurlHandle') && $ch instanceof \CurlHandle)) {
                curl_close($ch);
            }
            return;
        }

        // 非协程环境：保留原有池化逻辑
        if ($this->tryLock()) {
            if (count(self::$pool) < self::MAX_CONNECTIONS) {
                curl_reset($ch);
                self::$pool[] = $ch;
            } else {
                curl_close($ch);
            }
            $this->unlock();
        } else {
            curl_close($ch);
        }
    }

    private function executeCurlRequest(string $url, array $opt): ResponseInterface
    {
        $ch = $this->acquire();
        $headers = [];

        try {
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $header) use (&$headers) {
                $len = strlen($header);
                $header = trim($header);
                if ($header === '' || strpos($header, ':') === false) return $len;

                [$n, $v] = explode(':', $header, 2);
                $n = $this->normalizeHeaderName($n);
                $headers[$n][] = trim($v);
                return $len;
            });

            $this->configureCurlHandle($ch, $url, $opt);

            $response = curl_exec($ch);
            $err = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;

            if ($response === false) $response = '';
            if ($err && $opt['throwOnError']) throw new HttpClientNetworkException($err);

            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            $needsChunkDecode = (
                stripos($headersRaw, 'Transfer-Encoding: chunked') !== false &&
                preg_match('/^[0-9a-fA-F]+\r\n/', $body)
            );

            if ($needsChunkDecode) {
                $body = $this->decodeChunked($body);
            }

            preg_match('/Content-Encoding:\s*([^\r\n]+)/i', $headersRaw, $matches);
            $decEnc = strtolower($matches[1] ?? '');
            if ($decEnc === 'gzip' && function_exists('gzdecode')) {
                $body = @gzdecode($body) ?: $body;
            } elseif ($decEnc === 'deflate') {
                $body = @gzdecode($body) ?: @gzinflate($body) ?: $body;
            }

            return new Response($status, $headers, $this->createStream($body));
        } finally {
            $this->release($ch);
        }
    }

    /**
     * @param resource|\CurlHandle $ch
     * @param array<string,mixed>  $opt
     */
    private function configureCurlHandle($ch, string $url, array $opt): void
    {
        $headers = $this->buildHeaders($opt);

        // 支持http2可配置
        $version = CURL_HTTP_VERSION_1_1;
        if (!empty($opt['http2']) && \defined('CURL_HTTP_VERSION_2TLS')) {
            $curlVersion = curl_version()['version'] ?? '';
            if (version_compare($curlVersion, '7.58.0', '>=')) {
                $version = CURL_HTTP_VERSION_2TLS;
            }
        }
        $cfg = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => $opt['acceptEncoding'] ? '' : null,
            CURLOPT_HTTP_VERSION   => $version,
            CURLOPT_CONNECTTIMEOUT => self::CONNECTION_TIMEOUT,
            CURLOPT_TIMEOUT        => (int) $opt['timeout'],
            CURLOPT_FOLLOWLOCATION => $opt['followRedirects'],
            CURLOPT_MAXREDIRS      => $opt['maxRedirects'],
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifySSL($opt),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifySSL($opt) ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if (!empty($opt['proxy'])) {
            $cfg[CURLOPT_PROXY] = $opt['proxy'];
            $cfg[CURLOPT_HTTPPROXYTUNNEL] = true;
        }

        $ca = $this->detectCaBundle($opt['caBundle'], $opt['verifySSL']);

        if ($this->shouldVerifySSL($opt) && $ca) {
            $cfg[CURLOPT_CAINFO] = $ca;
        }

        if (strtoupper($opt['method']) !== 'GET') {
            [$body, $ctype]          = $this->buildBody($opt['body']);
            $cfg[CURLOPT_POSTFIELDS] = $body;
            $cfg[CURLOPT_CUSTOMREQUEST] = strtoupper($opt['method']);
            if ($ctype) {
                $headers[]               = "Content-Type: $ctype";
                $cfg[CURLOPT_HTTPHEADER] = $headers;
            }
            if ($cfg[CURLOPT_CUSTOMREQUEST] === 'POST') {
                $cfg[CURLOPT_POST] = true;
            }
        }

        $cfg = array_filter($cfg, static function ($v) {
            return $v !== null && $v !== [];
        });

        curl_setopt_array($ch, $cfg);
    }

    /** @param array<string,mixed> $opt */
    private function executeStreamRequest(string $url, array $opt): ResponseInterface
    {
        if ($this->isSwooleCoroutine()) {
            throw new HttpClientNetworkException('Swoole coroutine mode requires cURL extension or SWOOLE_HOOK_CURL');
        }

        [$body, $ctype] = $this->buildBody($opt['body']);
        $method  = strtoupper($opt['method']);
        $headers = $this->buildHeaders($opt);
        if ($ctype) $headers[] = "Content-Type: $ctype";
        if ($opt['acceptEncoding']) $headers[] = 'Accept-Encoding: gzip, deflate';

        // 重定向安全提醒
        $follow = $opt['followRedirects'] && $method === 'GET';
        if ($opt['followRedirects'] && $method !== 'GET') {
            error_log('[HttpClient] PHP stream wrapper followRedirects only effective for GET. Other methods will be converted to GET, which is a security risk!');
        }

        $ca = $this->detectCaBundle($opt['caBundle'], $opt['verifySSL']);

        $ssl = [
            'verify_peer'       => $this->shouldVerifySSL($opt),
            'verify_peer_name'  => $this->shouldVerifySSL($opt),
            'allow_self_signed' => !$this->shouldVerifySSL($opt),
        ];
        if ($this->shouldVerifySSL($opt) && $ca) {
            $ssl['cafile'] = $ca;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body,
                'timeout'         => (int) $opt['timeout'],
                'ignore_errors'   => true,
                'follow_location' => $follow,
                'max_redirects'   => $opt['maxRedirects'],
            ],
            'ssl'  => $ssl,
        ]);

        $phpErr = null;
        set_error_handler(static function ($errno, $errstr) use (&$phpErr) {
            $phpErr = $errstr;
        });
        $raw = file_get_contents($url, false, $ctx);
        restore_error_handler();

        $status     = 0;
        $respHdr    = [];
        $respLines  = $http_response_header ?? [];
        if (isset($respLines[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $respLines[0], $m)) {
            $status = (int) $m[1];
            foreach (array_slice($respLines, 1) as $h) {
                if (strpos($h, ':') === false) continue;
                [$n, $v] = array_map('trim', explode(':', $h, 2));
                $n = $this->normalizeHeaderName($n);
                $respHdr[$n][] = $v;
            }
        }
        if ($raw === false) {
            if ($opt['throwOnError']) {
                throw new HttpClientNetworkException($phpErr ?: 'stream context failed');
            }
            $raw = '';
        }

        // 压缩和chunked兼容增强
        if (!empty($respHdr['Transfer-Encoding'][0]) && stripos($respHdr['Transfer-Encoding'][0], 'chunked') !== false) {
            $raw = $this->decodeChunked((string) $raw);
            unset($respHdr['Transfer-Encoding']);
        }
        if (!empty($respHdr['Content-Encoding'][0])) {
            $enc = strtolower($respHdr['Content-Encoding'][0]);
            if ($enc === 'gzip' && function_exists('gzdecode')) {
                $raw = @gzdecode($raw) ?: $raw;
            } elseif ($enc === 'deflate') {
                $raw = @gzdecode($raw) ?: @gzinflate($raw) ?: $raw;
            }
            unset($respHdr['Content-Encoding']);
        }
        return new Response($status, $respHdr, $this->createStream((string) $raw));
    }

    private function decodeChunked(string $body): string
    {
        $pos = 0;
        $len = strlen($body);
        $out = '';

        while ($pos < $len) {
            $newline = strpos($body, "\r\n", $pos);
            if ($newline === false) {
                break;
            }

            $hex = trim(substr($body, $pos, $newline - $pos));
            if ($hex === '') {
                break;
            }

            $chunkLen = hexdec($hex);
            if ($chunkLen === 0) {
                // 最后一个 chunk 后可能有 Trailer headers，以 \r\n 结束
                // 直接跳到末尾的 \r\n 之后
                $pos = $newline + 2; // 跳过 0\r\n
                // 如果有 trailer，会再出现一个 \r\n 才结束
                if (strpos($body, "\r\n", $pos) === $pos) {
                    $pos += 2;
                }
                break;
            }

            $pos = $newline + 2;
            $out .= substr($body, $pos, $chunkLen);
            $pos += $chunkLen + 2; // 跳过 chunk 后的 \r\n
        }

        return $out;
    }

    /**
     * @return array
     * @param mixed $data
     */
    private function buildBody($data): array
    {
        if ($data === null) {
            return ['', null];
        }
        if ($data instanceof StreamInterface) {
            return [$data->__toString(), 'application/octet-stream'];
        }
        if (is_array($data)) {
            $hasFile = false;
            foreach ($data as $v) {
                if ($v instanceof \CURLFile || (is_array($v) && isset($v['file']))) {
                    $hasFile = true;
                    break;
                }
            }
            if ($hasFile) {
                return $this->buildMultipart($data);
            }
            return [http_build_query($data), 'application/x-www-form-urlencoded'];
        }
        if ($data instanceof \CURLFile) {
            return $this->buildMultipart(['file' => $data]);
        }

        $str  = (string) $data;
        $trim = ltrim($str);

        // 增强 JSON 检测：首字符匹配 + json_decode 二次校验
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = @json_decode($trim, true);
            if ($decoded !== null || $trim === 'null') {
                return [$str, 'application/json'];
            }
        }

        return [$str, 'text/plain'];
    }

    /** @param array<string,mixed> $fields */
    private function buildMultipart(array $fields): array
    {
        $boundary = '--------------------------' . microtime(true);
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";

            if ($value instanceof \CURLFile) {
                $filePath = $value->getFilename();
                $mimeType = $value->getMimeType() ?: 'application/octet-stream';
                $filename = $value->getPostFilename() ?: basename($filePath);

                $fileContent = @file_get_contents($filePath);
                if ($fileContent === false) {
                    throw new HttpClientNetworkException(
                        "Failed to read file for multipart upload: {$filePath}"
                    );
                }

                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$mimeType}\r\n\r\n";
                $body .= $fileContent . "\r\n";
            } elseif (is_array($value) && isset($value['file'])) {
                $filePath = $value['file'];
                $mimeType = $value['type'] ?? 'application/octet-stream';
                $filename = $value['filename'] ?? basename($filePath);

                $fileContent = @file_get_contents($filePath);
                if ($fileContent === false) {
                    throw new HttpClientNetworkException(
                        "Failed to read file for multipart upload: {$filePath}"
                    );
                }

                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$mimeType}\r\n\r\n";
                $body .= $fileContent . "\r\n";
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
            }
        }

        $body .= "--{$boundary}--\r\n";
        return [$body, "multipart/form-data; boundary={$boundary}"];
    }

    /** @param array<string,mixed> $opt */
    private function buildHeaders(array $opt): array
    {
        $headers = $opt['headers'] ?? [];
        if ($opt['cookie']) {
            $cookie = is_array($opt['cookie']) ? http_build_query($opt['cookie'], '', '; ') : (string) $opt['cookie'];
            $headers[] = "Cookie: $cookie";
        }
        if (!preg_grep('#^Accept:#i', $headers)) $headers[] = 'Accept: */*';

        // 同名header支持多行
        $map = [];
        foreach ($headers as $h) {
            if (strpos($h, ':') === false) continue;
            [$n, $v] = array_map('trim', explode(':', $h, 2));
            $key = strtolower($n);
            if (!isset($map[$key])) $map[$key] = [$n, []];
            $map[$key][1][] = $v;
        }
        $out = [];
        foreach ($map as [$name, $vals]) {
            foreach (array_unique($vals) as $v) {
                $out[] = $name . ': ' . $v;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $opt */
    private function shouldVerifySSL(array $opt): bool
    {
        if ($opt['verifySSL'] !== null) {
            return (bool) $opt['verifySSL'];
        }

        // 优先使用调用方显式传入的环境
        $env = $opt['appEnv'] ?? false;
        if ($env === false) {
            $env = getenv('APP_ENV');
            if ($env === false) {
                $env = 'dev';
            }
        }

        return $env === 'prod';
    }

    /** @var string|null Static cache for CA bundle path */
    private static $cachedCaBundle = null;

    /**
     * @param null $verify
     */
    private function detectCaBundle(?string $tip, $verify = null): ?string
    {
        if ($tip && file_exists($tip)) return $tip;

        if (self::$cachedCaBundle !== null) {
            return self::$cachedCaBundle === '' ? null : self::$cachedCaBundle;
        }

        $candidates = [
            (\defined('APP_PATH') ? \APP_PATH : \dirname(__DIR__, 2) . '/') . 'cacert.pem'
        ];

        if (function_exists('openssl_get_cert_locations')) {
            $locs = openssl_get_cert_locations();
            if (!empty($locs['default_cert_file'])) $candidates[] = $locs['default_cert_file'];
        }

        foreach ($candidates as $file) {
            if ($file && file_exists($file) && is_readable($file)) {
                self::$cachedCaBundle = $file;
                return $file;
            }
        }

        self::$cachedCaBundle = ''; // Mark as not found
        if ($verify) {
            error_log('[HttpClient] No valid CA bundle found, SSL will NOT be verified!');
        }
        return null;
    }

    private function createStream(string $content): StreamInterface
    {
        // 文件流确保安全关闭，由Stream类析构自动close，且不暴露原始资源给业务
        $res = fopen('php://temp', 'r+');
        if ($content !== '') {
            fwrite($res, $content);
            rewind($res);
        }
        return new Stream($res);
    }

    private function mergeOptions(array $opt): array
    {
        $merged = $this->defaultOptions;

        foreach ($opt as $key => $value) {
            // 对数组类型的配置项（headers、body）做递归合并，其余直接覆盖
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_merge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function hasCurl(): bool
    {
        return extension_loaded('curl');
    }

    private function normalizeHeaderName(string $name): string
    {
        return strtr(ucwords(strtolower(strtr($name, '-', ' '))), ' ', '-');
    }

    // 允许动态扩展HTTP方法
    public static function addSupportedMethod(string $method): void
    {
        $m = strtoupper($method);
        if (!in_array($m, static::$supportedMethods, true)) {
            static::$supportedMethods[] = $m;
        }
    }
}

// 定义更细粒度异常类型
class HttpClientException extends \RuntimeException {}
class HttpClientNetworkException extends HttpClientException {}
class HttpClientHttpException extends HttpClientException
{
    public function __construct($msg, $code)
    {
        parent::__construct($msg, $code);
    }
}
// multiGet专用异常
class HttpClientMultiException extends HttpClientException
{
    /** @var array */
    public $errors;
    /** @var array */
    public $responses;
    public function __construct($msg, $errArr, $respArr)
    {
        parent::__construct($msg);
        $this->errors = $errArr;
        $this->responses = $respArr;
    }

    public function getPartialErrors(): array
    {
        return $this->errors;
    }

    public function getPartialResponses(): array
    {
        return $this->responses;
    }
}

/*
 * --------------------------------------------------------------------------
 * 使用示例
 * --------------------------------------------------------------------------
 *
 * 基本 GET 请求（返回字符串）
 *   $client = new \Framework\Utils\HttpClient();
 *   $html = $client->request([
 *       'url' => 'https://example.com',
 *       'followRedirects' => true,
 *       'verifySSL' => false,
 *       'caBundle' => '/tmp/',
 *       'returnResponse' => false,
 *   ]);
 *
 * GET 请求（返回 ResponseInterface）
 *   $resp = $client->request([
 *       'url' => 'https://api.example.com/data',
 *       'returnResponse' => true,
 *       'headers' => ['Accept: application/json'],
 *       'followRedirects' => true,
 *       'verifySSL' => false,
 *       'caBundle' => '/tmp/',
 *   ]);
 *   $status = $resp->getStatusCode();
 *   $body   = (string) $resp->getBody();
 *
 * POST JSON
 *   $client->request([
 *       'url'    => 'https://api.example.com/posts',
 *       'method' => 'POST',
 *       'body'   => json_encode(['title' => 'Hello']),
 *       'headers' => [
 *           'Content-Type: application/json',
 *           'X-API-Key: secret',
 *       ],
 *       'followRedirects' => true,
 *       'verifySSL' => false,
 *       'caBundle' => '/tmp/',
 *       'returnResponse' => false,
 *   ]);
 *
 * 并发请求（cURL only）
 *   $responses = $client->multiGet([
 *       'https://api1.example.com',
 *       'https://api2.example.com',
 *   ], ['timeout' => 10]);
 *
 * 文件上传
 *   $client->request([
 *       'url'    => 'https://api.example.com/upload',
 *       'method' => 'POST',
 *       'body'   => ['file' => new \CURLFile('/path/to/photo.jpg')],
 *       'followRedirects' => true,
 *       'verifySSL' => false,
 *       'caBundle' => '/tmp/',
 *       'returnResponse' => false,
 *   ]);
 *
 * 异常处理
 *   try {
 *       $client->request([
 *           'url' => 'https://api.example.com',
 *           'followRedirects' => true,
 *           'verifySSL' => false,
 *           'caBundle' => '/tmp/',
 *           'returnResponse' => false,
 *       ]);
 *   } catch (\Framework\Utils\HttpClientNetworkException $e) {
 *       // 网络层错误（DNS、连接超时、SSL 失败等）
 *   } catch (\Framework\Utils\HttpClientHttpException $e) {
 *       // HTTP 状态码 ≥ 400
 *       $code = $e->getCode();
 *   } catch (\Framework\Utils\HttpClientMultiException $e) {
 *       // multiGet 部分失败
 *       $errors    = $e->getPartialErrors();
 *       $responses = $e->getPartialResponses();
 *   }
 * --------------------------------------------------------------------------
 */