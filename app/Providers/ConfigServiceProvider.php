<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

// 优先注册排第一位
class ConfigServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        // hook app_Providers_ConfigServiceProvider_register_start.php

        // 提前加载全局配置
        $config = \Framework\Core\Config::loadDir();
        $container->set(\Framework\Core\Config::class, $config);

        // hook app_Providers_ConfigServiceProvider_register_before.php

        // 批量定义配置项（兼容 PHP 7.2）
        $configs = [
            'appConfig' => [
                'key' => 'app',
                'process' => function ($appConfig) {
                    if (isset($appConfig['upload_path']) && $appConfig['upload_path'] === '/storage/upload/') {
                        $appConfig['upload_path'] = APP_PATH . trim($appConfig['upload_path'], './') . '/';
                    }
                    return $appConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "appConfig" is missing'
            ],
            'cacheConfig' => [
                'key' => 'cache',
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "cacheConfig" is missing'
            ],
            'dbConfig' => [
                'key' => 'db',
                'process' => function ($dbConfig) {
                    $driver = isset($dbConfig['driver']) ? $dbConfig['driver'] : 'mysql';
                    $dbConfig['master'] = isset($dbConfig['connections'][$driver]['master']) ? $dbConfig['connections'][$driver]['master'] : array();
                    $dbConfig['slaves'] = isset($dbConfig['connections'][$driver]['slaves']) ? $dbConfig['connections'][$driver]['slaves'] : array();

                    // 分片配置扁平化：将 sharding.tables 转换为 shards，并注入 Pool 可见的 slaves
                    $dbConfig['shards'] = [];
                    $dbConfig['shard_routers'] = [];
                    if (!empty($dbConfig['sharding']['tables'])) {
                        foreach ($dbConfig['sharding']['tables'] as $tableName => $shardingCfg) {
                            $nodes = $shardingCfg['nodes'] ?? [];
                            if (empty($nodes)) {
                                continue;
                            }
                            $firstNode = reset($nodes);

                            // 向后兼容：表级默认配置（使用第一个 node 的 master）
                            $dbConfig['shards'][$tableName] = [
                                'master' => $firstNode['master'] ?? [],
                                'slaves' => [],
                            ];

                            foreach ($nodes as $nodeId => $nodeCfg) {
                                // 为每个 shard 节点生成独立的连接配置
                                $dbConfig['shards'][$nodeId] = [
                                    'master' => $nodeCfg['master'] ?? [],
                                    'slaves' => $nodeCfg['slaves'] ?? [],
                                ];

                                if (!empty($nodeCfg['slaves'])) {
                                    foreach ($nodeCfg['slaves'] as $slave) {
                                        // 节点级 slave（供 Router 使用）
                                        $slaveNode = $slave;
                                        $slaveNode['shard'] = (string)$nodeId;
                                        $dbConfig['slaves'][] = $slaveNode;

                                        // 表级默认 slave（向后兼容）
                                        $slaveDefault = $slave;
                                        $slaveDefault['shard'] = (string)$tableName;
                                        $dbConfig['shards'][$tableName]['slaves'][] = $slaveDefault;
                                    }
                                }
                            }

                            // 分片路由元信息
                            $dbConfig['shard_routers'][$tableName] = [
                                'shard_key' => $shardingCfg['shard_key'] ?? '',
                                'nodes' => array_keys($nodes),
                            ];
                        }
                    }

                    unset($dbConfig['connections'], $dbConfig['sharding']);
                    return $dbConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "dbConfig" is missing'
            ],
            'i18nConfig' => [
                'key' => 'i18n',
                'process' => function ($i18nConfig) {
                    if (isset($i18nConfig['paths']['app'])) {
                        $i18nConfig['paths']['app'] = APP_PATH . ltrim($i18nConfig['paths']['app'], './');
                    }

                    if (isset($i18nConfig['paths']['plugins'])) {
                        $i18nConfig['paths']['plugins'] = APP_PATH . ltrim($i18nConfig['paths']['plugins'], './');
                    }

                    if (isset($i18nConfig['paths']['themes'])) {
                        $i18nConfig['paths']['themes'] = APP_PATH . ltrim($i18nConfig['paths']['themes'], './');
                    }

                    return $i18nConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "i18nConfig" is missing'
            ],
            'loggerConfig' => [
                'key' => 'logger',
                'process' => function ($loggerConfig) {
                    if (isset($loggerConfig['file']['path'])) {
                        $loggerConfig['file']['path'] = APP_PATH . trim($loggerConfig['file']['path'], './');
                    }

                    return $loggerConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "loggerConfig" is missing'
            ],
            'sessionConfig' => [
                'key' => 'session',
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "sessionConfig" is missing'
            ],
            'uploadConfig' => [
                'key' => 'upload',
                'process' => function ($uploadConfig) {
                    $fixPath = function ($path) {
                        if (empty($path)) return $path;
                        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
                        $appRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, APP_PATH), DIRECTORY_SEPARATOR);

                        // 幂等识别：如果已经是绝对路径（包含 APP_ROOT），则直接返回标准化后的路径
                        if (strpos($path, $appRoot . DIRECTORY_SEPARATOR) === 0 || $path === $appRoot) {
                            return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        }

                        return $appRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '\\');
                    };

                    if (isset($uploadConfig['upload_temp'])) {
                        $uploadConfig['upload_temp'] = $fixPath($uploadConfig['upload_temp']);
                    }
                    if (isset($uploadConfig['disks']['local']['root'])) {
                        $uploadConfig['disks']['local']['root'] = $fixPath($uploadConfig['disks']['local']['root']);
                    }
                    return $uploadConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "uploadConfig" is missing'
            ],
            'pluginConfig' => [
                'key' => 'plugin',
                'process' => function ($pluginConfig) {
                    if (isset($pluginConfig['plugins_path'])) {
                        $pluginConfig['plugins_path'] = APP_PATH . ltrim($pluginConfig['plugins_path'], './');
                    }

                    return $pluginConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "viewConfig" is missing'
            ],
            'viewConfig' => [
                'key' => 'view',
                'process' => function ($viewConfig) {
                    if (isset($viewConfig['themes_path'])) {
                        $viewConfig['themes_path'] = APP_PATH . ltrim($viewConfig['themes_path'], './');
                    }

                    return $viewConfig;
                },
                'validate' => function ($v) {
                    return !empty($v);
                },
                'error' => 'Database configuration "viewConfig" is missing'
            ]
        ];

        // hook app_Providers_ConfigServiceProvider_register_after.php

        $allConfigs = [];
        foreach ($configs as $name => $def) {
            $value = $config->get($def['key']);

            // 处理特殊逻辑（如 dbConfig）
            if (isset($def['process'])) {
                $value = $def['process']($value);
            }

            // 验证配置
            if (!$def['validate']($value)) {
                throw new \RuntimeException($def['error']);
            }

            // 直接注册实例（即时加载）
            $container->set($name, $value);
            $allConfigs[$name] = $value;
        }

        // hook app_Providers_ConfigServiceProvider_register_end.php

        // 设置到全局参数，供智能注入使用
        $container->setParams($allConfigs);
    }

    public function boot(\Framework\Core\Container $container): void
    {
        // nothing
    }
}
