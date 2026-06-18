<?php
// 会话配置
return [
    // 生命周期（秒）
    'ttl' => 1800,
    'pre' => 'well_', // cookie 前缀
    'cookie_domain' => '',   // cookie使用的域名，为空表示当前域名
    'cookie_path' => '/',     // 为空则表示当前目录和子目录
    'cookie_lifetime' => 8640000, // cookie生命期8640000为100天
    'cookie_secure' => true, // 在生产环境中，如果站点使用HTTPS，应该将此设置为true
    'cookie_samesite' => 'Lax', // None | Lax | Strict，跨站点请求时是否携带 Cookie，防 CSRF 攻击，默认 Lax，None 需要配合 cookie_secure = true 使用。
    'httponly' => true, // 打开后 js 获取不到 HTTP 设置的 cookie, 有效防止 XSS，对于安全很重要，除非有 BUG，否则不要关闭。
    'cache_sessionsData_expire' => 21600, // 缓存session > 255的数据生命期。
    'online_update_span' => 120, // 在线更新频度，大站设置的长一些。
    'online_hold_time' => 1800,  // 在线的时间，不建议调整。
    'delay_update' => 30, // 开启 session 延迟更新，减轻压力，会导致不重要的数据(useragent,url)显示有些延迟，单位为秒。
    'gc_recycle_time' => 600, // 10 分钟清理一次在线数据
    'gc_divisor' => 1000, // 垃圾回收时间 5 秒，在线人数 * 10 / 每1000个请求回收一次垃圾。在线压力大的时候降低值。
    'session_write_cache_on' => false, // true:启用，长期在线超过5k可开启，减少数据库压力，需配合开启缓存，最好是组合缓存YAC本机 / Redis 远程缓存主机。

    // 限流功能，需要缓存支持。
    'rate_limit' => [
        'enable' => true, // true:Enable / false:Closed
        'session' => [
            'limit'  => 10, // 同一 session 每分钟 >= 10 次触发IP黑名单的请求数阈值
            'window' => 60, // 时间窗口：60 秒
            'threshold' => 2, // 同一用户 IP 频繁变动，视为非正常访问
        ],
        'ip' => [
            'limit'  => 10, // 同一 IP 每分钟最多 >= 10 次触发IP黑名单的请求数阈值
            'window' => 60,
        ],
        /**
         * 限流中间件的“并发瞬时峰值”主要依据服务器的以下配置推算：
         * CPU核心数和性能：决定了并发处理的上限，CPU越多、性能越强，并发峰值可设越高。
         * 内存大小：每个并发请求都要消耗内存，内存越大，可承载并发越多。
         * 带宽：网卡和链路带宽直接影响处理高并发大流量请求的能力。
         * 系统最大连接数（ulimit、内核参数如net.core.somaxconn）：直接决定TCP连接上限，太低会直接拒绝新连接。
         * 服务进程/线程模型：比如Nginx、Tomcat有自己的worker数、连接池等，也决定并发上限。
         *
         * CPU + 内存 + 带宽 + 最大连接数，哪个先顶不住，capacity 参数就设多大。
         *
         * 用压力测试工具（ab、wrk、jmeter）实际打压，找到服务性能拐点，再留30%裕度，就是合理“并发瞬时峰值”。
         *
         * 2H1G / 4H2G内存的中小站点，主要受限于带宽
         *
         * 1Mbps = 1,000,000 bit/s = 125,000 Byte/s = 125KB/s。
         * 最大QPS = 带宽(bit/s) ÷ 单请求平均(bit)
         *
         * 假设单个请求平均返回40KB数据：40KB × 8 = 320,000 bit
         *
         * 最大QPS：1,000,000 ÷ 320,000 ≈ 3.1（每秒最多3个并发成功响应）
         */
        'cache' => [
            // 需配置 YAC 或 APCU 任意一个缓存，小站点仅需要配置默认项即可，大型站点需要配置 Memcached 和 Redis 等3层缓存限流。
            // 瞬时峰 10，每秒补 5，如果桶中无令牌可用则限流。
            'default' => [
                'capacity' => 5, // 并发瞬时峰值，服务器带宽承载并发数的 1/2
                'rate' => 2 //  每秒补充
            ],
            // 需配置 Memcached 缓存，大站建议分配到其他服务器。
            'memcached' => ['capacity' => 50, 'rate' => 10],
            // 需配置 Redis 缓存，大站建议分配到其他服务器。
            'redis' => ['capacity' => 100, 'rate' => 10],
        ],
        'cookie' => [
            'limit'  => 2, // 客户端连续不带 Cookie ≥ 2次 判定采集
            'window' => 120, // 时间窗口期120秒
        ],
        'verify_robot' => true, // 验证是否为搜索引擎爬虫，true 验证 false 关闭，可防采集
        'verify_dns' => false, // 验证DNS更精确：true 验证 false 关闭，可防采集,因网络原因，性能会降低，但不会有太大影响，如果遭受很严重的蜘蛛采集可短暂打开，获取的IP将入池，后续后越来越快
        // URL 前缀放行，需要放行的前缀URL自行添加，如:admin-user-list.html / my-home.html
        'url_prefix_allow' => ['admin', 'my'],
        // 路径白名单
        'path_whitelist' => [
            'app/views',
            'plugins',
            'themes',
            'storage/upload',
            'static'
        ],
        // 静态资源白名单
        'static_ext' => ['css' => 1, 'js' => 1, 'png' => 1, 'jpg' => 1, 'jpeg' => 1, 'gif' => 1, 'svg' => 1, 'woff' => 1, 'woff2' => 1],
        // Content-Type白名单
        'content_type_whitelist' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/svg+xml',
            'image/webp',
            'text/css',
            'application/javascript',
            'font/woff',
            'font/woff2',
        ],
        // 注释 'enable' = false 禁止爬虫索引站点内容
        'bot_list' => [
            // Googlebot - 美国 · 全球首选
            'googlebot' => ['enable' => true, 'host' => ['.google.com', '.googlebot.com']],
            // Bingbot - 美国 · 微软搜索首选
            'bingbot' => ['enable' => true, 'host' => ['.bing.com', '.search.msn.com']],
            // Baiduspider - 中国 · 百度首选
            'baiduspider' => ['enable' => true, 'host' => ['.baidu.com', '.baiduspider.com']],
            // Sogou web spider - 中国 · 搜狗首选
            'sogou web spider' => ['enable' => true, 'host' => ['.sogou.com', '.sogoucdn.com']],
            // YandexBot - 俄罗斯 · 俄罗斯首选
            'yandexbot' => ['enable' => false, 'host' => ['.yandex.com', '.yandex.ru']],
            // DuckDuckBot - 美国 · 隐私搜索首选
            'duckduckbot' => ['enable' => false, 'host' => '.duckduckgo.com'],
            // Exabot - 法国 · 法国首选
            'exabot' => ['enable' => false, 'host' => '.exabot.com'],
            // SeznamBot - 捷克 · 捷克首选
            'seznambot' => ['enable' => false, 'host' => '.seznam.cz'],
            // Naverbot - 韩国 · 韩国首选
            'naverbot' => ['enable' => false, 'host' => '.naver.com'],
            // Yahoo Slurp - 日本/美国 · 雅虎搜索
            'yahoo slurp' => ['enable' => false, 'host' => ['.yahoo.com', '.yahoo.co.jp']],
            // Qwantify – 法国 · 隐私搜索（非主流，可屏蔽）
            'qwantify' => ['enable' => false, 'host' => '.qwant.com'],
            // MojeekBot  – 英国 · 隐私独立搜索（使用量极低，可屏蔽）
            'mojeekbot' => ['enable' => false, 'host' => '.mojeek.com'],
            // Gigabot – 美国 · 通用爬虫（已很少更新，可屏蔽）
            'gigabot' => ['enable' => false, 'host' => '.gigablast.com'],
            // AhrefsBot – 美国 · SEO 分析首选（仅SEO工具抓取，可屏蔽）
            'ahrefsbot' => ['enable' => false, 'host' => '.ahrefs.com'],
            // SemrushBot – 美国 · SEO 分析首选（仅SEO工具抓取，可屏蔽）
            'semrushbot' => ['enable' => false, 'host' => '.semrush.com'],
            // Majestic-12 – 英国 · 链接分析首选（仅SEO工具抓取，可屏蔽）
            'majestic-12' => ['enable' => false, 'host' => '.majestic.com'],
            // CCBot – 德国 · 隐私搜索/通用抓取（可屏蔽）
            'ccbot' => ['enable' => false, 'host' => '.commoncrawl.org'],
            // ArchiveBot – 美国 · 网络归档（非搜索引擎，可屏蔽）
            'archivebot' => ['enable' => false, 'host' => '.archive.org'],
            // Applebot – 美国 · Siri 与 Spotlight 索引
            'applebot' => ['enable' => false, 'host' => '.apple.com'],
            // SISTRIX Crawler  – 德国 · SEO 工具（仅SEO工具抓取，可屏蔽）
            'sistrix crawler' => ['enable' => false, 'host' => '.sistrix.net'],
            // 英国SEO分析工具（仅SEO工具抓取，可屏蔽）
            'mj12bot' => ['enable' => false, 'host' => '.mj12bot.com'],
            // 360
            '360spider' => ['enable' => true, 'host' => '.so.com'],
            // 一搜
            'yisouspider' => ['enable' => true, 'host' => '.sm.com'],
            // 头条（可屏蔽）
            'bytespider' => ['enable' => false, 'host' => '.toutiao.com'],
            // 华为（可屏蔽）
            'aspiegelbot' => ['enable' => false, 'host' => '.aspiegel.com'],
            // 美国信息安全相关（可屏蔽）
            'censys' => ['enable' => false, 'host' => '.censys.io'],
            // 一个黑客相关的搜索
            'leakix' => ['enable' => false, 'host' => 'leakix.net'],
            'meta' => ['enable' => false, 'host' => '.facebook.com'],
        ],
        // 最后一道防护，爬虫特征识别，不区分大小写。
        'bots' => [
            'wget',
            'curl',
            'scrapy',
            'python',
            'python-requests',
            'phpcrawl',
            'go-http-client',
            'http-client',
            'httpclient',
            'phantomjs',
            'scan',
            'meta-externalagent'
        ],
        'verify_blacklist' => false, // 验证IP黑名单（初期无需开启此功能）
        'add_to_blacklist' => true, // 加入IP黑名单（默认，上面功能全部开启后，会创建黑名单）
    ],
];
