<?php
/**
 * WellCMS 3.0 数据库配置文件
 *
 * 本配置支持以下核心能力：
 * 1. 一主多从读写分离（自动路由读操作到从库，写操作到主库）
 * 2. FPM / Swoole 协程 双模连接池（自动识别运行环境）
 * 3. 多种负载均衡策略（随机、轮询、加权随机、加权轮询、最少连接数）
 * 4. 分布式分片（一致性哈希路由，支持分表分库）
 * 5. 多驱动兼容（MySQL / PostgreSQL / SQLite / SQLServer / Oracle）
 *
 * 关键概念说明：
 * - driver        : 默认激活的数据库驱动标识，必须对应 connections 中的某个键
 * - connections   : 各驱动的连接参数集合，每个驱动包含 master（主库）和 slaves（从库数组）
 * - pool          : 连接池全局配置，分 fpm（阻塞模式）和 coroutine（协程模式）两套参数
 * - sharding      : 分片规则，按表名配置 shard_key 和多个分片节点
 *
 * 注意：本文件修改后需重启 Swoole Worker / FPM 进程方可生效。
 */

return [
    /*
     * ------------------------------------------------------------------------
     * 默认驱动标识
     * ------------------------------------------------------------------------
     * 必须与下方 connections 数组中的某个键名一致。
     * 切换驱动时只需修改此处，无需改动业务代码。
     * ------------------------------------------------------------------------
     */
    'driver' => 'pgsql',

    /*
     * ------------------------------------------------------------------------
     * 全局表前缀
     * ------------------------------------------------------------------------
     * 所有 SQL 构造器会自动附加此前缀。
     * 例如 prefix='well_'，则查询 user 表时实际操作 well_user。
     * ------------------------------------------------------------------------
     */
    'prefix' => 'well_',

    /*
     * ------------------------------------------------------------------------
     * 各数据库驱动连接参数
     * ------------------------------------------------------------------------
     * 每个键对应一种驱动，支持同时配置多个驱动（虽然运行时只能激活一个）。
     *
     * 单节点配置字段说明：
     * - host         : 数据库服务器地址（IPv4 / IPv6 / Unix Socket / 域名）
     * - port         : 端口号，不同驱动有默认值（MySQL=3306, PgSQL=5432, SQLServer=1433, Oracle=1521）
     * - database     : 数据库名 / SID / Service Name
     * - username     : 连接账号
     * - password     : 连接密码
     * - charset      : 客户端字符集（MySQL=utf8mb4, PgSQL=UTF8, Oracle=AL32UTF8）
     * - collation    : 校对集，仅部分驱动有效（如 MySQL）
     * - engine       : 默认存储引擎提示（如 MySQL 的 InnoDB）
     * - persistent   : true=PDO 持久连接，false=短连接（推荐生产环境使用连接池+false）
     * - timeout      : 连接超时秒数，建议 ≤5s
     * - schema       : PostgreSQL 的 search_path，或 Oracle 的 Schema（可选）
     * - path         : SQLite 数据库文件路径（仅限 sqlite 驱动）
     */
    'connections' => [
        /*
         * MySQL 配置示例（取消注释即可启用）
         */
        /* 'mysql' => [
            'master' => [
                'host'       => '127.0.0.1',
                'port'       => '3306',
                'database'   => 'wellcms',
                'username'   => 'wellcms',
                'password'   => '123456',
                'charset'    => 'utf8mb4',
                'collation'  => 'utf8mb4_unicode_ci',
                'prefix'     => 'well_',
                'engine'     => 'InnoDB',
                'persistent' => false,
                'timeout'    => 5,
            ],
            'slaves' => [
                // 从库 1：权重 3，标签 read
                [
                    'host'       => '192.168.1.11',
                    'port'       => '3306',
                    'database'   => 'wellcms',
                    'username'   => 'wellcms_read',
                    'password'   => 'read_pass',
                    'charset'    => 'utf8mb4',
                    'collation'  => 'utf8mb4_unicode_ci',
                    'prefix'     => 'well_',
                    'persistent' => false,
                    'timeout'    => 5,
                    'weight'     => 3,
                    // 'tags'       => ['read'], // 未实现标签路由逻辑，如果你不需要为将来预留元数据，可以直接省略 tags 字段，或保持为空数组
                ],
                // 从库 2：权重 1，标签 read,bak
                [
                    'host'       => '192.168.1.12',
                    'port'       => '3306',
                    'database'   => 'wellcms',
                    'username'   => 'wellcms_read',
                    'password'   => 'read_pass',
                    'charset'    => 'utf8mb4',
                    'collation'  => 'utf8mb4_unicode_ci',
                    'prefix'     => 'well_',
                    'persistent' => false,
                    'timeout'    => 5,
                    'weight'     => 1,
                    'tags'       => ['read', 'bak'],
                ],
            ],
        ], */

        /*
         * PostgreSQL 配置示例（当前默认激活）
         */
        'pgsql' => [
            'master' => [
                'host'       => '127.0.0.1',
                'port'       => '5432',
                'database'   => 'wellcmsv3',
                'username'   => 'wellcmsv3',
                'password'   => '123456',
                'charset'    => 'UTF8',
                'collation'  => '',
                'prefix'     => 'well_',
                'engine'     => '',
                'persistent' => false,
                'timeout'    => 5,
                'schema'     => 'public',
            ],
            'slaves' => [
                // 从库节点示例（取消注释并填写真实地址后生效）
                /* [
                    'host'       => '192.168.1.21',
                    'port'       => '5432',
                    'database'   => 'wellcmsv3',
                    'username'   => 'wellcmsv3_read',
                    'password'   => 'read_pass',
                    'charset'    => 'UTF8',
                    'prefix'     => 'well_',
                    'persistent' => false,
                    'timeout'    => 5,
                    'weight'     => 2,
                    // 'tags'       => ['read'], // 未实现标签路由逻辑，如果你不需要为将来预留元数据，可以直接省略 tags 字段，或保持为空数组
                ], */
            ],
        ],

        /*
         * SQLite 配置示例（仅限单节点、开发/测试环境）
         */
        /* 'sqlite' => [
            'master' => [
                'driver'     => 'sqlite',
                'path'       => APP_PATH . 'storage/database/wellcms.sqlite',
                'prefix'     => 'well_',
                'persistent' => false,
            ],
            'slaves' => [],
        ], */
    ],

    /*
     * ------------------------------------------------------------------------
     * 连接池全局配置
     * ------------------------------------------------------------------------
     * 系统在运行时自动判断环境：
     * - Swoole 协程环境（\Swoole\Coroutine::getCid() > 0）=> 使用 coroutine 配置
     * - FPM / CLI 阻塞环境 => 使用 fpm 配置
     *
     * 配置项说明：
     * - enabled                : 是否启用连接池
     * - min_connections        : 池中最小保活连接数
     * - max_connections        : 池中最大连接数（建议 FPM 下 ≥ max_children）
     * - timeout                : 获取连接最大等待秒数（FPM 下建议 ≤3s，避免 502/504）
     * - threshold              : 告警阈值，连接数达到此值触发 monitor() 日志告警
     * - adjust_threshold       : 自动扩缩容利用率阈值（0.0~1.0），仅 FPM 模式生效
     * - load_balancer          : 负载均衡器类名，影响从库选择策略
     * - health_check_interval  : 协程池健康检查定时器周期（毫秒），仅 coroutine 模式生效
     */
    'pool' => [
        'fpm' => [
            'enabled'           => true,
            'min_connections'   => 2,
            'max_connections'   => 8,
            'timeout'           => 3,
            'threshold'         => 20,
            'adjust_threshold'  => 0.75,
            'load_balancer'     => \Framework\Database\Pool\LoadBalancer\WeightedRandomLoadBalancer::class,
        ],
        'coroutine' => [
            'enabled'               => true,
            'min_connections'       => 2,
            'max_connections'       => 16,
            'timeout'               => 5,
            'health_check_interval' => 60000,
            'load_balancer'         => \Framework\Database\Pool\LoadBalancer\LeastConnectionsLoadBalancer::class,
        ],
    ],

    /*
     * ------------------------------------------------------------------------
     * 分片规则配置（分库分表）
     * ------------------------------------------------------------------------
     * 支持按表维度配置一致性哈希分片。
     * 系统在启动时通过 ConfigServiceProvider 自动将本配置扁平化为：
     * - dbConfig['shards']       : 各 shard 节点的 master/slave 连接参数
     * - dbConfig['shard_routers'] : 分片路由元信息
     *
     * 字段说明：
     * - shard_key    : 分片键，通常为业务主键（如 user_id）。驱动会根据该字段值计算哈希。
     * - nodes        : 分片节点映射，键为 nodeId（如 shard_0, shard_1），值为该节点的连接参数。
     *                  每个节点内部必须包含 master，可选 slaves。
     *
     * 读写分离行为：
     * - 写操作（insert/update/delete）自动路由到该分片节点的 master
     * - 读操作（query/count/queryOne）自动路由到该分片节点的 slaves（若无从库则回退 master）
     * - 批量操作（bulkInsert/bulkUpdate）要求所有数据落在同一分片，否则抛出异常
     */
    'sharding' => [
        'default_strategy' => 'consistent_hash',
        'tables' => [
            /*
             * 示例：用户表按 user_id 分 4 片
             * 取消注释并配置真实节点后生效
             */
            /* 'user' => [
                'shard_key' => 'user_id',
                'nodes' => [
                    'shard_0' => [
                        'master' => [
                            'host'     => '10.0.0.11',
                            'port'     => '5432',
                            'database' => 'wellcms_shard_0',
                            'username' => 'wellcms',
                            'password' => 'pass',
                            'charset'  => 'UTF8',
                            'prefix'   => 'well_',
                        ],
                        'slaves' => [
                            [
                                'host'     => '10.0.0.11',
                                'port'     => '5432',
                                'database' => 'wellcms_shard_0',
                                'username' => 'wellcms_read',
                                'password' => 'read_pass',
                                'charset'  => 'UTF8',
                                'prefix'   => 'well_',
                                'weight'   => 1,
                            ],
                        ],
                    ],
                    'shard_1' => [
                        'master' => [
                            'host'     => '10.0.0.12',
                            'port'     => '5432',
                            'database' => 'wellcms_shard_1',
                            'username' => 'wellcms',
                            'password' => 'pass',
                            'charset'  => 'UTF8',
                            'prefix'   => 'well_',
                        ],
                        'slaves' => [],
                    ],
                    'shard_2' => [
                        'master' => [
                            'host'     => '10.0.0.13',
                            'port'     => '5432',
                            'database' => 'wellcms_shard_2',
                            'username' => 'wellcms',
                            'password' => 'pass',
                            'charset'  => 'UTF8',
                            'prefix'   => 'well_',
                        ],
                        'slaves' => [],
                    ],
                    'shard_3' => [
                        'master' => [
                            'host'     => '10.0.0.14',
                            'port'     => '5432',
                            'database' => 'wellcms_shard_3',
                            'username' => 'wellcms',
                            'password' => 'pass',
                            'charset'  => 'UTF8',
                            'prefix'   => 'well_',
                        ],
                        'slaves' => [],
                    ],
                ],
            ], */

            /*
             * 示例：订单表按 order_id 分 2 片
             */
            /* 'order' => [
                'shard_key' => 'order_id',
                'nodes' => [
                    'shard_0' => [
                        'master' => ['host' => '10.0.0.21', 'port' => '5432', 'database' => 'wellcms_order_0', 'username' => 'wellcms', 'password' => 'pass', 'charset' => 'UTF8', 'prefix' => 'well_'],
                        'slaves' => [],
                    ],
                    'shard_1' => [
                        'master' => ['host' => '10.0.0.22', 'port' => '5432', 'database' => 'wellcms_order_1', 'username' => 'wellcms', 'password' => 'pass', 'charset' => 'UTF8', 'prefix' => 'well_'],
                        'slaves' => [],
                    ],
                ],
            ], */
        ],
    ],
];
