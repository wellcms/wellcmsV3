<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * UpgradeService
 *
 * 自动升级系统核心调度服务
 */
class UpgradeService
{
    /** @var \App\Services\System\KeyValueService */
    private $kv;
    /** @var \Framework\Core\Container */
    private $container;
    /** @var array */
    private $appConfig;
    public function __construct(array $appConfig, \App\Services\System\KeyValueService $kv, \Framework\Core\Container $container)
    {
        $this->kv = $kv;
        $this->appConfig = $appConfig;
        $this->container = $container;
    }

    /**
     * 检查远程版本信息
     *
     * @return array
     */
    public function checkVersion(): array
    {
        $currentVersion = $this->appConfig['version'];
        $config = (array)($this->kv->settingGet('config') ?? []);
        $apiUrl = !empty($config['upgrade_url']) ? $config['upgrade_url'] : 'https://www.wellcms.com/api/v1/version.html';

        $client = new \Framework\Utils\HttpClient();
        try {
            $response = $client->request([
                'method' => 'POST',
                'url' => $apiUrl,
                'body' => json_encode([
                    'version' => $currentVersion,
                    'php' => PHP_VERSION,
                    'os' => PHP_OS,
                ]),
                'timeout' => 5,
                'headers' => [
                    'User-Agent: ' . \Framework\Utils\IpHelper::userAgent(),
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'followRedirects' => true,
                'verifySSL' => false,
                'caBundle' => '/tmp/',
                'returnResponse' => false,
            ]);

            $data = json_decode($response, true);
            if (!is_array($data)) {
                if ($this->container->has(\Framework\Logger\LoggerInterface::class)) {
                    $this->container->get(\Framework\Logger\LoggerInterface::class)->warning(
                        'Upgrade API returned non-JSON response: ' . mb_substr((string)$response, 0, 200)
                    );
                }
                return [];
            }
            return $data;
        } catch (\Throwable $e) {
            if ($this->container->has(\Framework\Logger\LoggerInterface::class)) {
                $this->container->get(\Framework\Logger\LoggerInterface::class)->warning(
                    'Upgrade API request failed: ' . $e->getMessage()
                );
            }
            return [];
        }
    }

    /**
     * 校验远程版本响应并持久化到配置
     *
     * 对 $response 进行有效性判定，区分"网络错误"、"无新版本"、"有新版本"三种状态，
     * 避免互相覆盖，并在无新版本时主动清理过期升级元数据。
     *
     * @param array $response 远程 checkVersion() 返回的原始数组
     * @param array $config 当前本地配置（引用传回，供控制器渲染使用）
     * @return string 官方消息文本
     */
    public function validateAndStoreVersion(array $response, array &$config): string
    {
        /** @var \App\Services\System\CacheService $cache */
        $cache = $this->container->get(\App\Services\System\CacheService::class);

        // 协议约定：响应有效载荷位于 data 字段
        $data = $response['data'];

        // 有效的 API 响应：始终更新冷却期，防止每页重试
        // 注意：data 为空时（API 网络错误）不设冷却、不改状态，不兜底
        $config['last_version'] = time() + 7200;

        if (isset($data['upgrade'])) {
            $config['upgrade'] = (int)$data['upgrade'];

            // 无新版本时彻底清理残留升级元数据，防止过期数据被复用
            if (empty($data['upgrade'])) {
                unset($config['upgrade_url'], $config['upgrade_hash'], $config['upgrade_id']);
                $config['upgrade_id'] = '';
            } elseif (!empty($data['upgrade_id'])) {
                // 仅在有新版本时才更新 upgrade_id，避免 upgrade=0 时残留
                $config['upgrade_id'] = (string)$data['upgrade_id'];
            }
        }

        // 固化升级包资源（仅在 data 提供时才更新，防止清空）
        if (!empty($data['url'])) {
            $config['upgrade_url'] = (string)$data['url'];
        }
        if (!empty($data['hash'])) {
            $config['upgrade_hash'] = (string)$data['hash'];
        }

        // 对远程版本号做基础格式校验，防止异常数据污染本地配置
        if (!empty($data['version']) && preg_match('#^\d+(\.\d+){1,3}$#', $data['version'])) {
            $config['official_version'] = $data['version'];
        }

        // 持久化官方消息到配置，供 panel 和 checkUpgrade 页面展示
        if (!empty($data['message'])) {
            $config['official_info'] = $data['message'];
        }

        // 持久化到数据库
        if (!$this->kv->settingSet('config', $config)) {
            throw new \App\Exception\UpgradeException(
                '版本配置持久化失败：无法获取分布式锁或写入被拒绝',
                20
            );
        }

        // 处理官方消息缓存（仅在 API 响应有效时）
        $message = '';
        if (!empty($data['message'])) {
            $cache->set('official-message', $data['message'], 7200);
            $message = $data['message'];
        } elseif (isset($data['upgrade']) && empty($data['upgrade'])) {
            // 明确无新版本时清除旧消息，避免误导
            $cache->delete('official-message');
        }

        return $message;
    }

    /**
     * 升级前环境预检
     *
     * 检查升级流程所依赖的关键路径是否存在且可写，防止覆盖到一半因权限崩溃。
     * 本方法在 FPM / Swoole 下均使用标准 PHP 同步 I/O，因升级操作是管理员手动
     * 触发的极低频操作，同步阻塞在业务上可接受；如需极致优化，可后续替换为
     * 协程安全的文件操作 API。
     *
     * @throws \App\Exception\UpgradeException
     */
    public function preflightCheck(): void
    {
        $checkPaths = [
            APP_PATH,
            APP_PATH . 'config/App.php',
            APP_PATH . 'storage/tmp/',
            APP_PATH . 'app/Services/Upgrade/UpgradeService.php',
        ];

        foreach ($checkPaths as $path) {
            // 确保路径"必须存在且可写"，不存在直接失败
            if (!file_exists($path) || !is_writable($path)) {
                throw new \App\Exception\UpgradeException(
                    '环境检测失败：[' . basename($path) . '] 不存在或无写权限，请先修复磁盘权限。',
                    1
                );
            }
        }
    }

    /**
     * 执行完整升级流程
     *
     * @param string $upgradeUrl 升级包下载地址
     * @param string $expectedHash 预期哈希
     * @return bool
     * @throws \Throwable
     */
    public function run(string $upgradeUrl, string $expectedHash = ''): bool
    {
        if (empty($upgradeUrl)) {
            throw new \RuntimeException('Invalid upgrade URL.');
        }

        // 解除脚本执行时间限制，防止大文件下载过程中断
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $tmpDir = APP_PATH . ltrim($this->appConfig['tmp_path'], './');
        $zipPath = $tmpDir . 'upgrade_' . date('Ymd_His') . '.zip';

        // 1. 下载阶段
        /** @var Downloader $downloader */
        $downloader = $this->container->get(Downloader::class);
        $downloader->download($upgradeUrl, $zipPath, $expectedHash);

        // 2. 部署阶段 (解压覆盖)
        /** @var Deployer $deployer */
        $deployer = $this->container->get(Deployer::class);
        $deployer->extractAndOverwrite($zipPath, APP_PATH);

        // 3. 数据库迁移阶段（统一为 PHP 脚本，与插件/主题升级同源）
        $scriptPath = APP_PATH . 'upgrade.php';
        if (is_file($scriptPath)) {
            /** @var ScriptRunner $scriptRunner */
            $scriptRunner = $this->container->get(ScriptRunner::class);
            $scriptRunner->run($scriptPath);

            // 执行完毕后清理脚本，防止泄露或重复执行
            @unlink($scriptPath);
        }

        // 4. 版本同步与持久化
        // 从数据库实时读取官方版本号，避免依赖构造时传入的 $appConfig（可能未包含最新远程版本）
        $config = (array)($this->kv->settingGet('config') ?? []);
        $newVersion = !empty($config['official_version']) ? $config['official_version'] : $this->appConfig['version'];
        $versionDate = time();

        // A. 更新数据库 well_kv -> setting -> config
        if (is_array($config)) {
            $config['version'] = $newVersion;
            $config['version_date'] = $versionDate;
            $config['upgrade'] = 0;
            // 清理升级残留的元数据
            unset($config['upgrade_url'], $config['upgrade_hash'], $config['upgrade_id']);
            if (!$this->kv->settingSet('config', $config)) {
                throw new \App\Exception\UpgradeException(
                    '升级后版本同步失败：配置写入被拒绝',
                    21
                );
            }
        }

        // B. 更新物理配置文件 config/App.php (如果允许写入)
        $appConfigFile = APP_PATH . 'config/App.php';
        if (is_writable($appConfigFile)) {
            $configContent = file_get_contents($appConfigFile);
            $newConfigContent = preg_replace("/'version'\s*=>\s*'.*?'/", "'version' => '{$newVersion}'", $configContent);
            file_put_contents($appConfigFile, $newConfigContent);
        }

        // 5. 清理与结算
        @unlink($zipPath);

        // 如果开启了 OpCache，强制刷新
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // 彻底清理编译缓存与编译后的类映射
        \Framework\Utils\DirectoryHelper::rmdirRecursive($tmpDir);
        @mkdir($tmpDir, 0755, true);

        // 如果是 Swoole 模式，触发热重启以更新 Worker 内存中的代码
        \Framework\Utils\Runtime::reload();

        return true;
    }
}
