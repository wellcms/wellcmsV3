<?php

// 应用基础配置

return [
    // 应用标识与版本
    'name' => 'WellCMS',
    'version' => '3.0',
    'static_version' => '?3.0',
    'logo_mobile_url' => 'img/logo.png', // 手机 LOGO URL
    'logo_pc_url' => 'img/logo.png', // PC  LOGO URL
    'logo_water_url' => 'img/water-small.png', // 水印 LOGO URL

    // 站点访问级别 0: 站点关闭; 1: 管理员可读写; 2: 会员可读;  3: 会员可读写; 4：所有人只读; 5: 所有人可读写
    'runlevel' => 5,
    'runlevel_reason' => 'The site is under maintenance, please visit later.',

    'sitename' => 'WellCMS 3.0',
    'title' => 'WellCMS 3.0 - 内容引擎的未来',
    'auth_key' => '',
    'domain' => '', // 域名
    'path' => '/', // 前台路径 "/" 结尾
    'view_url' => '/views/',
    'tmp_path' => './storage/tmp/',
    'admin_bind_ip' => 1, // 后台是否绑定 IP 0关闭 1启用
    'cdn_on' => 0,
    'api_on' => 0, // 默认关闭，打开后页面可通过api获取数据
    'apiKey' => '', // api key
    'auth_sign_up_on' => 1, // 注册账号：0关闭 1开启
    'signIn_by_username' => 1, // 用户名登录：0关闭 1开启
    'verify_email_on' => 0, // 邮箱验证：0关闭 1开启
    'signIn_by_code' => 0, // 邮箱验证码登录，和用户名登录同时开启，优先使用邮箱验证登录 / verify_email_on 和 signIn_by_code 同时开启
    'compress' => 1, // 代码压缩 0关闭 1仅压缩php、html代码(不压缩js代码) 2压缩全部代码 如果启用压缩出现错误，请关闭，删除html中的所有注释，并且js代码按照英文分号结束的地方加上分号;
    // token验证，开启后内容提交和上传都需要token，没有token无法操作，app建议开启，有效阻止抓包伪造提交等。开启后相当于单线程，仅限当前页有效。
    'login_only' => 0, // 单点登录 0关闭 1开启
    'login_ip' => 0, // 验证IP 0关闭 1开启
    'login_ua' => 0, // 验证UA 0关闭 1开启
    'thumbnail_width' => 400, // 缩略图宽度
    'thumbnail_height' => 280, // 缩略图高度
    /**
     * URL 风格（0～3）
     * 支持多种 URL 格式：
     * 0: ?user-home-1.html
     * 1: user-home-1.html (0~1不推荐，不支持URL加密)
     * 2~3 支持多国语言前缀，需按照多国语言插件
     * 2: /user/home/1.html
     * 3: /user/home/1
     */
    'url_rewrite_on' => 2,
    'link_secret' => '123456', // URL 加密密钥，严禁修改，否则URL会改变，影响SEO
    // 当使用非默认语言时，是否在 URL 前缀语言代码，false=不启用
    'url_language_prefix' => true,
    'max_file_size' => 5 * 1024 * 1024, // 5MB

    // 外链安全跳转配置
    'external_link_redirect_enabled' => 1, // 0=关闭 1=开启（forum 模块默认启用，page/article/store 需在 external_link_modules 中手动添加）
    'external_link_modules' => ['forum'], // 启用外链处理的模块列表
    'external_link_whitelist' => '', // 外链白名单域名（逗号分隔）

    /**
     * 模板错误处理策略
     */
    'template_error_policy' => [
        // 是否将模板执行异常写入应用日志（推荐生产开启）
        'log_render_errors' => true,

        // 模板渲染失败时的 HTTP 状态码；false 表示保持现有 200 行为
        'error_status_code' => 500,

        // 是否在生产环境显示简化错误信息（非调试模式仍不暴露堆栈）
        'show_public_message' => true,
    ],

    /**
     * 错误处理与调试策略
     */
    'error_handling' => [
        // 生产环境默认提示语
        'generic_message' => 'Internal Server Error',

        // 业务错误渲染器：theme | fallback
        'business_renderer' => 'theme',
    ],

    /**
     * 请求追踪 ID 配置
     */
    'request_id' => [
        'header_name' => 'X-Request-Id',
        'enabled'     => true,
    ],

    /**
     * 限流调试诊断
     */
    'throttle_diag' => [
        // DEBUG > 0 时是否在业务错误响应中附加 {diagnostic:{reason,stage}}
        'enabled_in_debug' => true,
    ],
];