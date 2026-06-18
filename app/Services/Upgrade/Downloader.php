<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * Downloader
 *
 * 负责安全下载升级包并验证完整性
 */
class Downloader
{
    /**
     * 下载远程文件并校验哈希
     *
     * @param string $url 官方下载地址
     * @param string $savePath 本地保存路径
     * @param string $expectedHash 预期哈希值 (SHA256)
     * @return bool
     * @throws \RuntimeException
     */
    public function download(string $url, string $savePath, string $expectedHash = ''): bool
    {
        try {
            // 【工程级方案：边下边算哈希】
            // 参考 LocalStorage 策略，在流拷贝过程中同步计算 SHA256，省去后期再次读取磁盘 IO
            // 对齐 HttpClient 配置风格：根据 APP_ENV 决定 SSL 验证，使用数组 headers
            $env = getenv('APP_ENV');
            if ($env === false) {
                $env = 'dev';
            }
            $verifySSL = $env === 'prod';

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 600,
                    'header' => [
                        'User-Agent: ' . \Framework\Utils\IpHelper::userAgent(),
                        'Accept: */*',
                    ],
                    'follow_location' => true,
                ],
                'ssl' => [
                    'verify_peer' => $verifySSL,
                    'verify_peer_name' => $verifySSL,
                ],
            ]);

            $remote = @fopen($url, 'rb', false, $context);
            if (!$remote) {
                $error = error_get_last();
                throw new \RuntimeException('Failed to open remote URL: ' . ($error['message'] ?? 'Unknown Connection Error'));
            }

            // P1 FIX: 检查 HTTP 状态码，防止保存错误页面（如 403 HTML 导致 1KB 损坏包）
            $meta = stream_get_meta_data($remote);
            if (!empty($meta['wrapper_data'])) {
                foreach ($meta['wrapper_data'] as $header) {
                    if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/i', $header, $matches)) {
                        $statusCode = (int)$matches[1];
                        if ($statusCode !== 200) {
                            fclose($remote);
                            throw new \RuntimeException('HTTP error ' . $statusCode . ' while downloading: ' . $url);
                        }
                        break;
                    }
                }
            }

            $local = @fopen($savePath, 'wb');
            if (!$local) {
                fclose($remote);
                throw new \RuntimeException('Failed to open local path for writing: ' . $savePath);
            }

            // 初始化哈希上下文 (SHA256)
            $hashCtx = !empty($expectedHash) ? hash_init('sha256') : null;
            $bufferSize = 256 * 1024; // 256KB 缓存在内存负载和 IO 效率间达到平衡

            while (!feof($remote)) {
                $chunk = fread($remote, $bufferSize);
                if ($chunk === false) {
                    throw new \RuntimeException('Error occurred during stream reading.');
                }
                if ($chunk === '') continue;

                if (fwrite($local, $chunk) === false) {
                    throw new \RuntimeException('Failed to write chunk to local disk.');
                }

                // 同步更新哈希
                if ($hashCtx) {
                    hash_update($hashCtx, $chunk);
                }
            }

            fclose($remote);
            fclose($local);

            // 最终哈希校验
            if ($hashCtx) {
                $actualHash = hash_final($hashCtx);
                if ($actualHash !== $expectedHash) {
                    @unlink($savePath);
                    throw new \RuntimeException('File integrity check failed (Hash-while-Downloading). mismatch.');
                }
            }

            return true;
        } catch (\Exception $e) {
            if (isset($remote) && is_resource($remote)) fclose($remote);
            if (isset($local) && is_resource($local)) fclose($local);
            if (file_exists($savePath)) @unlink($savePath);
            throw new \RuntimeException('Engineering download failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
