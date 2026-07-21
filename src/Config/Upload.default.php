<?php

// 上传附件配置

return [
    //'upload_url' => 'upload/', // 本地文件上传目录，
    'upload_temp' => '/storage/upload/temp/', // 临时缓存文件目录，存放分片文件(业务流：上传 -> Temp表（文件在upload_temp目录） -> 用户提交（复制到upload目录） -> file_storage表 & attachment表，生成一个 file_storage.id)
    //'upload_path' => '/storage/upload/', // 物理路径，可以用 NFS 存入到单独的文件服务器
    'attach_dir_save_rule' => 'Ym', // 附件存放规则，附件多用：Ymd，附件少：Ym
    'upload_size' => 20, // 上传文件大小 20M ，文件过大会导致超时
    'max_file_size' => 50 * 1024 * 1024, // 最大文件50MB
    // 任务调度器开关
    'scheduler_enable' => 0,
    // 是否清理图片EXIF信息（保护隐私），必须开启任务调度器开关，同时将任务调度期加入到系统进程
    'clean_exif' => 0,

    // 需要审核的文件类型（敏感内容）
    'require_review' => [
        'application/x-msdownload', // exe
        'application/x-sh',         // sh
        'application/x-bat',        // bat
        'application/x-php',        // php
        'text/html',                // html
        'text/javascript',          // js
    ],

    // 文件下载设置
    'download' => [
        'expire' => 24 * 3600, // 下载链接有效期（秒）
        'count_limit' => 100,  // 单个文件最多下载次数
    ],

    'allowed_ext' => [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'bmp',
        'webp',
        'svg',
        'tiff',
        'tif',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'json',
        'html',
        'css',
        'js',
        'zip',
        'rar',
        '7z',
        'gz',
        'tgz',
        'tar.gz',
        'tar',
        'avi',
        'mp4',
        'wmv',
        'mov',
        'mkv',
        'webm',
        'apk',
        'ipa',
        'deb',
        'rpm'/* , 'exe', 'dll', 'com', 'bat', 'msi' */,
        'dmg',
        'iso'
    ],

    // 针对 well_group 表 allowed_file_types 字段(数字型)的预设组映射
    // 0: 遵循全局 allowed_ext; 1-n: 动态子集
    'type_presets' => [
        1 => ['jpg', 'jpeg', 'png', 'gif', 'webp'], // 准图床组
        2 => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'], // 文档兼容组
        3 => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'pdf', 'doc', 'docx', 'txt'], // 开发者/全能组
    ],

    // 全局默认配额 (当用户组配额设为 0 时，启用以下兜底限制)
    'limit_defaults' => [
        'upload_daily_quota'       => 50,                   // 每日上传文件总数
        'upload_per_post'          => 10,                   // 单场发布允许上传数
        'quota_daily_size_default' => 200 * 1024 * 1024,    // 每日累计总容量 (200MB)
        'quota_single_size_default' => 20 * 1024 * 1024,    // 单文件最大容量 (20MB)
    ],

    'allowed_mimes' => [
        // 视频类
        'video/avi'   => ['avi'],
        'video/mp4'   => ['mp4'],
        'video/x-ms-wmv' => ['wmv'],
        'video/quicktime' => ['mov'],
        'video/x-matroska' => ['mkv'],
        'video/webm' => ['webm'],

        // 图像类
        'image/gif'   => ['gif'],
        'image/jpeg'  => ['jpg', 'jpeg'],
        'image/png'   => ['png'],
        'image/webp'  => ['webp'],
        'image/bmp'   => ['bmp'],
        'image/svg+xml' => ['svg'],
        'image/tiff' => ['tiff', 'tif'],

        // 文档类
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt'],
        'application/json' => ['json'],
        'text/json' => ['json'],
        'text/html' => ['html'],
        'text/css' => ['css'],
        'application/javascript' => ['js'],
        'text/javascript' => ['js'],

        // 压缩包类
        'application/zip' => ['zip'],
        'application/x-rar-compressed' => ['rar'],
        'application/x-7z-compressed' => ['7z'],
        'application/x-gzip' => ['gz', 'tgz', 'tar.gz'],
        'application/x-tar' => ['tar'],

        // App类 (移动端安装包)
        'application/vnd.android.package-archive' => ['apk'],
        'application/x-ios-app' => ['ipa'],

        // 系统安装包及镜像类
        'application/x-debian-package' => ['deb'],
        'application/x-redhat-package-manager' => ['rpm'],
        // 根据自己的需要开启，删除注释即可，但需要配合启用 ClamAV 安全扫描，对于二进制安装包，这是唯一的识别恶意软件的手段。
        //'application/x-msdownload' => ['exe', 'dll', 'com', 'bat', 'msi'],
        'application/x-apple-diskimage' => ['dmg'],
        'application/x-iso9660-image' => ['iso'],
    ],

    'allowed_image_mimes' => [
        // 图像类
        'image/gif'   => ['gif'],
        'image/jpeg'  => ['jpg', 'jpeg'],
        'image/png'   => ['png'],
        'image/webp'  => ['webp'],
        'image/bmp'   => ['bmp'],
        'image/svg+xml' => ['svg'],
        'image/tiff' => ['tiff', 'tif'],
    ],

    // 默认驱动：修改这里即可切换存储方式 ('local', 'aliyun', 'aws', 'cloudflare', 'backblaze')
    'default' => 'local',
    'local_file' => 0, // 上传云储存后本地文件 0:保留 1:删除

    // 各驱动的具体配置
    'disks' => [
        'local' => [
            'root'  => '/storage/upload/', // 本地文件上传目录，物理路径，可以用 NFS 存入到单独的文件服务器
            'url'   => '/upload/', // 前端文件链接
        ],

        /* // ----------------------------------------------------------------
        // 1. 阿里云 OSS (Alibaba Cloud Object Storage Service)
        // 使用独立的 OssStorage 驱动，因为其 Header 签名方式与标准 S3 略有不同
        // ----------------------------------------------------------------
        'aliyun' => [
            'driver'        => OssStorage::class,
            'access_id'     => 'LTAI5t7.......',           // RAM 用户 AccessKey ID
            'access_secret' => 'F23dsk.......',            // RAM 用户 AccessKey Secret
            'bucket'        => 'my-wellcms-oss',           // Bucket 名称
            'endpoint'      => 'oss-cn-shanghai.aliyuncs.com', // 地域节点 (不带 bucket 前缀)
            'is_cname'      => false,                      // 如果 endpoint 是自定义域名，设为 true
            'url'       => 'https://oss.example.com',  // (可选) 绑定的 CDN 加速域名，用于生成访问 URL
            'use_ssl'       => true,
        ],

        // ----------------------------------------------------------------
        // 2. AWS S3 (Amazon Simple Storage Service)
        // S3 协议的原生实现
        // ----------------------------------------------------------------
        'aws' => [
            'key'      => 'AKIAIOSFODNN7EXAMPLE',      // Access Key ID
            'secret'   => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', // Secret Access Key
            'region'   => 'us-east-1',                 // 区域代码
            'bucket'   => 'my-wellcms-aws',
            'endpoint' => null,                        // AWS 原生不需要填，驱动会自动生成 s3.region.amazonaws.com
            'url'      => 'https://my-wellcms-aws.s3.amazonaws.com', // 用于生成公网访问链接
        ],

        // ----------------------------------------------------------------
        // 3. Cloudflare R2
        // 完全兼容 S3 API，且无出口流量费。
        // 注意：R2 的 Endpoint 格式必须包含 Account ID。
        // ----------------------------------------------------------------
        'cloudflare' => [
            'key'      => '324000.......',             // R2 Access Key ID
            'secret'   => 'e4000000.......',           // R2 Secret Access Key
            'region'   => 'auto',                      // R2 对 region 不敏感，通常填 'auto' 或 'us-east-1'
            'bucket'   => 'my-wellcms-r2',
            // 关键：R2 的 Endpoint 格式为 https://<account_id>.r2.cloudflarestorage.com
            'endpoint' => 'https://<your_account_id>.r2.cloudflarestorage.com',
            // R2 Bucket 默认不公开，必须绑定自定义域名或开启 R2.dev 才能公开访问
            'url'      => 'https://r2.example.com',
        ],

        // ----------------------------------------------------------------
        // 4. Backblaze B2
        // 兼容 S3 API，性价比较高。
        // 需要在 B2 后台创建一个 "Application Key" (推荐不要用 Master Key)。
        // ----------------------------------------------------------------
        'backblaze' => [
            'key'      => '004a.........',             // keyID (App Key ID)
            'secret'   => 'K004.........',             // applicationKey (Secret)
            'region'   => 'us-west-004',               // 在 Bucket 设置中查看，如 us-west-004
            'bucket'   => 'my-wellcms-b2',
            // 关键：B2 Endpoint 格式，如 s3.us-west-004.backblazeb2.com
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'url'      => 'https://f004.backblazeb2.com/file/my-wellcms-b2', // B2 的公共访问 URL 结构不同
        ], */
    ],

    'content_check_depth' => 256,
    // 扩展检查 YARA/ClamAV 路径
    'clamav_socket' => '/var/run/clamav/clamd.ctl',
    'ttl' => 6 * 3600, // 上传会话有效期，单位秒
    'logging_enabled' => true,
    'logging_level' => 'info',

    // 临时文件自动清理
    'temp_cleanup' => [
        'min_age' => 3600,
        'default_max_age' => 21600,
        'chunk_max_age' => 21600,
        'namespace_whitelist' => [],
        'whitelist_max_age' => 86400,
        'draft_lookup_days' => 30,
        'draft_lookup_limit' => 500,
        'gc_batch_size' => 20,
        'scheduler_batch_size' => 200,
        'gc_probability' => 0.01,
        'gc_min_interval' => 3600,
    ],
];