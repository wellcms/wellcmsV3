<?php
// 缓存驱动配置
// [多站点隔离] 同一套代码部署多个站点时，设置环境变量 WELLCMS_SITE_ID 区分前缀
// Nginx 示例: fastcgi_param WELLCMS_SITE_ID siteA;
// Swoole 示例(Supervisor): environment=WELLCMS_SITE_ID=siteA
// 单站点无需设置
$siteId     = getenv('WELLCMS_SITE_ID');
$sitePrefix = $siteId !== false ? $siteId . '_' : '';

return [
    'site_id' => $siteId !== false ? $siteId : '',

    // 默认使用 MySQL 降级缓存
    //'default' => 'mysql',

    // 各驱动配置，使用哪个在根目录/config目录下配置哪个，如果配置多个缓存，会操作按照配置顺序操作多个。一般缓存组合本地Apcu/YAC(二选一)，集群远程Redis/memcached(二选一)
    'stores' => [
        /* 'yac'      => ['cachepre' => 'wellcms:yac_'],
        'apcu'     => ['cachepre' => 'wellcms:apc_'],
        'redis'    => [
            'host'          => '127.0.0.1',
            'port'          => 6379,
            'timeout'       => 1.0,
            'password'      => '',
            'dbname'        => 0,
            'persistent_id' => 'wellcms:app_redis', // php-redis 的持久化 ID
            'cachepre'      => 'wellcms:redis_',
            'pool_size'     => 10,           // 自定义的连接池大小
        ],
        'memcached' => [
            'servers'       => [
                ['host' => '127.0.0.1', 'port' => 11211],
            ],
            'persistent_id' => 'app_mem',
            'cachepre'      => 'wellcms:memcached_',
            'pool_size'     => 10,
        ], */],

    'cache_ttl' => 7200, // 缓存生命期
    'ip_ttl' => 7200, // IP黑白名单缓存生命期，大站设置阈值为600减少缓存压力。
    'user_ttl' => 7200, // 用户缓存生命期
    //'base_ttl' => 1800, // 基础缓存数据1800秒，非频繁变更数据
    //'title_ttl' => 1800, // 标题缓存数据1800秒，容易出现变更的数据
    //'config_ttl' => 7200, // 配置缓存数据1800秒
];
