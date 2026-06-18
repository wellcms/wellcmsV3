<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class ModelServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        try {

            // hook app_Providers_ModelServiceProvider_register_start.php

            $models = [
                \App\Models\CacheModel::class,
                \App\Models\GroupModel::class,
                \App\Models\IpListModel::class,
                \App\Models\KeyValueModel::class,
                \App\Models\LogModel::class,
                \App\Models\NavigationModel::class,
                \App\Models\RecycleModel::class,
                \App\Models\SessionDataModel::class,
                \App\Models\SessionModel::class,
                \App\Models\UserModel::class,
                \App\Models\FileStorageModel::class,
                \App\Models\AttachmentModel::class,
                \App\Models\TempContentModel::class,
                \App\Models\UploadLogModel::class
            ];

            // hook app_Providers_ModelServiceProvider_register_models.php

            foreach ($models as $model) {
                $container->bind($model, $model, true, false);
            }

            // 基础核心服务 (Core Foundation Services) - 非延迟加载，确保架构支柱常驻
            $coreServices = [
                \App\Services\System\CacheService::class,
                \App\Services\Auth\GroupService::class,
                \App\Services\System\IpListService::class,
                \App\Services\System\KeyValueService::class,
                \App\Services\System\LogService::class,
                \App\Services\Auth\SessionDataService::class,
                \App\Services\Auth\SessionService::class,
                \App\Services\Auth\UserService::class,
                \App\Services\Auth\TokenService::class,
                \App\Services\Storage\FileStorageService::class,
                \App\Services\Storage\AttachmentService::class,
                \App\Services\Content\TempContentService::class,
                \App\Services\Content\NavigationService::class,
            ];

            // hook app_Providers_ModelServiceProvider_register_core_services.php

            foreach ($coreServices as $service) {
                $container->bind($service, $service, true, false);
            }

            $moduleServices = [
                \App\Services\Content\RecycleService::class,
                \App\Services\Storage\UploadLogService::class,
                \App\Services\System\MailService::class,
            ];

            // hook app_Providers_ModelServiceProvider_register_services.php

            foreach ($moduleServices as $service) {
                $container->bind($service, $service, true, true);
            }

            // 特殊构造服务
            $container->bind(\App\Controllers\Admin\Service\TokenManager::class, function ($container) {
                return new \App\Controllers\Admin\Service\TokenManager(
                    $container->get('appConfig'),
                    $container->get('sessionConfig')
                );
            }, true, true);

            // hook app_Providers_ModelServiceProvider_register_after.php

            // 有依赖渲染 (不延迟加载，避免 Proxy 导致构造函数类型校验失败)
            $container->bind(\Framework\Cache\Interfaces\CacheInterface::class, static function ($container) {
                return new \App\Factory\CacheFactory($container->get(\App\Services\System\CacheService::class), $container->get('cacheConfig'));
            }, true, false);

            // hook app_Providers_ModelServiceProvider_register_end.php

        } catch (\Throwable $e) {
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . self::class . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if ((\DEBUG ?? 0) >= 2) throw $e;
        }
    }

    public function boot(\Framework\Core\Container $container): void
    {
        // nothing
    }
}
