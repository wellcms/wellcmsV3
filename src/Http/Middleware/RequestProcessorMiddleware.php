<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Http\Middleware;

use Framework\Exception\Http\UploadSecurityException;
use Framework\Http\Interfaces\{ServerRequestInterface, UploadedFileInterface};
use Framework\Utils\IpHelper;

final class RequestProcessorMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;
    /** @var int */
    private $urlRewriteMode;
    /** @var array */
    private $uploadConfig;

    public function __construct(int $urlRewriteMode, array $uploadConfig, \Framework\Logger\LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->uploadConfig = $uploadConfig;
        $this->urlRewriteMode = $urlRewriteMode;
    }

    public function process(ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // 阶段1：处理请求主体
        $request = $this->processBody($request);

        // 阶段2：处理上传文件
        $request = $this->processUploadedFiles($request);

        // 阶段3：处理GET查询参数
        $request = $this->processQueryParams($request);

        // 阶段4：处理Cookie参数
        $request = $this->processCookieParams($request);

        // 阶段5：处理Route参数
        $request = $this->processRouteParams($request);

        // 阶段6：输入过滤 取值的时候再过滤，确保入库前保持源数据。
        //$request = $this->processInputFilter($request);

        // 设置全局上下文 IP/UA/Host/Lang/Scheme/Port 快照件
        $uri = $request->getUri();
        IpHelper::setContextIp(IpHelper::ip($request->getServerParams()));
        IpHelper::setContextUa($request->getServerParams()['HTTP_USER_AGENT'] ?? '');
        IpHelper::setContextHost($request->getServerParams()['HTTP_HOST'] ?? '');
        IpHelper::setContextLang($request->getServerParams()['HTTP_ACCEPT_LANGUAGE'] ?? '');
        IpHelper::setContextScheme($uri->getScheme() ?: (IpHelper::scheme($request->getServerParams())));
        IpHelper::setContextPort($uri->getPort() ?: (IpHelper::port($request->getServerParams())));
        IpHelper::setContextScript($request->getServerParams()['PHP_SELF'] ?? $request->getServerParams()['SCRIPT_NAME'] ?? '');

        // 将加工好的 $request 压入请求栈
        \Framework\Http\Psr7\RequestStack::push($request);
        try {
            return $handler->handle($request);
        } finally {
            // 弹出请求栈
            \Framework\Http\Psr7\RequestStack::pop();
        }
    }

    /* ------------------------------- Body ---------------------------------*/
    private function processBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $rawBody = $this->getRawBody($request);
        $parsedBody = $this->parseRequestBody(
            $request->getServerParams()['CONTENT_TYPE'] ?? '',
            $rawBody,
            $request
        );

        // 保留原始body和解析后的数据
        return $request
            ->withParsedBody($parsedBody)
            ->withBody(\Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStream($rawBody));
    }

    private function getRawBody(ServerRequestInterface $request): string
    {
        $coroClass = "\\Swoole\\Coroutine";
        return $this->isSwooleCoroutine() ? call_user_func([$coroClass, 'getContext'])['rawContent'] ?? '' : (string)$request->getBody();
    }

    private function parseRequestBody(string $contentType, string $raw, ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (!empty($parsedBody) && !is_string($parsedBody)) return (array)$parsedBody;

        switch (true) {
            case stripos($contentType, 'application/json') !== false:
                return json_decode($raw, true) ?: [];
            case stripos($contentType, 'application/x-www-form-urlencoded') !== false:
                if (!empty($raw)) {
                    parse_str($raw, $result);
                    return $result;
                }
                return [];
            default:
                return is_array($parsedBody) ? $parsedBody : [];
        }
    }

    /* ------------------------------- UploadedFiles ---------------------------------*/
    private function processUploadedFiles(ServerRequestInterface $request): ServerRequestInterface
    {
        $files = $request->getUploadedFiles();

        foreach ($files as $field => $fileGroup) {
            if ($fileGroup instanceof UploadedFileInterface) {
                $files[$field] = $this->secureHandle($fileGroup, $request);
            } elseif (is_array($fileGroup)) {
                $secured = [];
                foreach ($fileGroup as $file) {
                    if ($file instanceof UploadedFileInterface) {
                        $secured[] = $this->secureHandle($file, $request);
                    }
                }
                $files[$field] = $secured;
            }
        }

        return $request->withUploadedFiles($files);
    }

    /* ------------------------- Secure handling ---------------------------*/

    private function secureHandle(UploadedFileInterface $file, \Framework\Http\Interfaces\ServerRequestInterface $request): UploadedFileInterface
    {
        // 无文件上传时直接返回，不做安全审查
        if ($file->getError() === \UPLOAD_ERR_NO_FILE) {
            return $file;
        }

        // 基础错误
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new UploadSecurityException("Upload error code: {$file->getError()}");
        }

        // 临时文件合法来源校验
        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !$this->isValidUploadSource($tmpPath)) {
            throw new UploadSecurityException('Invalid upload source');
        }

        // 注册自动清理回调
        $cleaner = function ($e = null) use ($tmpPath, $request) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
                $this->logger->debug("Cleaned temp file", [
                    'path' => $tmpPath,
                    'reason' => $e ? $e->getMessage() : 'Normal request termination',
                    'client_info' => [
                        'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                        'ua' => $request->getServerParams()['HTTP_USER_AGENT'] ?? null
                    ]
                ]);
            }
        };

        $this->registerTempFileCleaner($tmpPath, $cleaner);

        try {
            // 尺寸校验
            $this->checkFileSize($file);

            if (empty($file->getClientFilename())) {
                throw new UploadSecurityException('Missing filename');
            }

            // 1. 识别阶段 (Identity): 以真实内容为准
            $realMime = $this->detectRealMimeType($file);

            // 2. 验证白名单并获取标准后缀
            $canonicalExt = $this->validateMimeAndExt($realMime, $file->getClientFilename(), $request);
            // 如果文件的真实 MIME 不在 Upload.php 的配置中，系统立即抛出异常拦截。
            if (false === $canonicalExt) {
                throw new UploadSecurityException("Disallowed file type: " . $realMime);
            }

            // 3. 风险评估阶段 (Risk Assessment): 深度内容扫描
            $isDeepSafe = $this->deepContentScan($realMime, $tmpPath);
            if (!$isDeepSafe) {
                throw new UploadSecurityException("Security verification failed: Malicious content or invalid structure detected.");
            }

            // 4. 执行阶段 (Execution): 判定是否需要安全隔离存储
            $clientExt = \strtolower(\pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
            $allowedExts = $this->uploadSecurity()['allowed_mimes'][$realMime] ?? [];
            $extMatches = \in_array($clientExt, $allowedExts, true);

            // 探测杀毒引擎可用性
            $clamSocket = $this->uploadSecurity()['clamav_socket'] ?? '';
            $hasAntivirus = $clamSocket && @\file_exists($clamSocket);

            // 强安全判定逻辑：只有 (已验证的安全图片) 或 (后缀匹配 且 经过了杀毒引擎扫描) 时才判为完全安全
            $isImage = \strpos($realMime, 'image/') === 0;
            $isStrictSafe = ($isImage && $isDeepSafe) || ($extMatches && $hasAntivirus);
            $notSafe = !$isStrictSafe;

            // 5. 存储：使用由 MIME 决定的标准后缀进行保存
            return $this->store($file, $realMime, $notSafe, $canonicalExt);
        } catch (UploadSecurityException $e) {
            // 立即清理验证失败的临时文件
            $cleaner($e);
            throw $e;
        }
    }

    private function registerTempFileCleaner(string $path, callable $cleaner): void
    {
        // Swoole协程环境
        if ($this->isSwooleCoroutine()) {
            $coroClass = "\\Swoole\\Coroutine";
            $ctx = call_user_func([$coroClass, 'getContext']);
            $ctx->tempFileCleaners[$path] = ['active' => true, 'cleaner' => $cleaner];
            call_user_func([$coroClass, 'defer'], function () use ($path) {
                $coroClass = "\\Swoole\\Coroutine";
                $ctx = call_user_func([$coroClass, 'getContext']);
                $info = $ctx->tempFileCleaners[$path] ?? null;
                if ($info && $info['active']) {
                    ($info['cleaner'])(null);
                }
                unset($ctx->tempFileCleaners[$path]);
            });
        } else {
            // FPM环境
            register_shutdown_function($cleaner);
        }
    }

    private function isValidUploadSource(string $tmp): bool
    {
        if ($this->isSwooleCoroutine()) {
            /**
             * @var array $meta
             * Swoole 将文件元数据存放在 Coroutine Context 中
             */
            $coroClass = "\\Swoole\\Coroutine";
            $meta = call_user_func([$coroClass, 'getContext'])['uploaded_files'] ?? [];
            foreach ($meta as $m) {
                if ($m['tmp_name'] === $tmp) return true;
            }
            return false;
        }
        return is_uploaded_file($tmp);
    }

    /**
     * 上传配置（零信任白名单）
     */
    private function uploadSecurity(): array
    {
        $temp = $this->uploadConfig['upload_temp'] ?? 'storage/tmp/';
        $temp = is_string($temp) ? $temp : 'storage/tmp/';
        $temp = (strpos($temp, APP_PATH) === 0) ? $temp : APP_PATH . trim($temp, '/\\') . DIRECTORY_SEPARATOR;

        $data = [
            'upload_temp' => $temp,
            'attach_dir_save_rule' => $this->uploadConfig['attach_dir_save_rule'],
            'max_size' => $this->uploadConfig['max_file_size'],
            'allowed_mimes' => $this->uploadConfig['allowed_mimes'],
            'content_check_depth' => $this->uploadConfig['content_check_depth'],
            // 扩展检查 YARA/ClamAV 路径
            'clamav_socket' => $this->uploadConfig['clamav_socket']
        ];
        return $data;
    }

    private function checkFileSize(UploadedFileInterface $file): void
    {
        $streamSize = $file->getStream()->getSize();
        if ($file->getSize() !== $streamSize) {
            throw new UploadSecurityException("File size tampered");
        }

        if ($file->getSize() > $this->uploadSecurity()['max_size']) {
            throw new UploadSecurityException('File too large');
        }
    }

    private function detectRealMimeType(UploadedFileInterface $file): string
    {
        if (!\extension_loaded('fileinfo')) {
            throw new UploadSecurityException('Fileinfo is not enabled');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !$this->isValidUploadSource($tmpPath)) {
            throw new UploadSecurityException('Invalid upload source');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime1 = $finfo->file($tmpPath);

        // 二次检测：读取前 content_check_depth 字节
        $handle = fopen($tmpPath, 'rb');
        $header = fread($handle, $this->uploadSecurity()['content_check_depth']);
        fclose($handle);
        $mime2 = $finfo->buffer($header);

        if ($mime1 !== $mime2) {
            throw new UploadSecurityException('MIME type mismatch');
        }

        return $mime1;
    }

    // 验证 MIME 并返回建议的后缀，失败返回 false
    private function validateMimeAndExt(string $mime, ?string $filename, ServerRequestInterface $request)
    {
        $allowedMimes = $this->uploadSecurity()['allowed_mimes'];
        if (!isset($allowedMimes[$mime])) {
            // 只要文件名符合分片模式 (.partN) 或请求中明确包含 upload_chunk 动作，则允许通过验证。
            // 原因是分片内容不完整，finfo 识别出的 MIME 往往不在业务白名单中（如 deb/exe 片段）。
            $isChunk = \preg_match('/\.part\d+$/i', (string)$filename);
            if (!$isChunk && $filename) {
                // 兼容某些前端可能不带扩展名但参数里有分片信息的情况
                $body = $request->getParsedBody() ?? [];
                $action = (string)($body['action'] ?? '');
                if ($action === 'upload_chunk' || isset($body['uploadId'])) {
                    $isChunk = true;
                }
            }

            if ($isChunk) {
                return 'part';
            }
            return false;
        }

        // 路径穿越检测
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
            return false;
        }

        // 返回该 MIME 对应的首选后缀
        return $allowedMimes[$mime][0] ?? false;
    }

    /**
     * 深度扫描：图片 → exif_imagetype；可执行 → ClamAV / YARA
     */
    private function deepContentScan(string $mime, string $path): bool
    {
        try {
            // 1. 图像内容合法性校验
            if (\strpos($mime, 'image/') === 0) {
                $status = false;
                if (\function_exists('exif_imagetype')) {
                    $status = @\exif_imagetype($path) !== false;
                }

                if (!$status && \function_exists('getimagesize')) {
                    $status = @\getimagesize($path) !== false;
                }

                if (!$status) return false; // 图片内容损坏或伪装
            }

            // 2. 杀毒引擎深度扫描 (ClamAV)
            $clamSocket = $this->uploadSecurity()['clamav_socket'] ?? '';
            if ($clamSocket && @\file_exists($clamSocket)) {
                if (!\function_exists('socket_create')) return true; // 扩展缺失时跳过，依赖基础安全

                $clam = @\socket_create(AF_UNIX, SOCK_STREAM, 0);
                if (!$clam || !@\socket_connect($clam, $clamSocket)) return true; // 连接失败时跳过，确保业务不中断

                $command = "nSCAN $path\n";
                if (@\socket_write($clam, $command) !== \strlen($command)) {
                    @\socket_close($clam);
                    return true;
                }

                $response = '';
                while ($buf = @\socket_read($clam, 2048)) {
                    $response .= $buf;
                }
                @\socket_close($clam);

                if (\stripos($response, 'OK') === false) return false; // 发现病毒载荷
            }

            return true;
        } catch (\Throwable $e) {
            throw new UploadSecurityException('Unexpected error during security scan: ' . $e->getMessage());
        }
    }

    private function store(UploadedFileInterface $src, string $mime, bool $notSafe, string $ext): UploadedFileInterface
    {
        $tmpPath = $src->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !$this->isValidUploadSource($tmpPath)) {
            throw new UploadSecurityException('Invalid upload source');
        }

        try {
            //$sha256  = hash_file('sha256', $tmpPath);
            $ctx = hash_init('sha256');
            $handle = fopen($tmpPath, 'rb');
            if (!$handle) {
                throw new UploadSecurityException("Unable to open temporary file");
            }

            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                hash_update($ctx, $chunk);
            }

            fclose($handle);

            $sha256 = hash_final($ctx);
            $addExt = true === $notSafe ? '_' : '';
            $uuid = $this->generateUuid();
            $newName = sprintf('%s_%s.%s%s', $sha256, $uuid, $addExt, $ext);

            $storageDir = $this->uploadSecurity()['upload_temp'] . date($this->uploadSecurity()['attach_dir_save_rule']);
            if (!\is_dir($storageDir) && !@\mkdir($storageDir, 0700, true)) {
                throw new UploadSecurityException("Failed to create storage directory: $storageDir. Please check folder permissions.");
            }

            if (\is_dir($storageDir) && \substr(\sprintf('%o', \fileperms($storageDir)), -4) !== '0700') {
                // throw new UploadSecurityException("Insecure storage directory: $storageDir. Permissions must be 0700.");
                @\chmod($storageDir, 0700);
            }

            $dest = $storageDir . '/' . $newName;
            if (file_exists($dest)) {
                // 策略1：记录日志并返回已存文件
                // 策略2：追加随机后缀
                // 非白名单文件一律返回后缀为 xx._ext 安全设置
                $newName = $sha256 . '_' . bin2hex(random_bytes(4)) . ".$addExt$ext";
                $dest = $storageDir . '/' . $newName;
            }
            $src->moveTo($dest);

            // 清理已成功转移文件
            $this->unregisterTempFileCleaner($tmpPath);

            // [安全策略] 物理存盘文件名依然是随机哈希+UUID，但 StoredFile 对象携带经过安全过滤后的原始文件名。
            // 这确保了底层存储的绝对隔离，同时让业务层能正确获取并展示用户的原始文件名。
            $originalName = (string)$src->getClientFilename();
            $safeOriginalName = \str_replace(["/", "\\", ".."], "", \strip_tags($originalName));

            return new \Framework\Http\Psr7\StoredFile($dest, $safeOriginalName, $mime, $src->getSize());
        } catch (\Exception $e) {
            $this->logger->error("Storage failed", ['tmp' => $tmpPath, $e]);
            throw $e;
        }
    }

    private function unregisterTempFileCleaner(string $path): void
    {
        if ($this->isSwooleCoroutine()) {
            // Swoole协程环境：通过标志位禁用清理器，避免误删已转移文件
            $coroClass = "\\Swoole\\Coroutine";
            $ctx = call_user_func([$coroClass, 'getContext']);
            if (isset($ctx->tempFileCleaners[$path])) {
                $ctx->tempFileCleaners[$path]['active'] = false;
            }
        } else {
            // FPM无法直接取消，需标记状态
            file_put_contents($path . '.status', 'persisted');
        }
    }

    /* ------------------------ UUID generator -----------------------------*/
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10))
        );
    }

    /* ------------------------------- QueryParams ---------------------------------*/
    private function processQueryParams(ServerRequestInterface $request): ServerRequestInterface
    {
        parse_str($request->getUri()->getQuery(), $queryParams);
        return $request->withQueryParams($queryParams);
    }

    /* ------------------------------- CookieParams ---------------------------------*/
    private function processCookieParams(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withCookieParams($request->getCookieParams() ?? []);
    }

    /* ------------------------------- RouteParams ---------------------------------*/
    private function processRouteParams(ServerRequestInterface $request): ServerRequestInterface
    {
        // 获取原始路径（不含 QueryString）
        $path = $request->getUri()->getPath();
        if ('/' === $path) {
            $path = $request->getUri()->getQuery();
            // 只解析路径，其他GET参数由 processQueryParams 解析(?api=true&groupId=107)
            if (empty($path) || strpos($path, '=') !== false || strpos($path, '&') === false) return $request;
        }

        $parts = [];
        // 根据模式分割
        switch ($this->urlRewriteMode) {
            case 0: // ?user-home-1.html
            case 1: // user-home-1.html
                $path = pathinfo($path, PATHINFO_FILENAME);
                $parts = explode('-', $path);
                break;
            case 2: // /user/home/1.html
                $path = trim($path, '/');
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                if ('html' === $ext) {
                    $clean = substr($path, 0, -5);
                } else {
                    $staticExts = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'map', 'webp', 'avif'];
                    if ($ext && !in_array(strtolower($ext), $staticExts, true)) {
                        $clean = strtr($path, ['.' . $ext => '_' . $ext]);
                    } else {
                        $clean = $path;
                    }
                }
                $parts = explode('/', $clean);
                break;
            case 3: // /user/home/1
                $path = trim($path, '/');
                $parts = explode('/', $path);
                break;
            default:
                $parts = [];
        }

        if (!empty($parts)) {
            foreach ($parts as $key => &$val) {
                $val = is_numeric($val) ? (int)$val : (string)$val;
                $request = $request->withAttribute((string)$key, $val);
            }
            unset($val);

            $request = $request->withAttribute('route_params', $parts);
            $request = $request->withQueryParams($parts + $request->getQueryParams());
        }

        return $request;
    }

    private function isSwooleCoroutine(): bool
    {
        $coroClass = "\\Swoole\\Coroutine";
        return \extension_loaded('swoole') && ((call_user_func([$coroClass, 'getCid']) ?? -1) > 0);
    }
}
