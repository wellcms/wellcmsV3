<?php
if (!defined('IN_WELLCMS')) exit();

/**
 * 环境检测
 */
function check_environment()
{
    $results = [];

    // PHP 版本
    $results['php_version'] = [
        'name' => 'PHP Version',
        'required' => '7.2.0+',
        'current' => PHP_VERSION,
        'status' => version_compare(PHP_VERSION, '7.2.0', '>=')
    ];

    $extensions = [
        'pdo_mysql' => 'PDO MySQL (Required for MySQL)',
        'pdo_pgsql' => 'PDO PostgreSQL (Required for PgSQL)',
        'mbstring'  => 'MBString',
        'json'      => 'JSON',
        'fileinfo'  => 'FileInfo',
        'intl'      => 'Intl'
    ];

    $has_db_driver = false;
    foreach ($extensions as $ext => $name) {
        $loaded = extension_loaded($ext);
        $is_db = strpos($ext, 'pdo_') === 0;
        if ($is_db && $loaded) $has_db_driver = true;

        $results['ext_' . $ext] = [
            'name' => $name,
            'required' => 'Installed',
            'current' => $loaded ? 'Installed' : 'Not Found',
            'status' => $loaded,
            'is_db_driver' => $is_db
        ];
    }

    // Force failure if no DB driver at all
    if (!$has_db_driver) {
        $results['db_driver_required'] = [
            'name' => 'Database Driver (MySQL/PgSQL)',
            'required' => 'At least one',
            'current' => 'None Found',
            'status' => false
        ];
    }

    // 建议扩展
    $results['ext_redis'] = [
        'name' => 'Redis (Recommended)',
        'required' => 'Installed',
        'current' => extension_loaded('redis') ? 'Installed' : 'Not Found',
        'status' => extension_loaded('redis'),
        'is_recommended' => true
    ];

    // 目录权限
    $dirs = [
        'config/' => APP_PATH . 'config',
        'storage/' => APP_PATH . 'storage',
        'install/' => APP_PATH . 'install'
    ];

    foreach ($dirs as $rel => $path) {
        if (!is_dir($path)) @mkdir($path, 0777, true);
        $results['dir_' . str_replace('/', '', $rel)] = [
            'name' => $rel . ' Writeable',
            'required' => 'Writeable',
            'current' => is_writable($path) ? 'Writeable' : 'Fixed/Unwriteable',
            'status' => is_writable($path)
        ];
    }

    return $results;
}

/**
 * 测试数据库连接
 */
function check_db_connection($cfg): array{
    $type = $cfg['type'] ?? 'mysql';
    try {
        if ($type === 'pgsql') {
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname=postgres";
        } else {
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4";
        }
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return ['status' => true, 'message' => 'Connection success'];
    } catch (PDOException $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 生成随机字符串
 */
function installer_random_str(int $length = 16)
{
    $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjklmnpqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * 执行安装
 */
function execute_install($data): array{
    try {
        set_time_limit(0); // 防止大型数据库导入超时
        $db = $data['db'];
        $admin = $data['admin'];

        // 0. 预检目录权限
        $target_config_dir = APP_PATH . 'config/';
        if (!is_dir($target_config_dir)) @mkdir($target_config_dir, 0777, true); // Ensure directory exists before checking writability
        if (!is_writable($target_config_dir)) {
            throw new \Exception("Directory {$target_config_dir} is not writable.");
        }
        if (!is_writable(INSTALL_PATH)) {
            throw new \Exception("Directory " . INSTALL_PATH . " is not writable.");
        }

        // 1. 创建数据库并连接
        $type = $db['type'] ?? 'mysql';
        if ($type === 'pgsql') {
            $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=postgres";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // PostgreSQL 检查数据库是否存在
            $check = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $check->execute([$db['name']]);
            if (!$check->fetch()) {
                $pdo->exec("CREATE DATABASE \"{$db['name']}\" OWNER \"{$db['user']}\"");
            }
            $pdo = null; // 断开 postgres 连接
            $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // PG 15+ 针对 public schema 权限问题的自动修复
            try {
                // 1. 尝试直接授权 (如果当前用户已经是 Owner 或超级用户)
                @$pdo->exec("ALTER SCHEMA public OWNER TO \"{$db['user']}\"");
                $pdo->exec("GRANT ALL ON SCHEMA public TO \"{$db['user']}\"");
            } catch (\PDOException $e) {
                try {
                    // 2. 如果授权失败且 schema 为空，尝试“删掉重建”
                    // 只要当前用户是数据库的 OWNER，就有权删除并重建 public schema
                    $checkEmpty = $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'");
                    if ($checkEmpty->fetchColumn() == 0) {
                        $pdo->exec("DROP SCHEMA IF EXISTS public CASCADE");
                        $pdo->exec("CREATE SCHEMA public AUTHORIZATION \"{$db['user']}\"");
                        $pdo->exec("GRANT ALL ON SCHEMA public TO \"{$db['user']}\"");
                    }
                } catch (\PDOException $e2) {
                    // 3. 如果还是失败，说明用户权限极低，只能提示手动执行
                    // 保持原样，尝试继续，通常会触发后面的 CREATE TABLE 错误
                }
            }
        } else {
            $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db['name']}`");

            // 允许在 AUTO_INCREMENT 列中插入字面量 0（well_group Guest 组）
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        }

        // 2. 导入 SQL
        $sql_file = INSTALL_PATH . 'install.sql';
        if (!file_exists($sql_file)) throw new \Exception("install.sql not found");

        $sql_content = file_get_contents($sql_file);
        if ($db['prefix'] !== 'well_') {
            $sql_content = str_replace('well_', $db['prefix'], $sql_content);
        }

        // --- 预处理：彻底移除所有注释以简化转换逻辑 ---
        $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);
        $lines = explode("\n", $sql_content);
        $clean_sql = "";
        foreach ($lines as $line) {
            $line = preg_replace('/--.*$/', '', $line); // 移除行尾注释
            $line = preg_replace('/#.*$/', '', $line);  // 移除 MySQL 风格单行注释
            $line = trim($line);
            if ($line !== '') {
                $clean_sql .= $line . "\n";
            }
        }

        if ($type === 'pgsql') {
            // --- PostgreSQL 兼容性深度转换 ---
            $clean_sql = str_replace('`', '"', $clean_sql);

            // 移除 MySQL 特有后缀
            $clean_sql = preg_replace('/ENGINE\s*=\s*\w+/i', '', $clean_sql);
            $clean_sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $clean_sql);
            $clean_sql = preg_replace('/CHARACTER\s+SET\s+\w+/i', '', $clean_sql);
            $clean_sql = preg_replace('/COLLATE\s*=\s*[\w_]+/i', '', $clean_sql);
            $clean_sql = preg_replace('/COLLATE\s+[\w_]+/i', '', $clean_sql);
            $clean_sql = preg_replace('/COMMENT\s+\'.*?\'/i', '', $clean_sql); // 移除 MySQL 字段/表注释

            // 类型映射 (注意顺序：从长到短，防止 partial match)
            $mappings = [
                // 自增主键处理 (必须最先处理)
                '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' BIGSERIAL ',
                '/\bBIGINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'                     => ' BIGSERIAL ',
                '/\bINT\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'    => ' SERIAL ',
                '/\bINT\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i'                        => ' SERIAL ',
                '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\b/i' => ' SERIAL ',

                // MySQL 特有属性清理
                '/\bUNSIGNED\b/i'            => ' ',
                '/\bZEROFILL\b/i'            => ' ',
                '/\bBINARY\b(?!\s*\(16\)|\s*\(32\))/i' => ' ', // 移除作为属性的 BINARY，保留作为类型的
                '/\bCHARACTER\s+SET\s+\w+/i'  => ' ',
                '/\bCOLLATE\s*=\s*[\w_]+/i'   => ' ',
                '/\bCOLLATE\s+[\w_]+/i'       => ' ',
                '/\bENGINE\s*=\s*\w+/i'       => ' ',
                '/\bAUTO_INCREMENT\s*=\s*\d+/i' => ' ',
                '/\bDEFAULT\s+CHARSET\s*=\s*\w+/i' => ' ',
                '/\bCOMMENT\s*=\s*\'.*?\'/i'  => ' ',
                '/\bCOMMENT\s+\'.*?\'/i'      => ' ',
                '/ON\s+UPDATE\s+CURRENT_TIMESTAMP/i' => ' ', // PgSQL 不支持列级自动更新，需剔除

                // 无符号整数溢出保护 (关键修复：MySQL INT UNSIGNED -> Postgres INTEGER)
                // 确保替换结果前后有空格，防止与 NOT NULL 粘连
                '/\bBIGINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' NUMERIC(20) ',
                '/\bINT\s*(\(\d+\))?\s+UNSIGNED\b/i'      => ' INTEGER ',
                '/\bINTEGER\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' INTEGER ',
                '/\bMEDIUMINT\s*(\(\d+\))?\s+UNSIGNED\b/i' => ' INTEGER ',
                '/\bSMALLINT\s*(\(\d+\))?\s+UNSIGNED\b/i'  => ' INTEGER ',
                '/\bTINYINT\s*(\(\d+\))?\s+UNSIGNED\b/i'   => ' SMALLINT ',

                // 精确基础类型转换
                '/\bBIGINT\b(\s*\(\d+\))?/i'      => ' BIGINT ',
                '/\bINT\b(\s*\(\d+\))?/i'         => ' INTEGER ',
                '/\bINTEGER\b(\s*\(\d+\))?/i'     => ' INTEGER ',
                '/\bMEDIUMINT\b(\s*\(\d+\))?/i'   => ' INTEGER ',
                '/\bSMALLINT\b(\s*\(\d+\))?/i'    => ' SMALLINT ',
                '/\bTINYINT\b(\s*\(\d+\))?/i'     => ' SMALLINT ',
                '/\bBOOL(EAN)?\b/i'               => ' SMALLINT ',
                '/\bBIT\s*(\(\d+\))?/i'           => ' INTEGER ',

                // 浮点数处理
                '/\bDOUBLE\b(\s*PRECISION)?/i'    => ' DOUBLE PRECISION ',
                '/\bFLOAT\b(\s*\(\d+(,\d+)?\))?/i' => ' REAL ',
                '/\bDECIMAL\s*\((\d+),(\d+)\)/i' => ' NUMERIC($1,$2) ',
                '/\bDECIMAL\s*\((\d+)\)/i'   => ' NUMERIC($1,0) ',

                // 文本与二进制
                '/LONGTEXT/i'                => ' TEXT ',
                '/MEDIUMTEXT/i'              => ' TEXT ',
                '/TINYTEXT/i'                => ' TEXT ',
                '/\bTEXT\b/i'                => ' TEXT ',
                '/\bJSON\b/i'                => ' TEXT ', // 遵循规范使用 TEXT 存储 JSON
                '/\bBLOB\b/i'                => ' BYTEA ',
                '/\bMEDIUMBLOB\b/i'          => ' BYTEA ',
                '/\bLONGBLOB\b/i'            => ' BYTEA ',
                '/\bBINARY\s*\(16\)/i'       => ' BYTEA ',
                '/\bBINARY\s*\(32\)/i'       => ' BYTEA ',
                '/\bVARBINARY\s*\(16\)/i'    => ' BYTEA ',
                '/\bVARBINARY\s*\(32\)/i'    => ' BYTEA ',

                // INET 网络地址类型原生支持包含操作符 (<<) 范围搜索
                '/DATETIME/i'                => ' TIMESTAMP ',
                '/TIMESTAMP\b/i'             => ' TIMESTAMP ',
                '/\bYEAR\b/i'                => ' SMALLINT ',

                '/\bCHAR\s*\((\d+)\)/i'      => ' VARCHAR($1) ',

            ];
            foreach ($mappings as $pattern => $replacement) {
                $clean_sql = preg_replace($pattern, $replacement, $clean_sql);
            }

            // 自增主键转换
            $clean_sql = preg_replace('/BIGINT\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'BIGSERIAL', $clean_sql);
            $clean_sql = preg_replace('/INTEGER\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'SERIAL', $clean_sql);
            $clean_sql = preg_replace('/INT\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'SERIAL', $clean_sql);
            $clean_sql = preg_replace('/AUTO_INCREMENT/i', '', $clean_sql);

            // 分割并重构
            $raw_queries = preg_split('/;[\r\n]+/s', $clean_sql);
            $queries = [];
            foreach ($raw_queries as $q) {
                $q = trim($q);
                if (empty($q)) continue;

                if (stripos($q, 'CREATE TABLE') !== false) {
                    // 提取表名
                    $tableName = '';
                    if (preg_match('/CREATE\s+TABLE\s+"(\w+)"/i', $q, $tnMatch)) {
                        $tableName = $tnMatch[1];
                    }

                    // 1. 提取所有索引
                    preg_match_all('/(?:UNIQUE\s+)?KEY\s+"(\w+)"\s+\((.*?)\)/i', $q, $idxMatches, PREG_SET_ORDER);
                    $indexes = [];
                    foreach ($idxMatches as $match) {
                        $isUnique = stripos($match[0], 'UNIQUE') !== false;
                        $idxName = $match[1];
                        $idxCols = preg_replace('/\(\d+\)/', '', $match[2]);
                        if ($isUnique) {
                            $indexes[] = "CREATE UNIQUE INDEX \"idx_{$tableName}_{$idxName}\" ON \"{$tableName}\" ({$idxCols})";
                        } else {
                            $indexes[] = "CREATE INDEX \"idx_{$tableName}_{$idxName}\" ON \"{$tableName}\" ({$idxCols})";
                        }
                    }

                    // 2. 从建表语句中移除索引定义
                    $q = preg_replace('/,(?:\s+)?(?:UNIQUE\s+)?KEY\s+"(\w+)"\s+\(.*?\)/i', '', $q);
                    $q = preg_replace('/(?:UNIQUE\s+)?KEY\s+"(\w+)"\s+\(.*?\),?/i', '', $q);

                    // 修正末尾逗号
                    $q = preg_replace('/,\s*\)/', ')', $q);

                    // 保证表先于索引创建
                    $queries[] = $q;
                    foreach ($indexes as $idx) $queries[] = $idx;
                } elseif (stripos($q, 'ALTER TABLE') !== false && (stripos($q, 'AUTO_INCREMENT') !== false || stripos($q, 'MODIFY') !== false)) {
                    // 跳过 MySQL 特有的修改自增值或字段属性语句，PG 不支持该语法且我们最后有同步方案
                    continue;
                } else {
                    $queries[] = $q;
                }
            }
        } else {
            $queries = preg_split('/;[\r\n]+/s', $clean_sql);
        }

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                try {
                    $pdo->exec($query);
                } catch (\PDOException $e) {
                    $code = $e->getCode();
                    if ($code != '42S01' && $code != '42P07') {
                        throw new \Exception("SQL Error in [{$query}]: " . $e->getMessage());
                    }
                }
            }
        }


        // 3. 处理默认配置文件
        $default_config_dir = APP_PATH . 'src/Config/';
        $files = glob($default_config_dir . '*.default.php');
        foreach ($files as $file) {
            $filename = basename($file);
            $new_filename = str_replace('.default.php', '.php', $filename);
            if (!copy($file, $target_config_dir . $new_filename)) {
                throw new \Exception("Failed to copy config file: {$new_filename}");
            }
        }

        // 处理 App.php
        $app_file = $target_config_dir . 'App.php';
        if (file_exists($app_file)) {
            $auth_key = installer_random_str(64);
            $link_secret = installer_random_str(32);
            $app_content = file_get_contents($app_file);
            $app_content = preg_replace("/'auth_key'\s*=>\s*'.*?'/i", "'auth_key' => '$auth_key'", $app_content);
            $app_content = preg_replace("/'link_secret'\s*=>\s*'.*?'/i", "'link_secret' => '$link_secret'", $app_content);
            file_put_contents($app_file, $app_content);
        }

        // 同步 Session.php 的 cookie 前缀
        $session_file = $target_config_dir . 'Session.php';
        if (file_exists($session_file)) {
            $session_content = file_get_contents($session_file);
            $session_content = preg_replace("/'pre'\s*=>\s*'.*?'/i", "'pre' => '{$db['prefix']}'", $session_content);
            file_put_contents($session_file, $session_content);
        }

        // 4. 重写 Database.php
        $db_config_file = $target_config_dir . 'Database.php';

        // 分驱动适配参数
        $charset = ($type === 'pgsql') ? 'UTF8' : 'utf8mb4';
        $collation = ($type === 'pgsql') ? '' : 'utf8mb4_unicode_ci';
        $engine = ($type === 'pgsql') ? '' : 'InnoDB';

        $db_tpl = "<?php
return [
    'driver' => '{$type}',
    'prefix' => '{$db['prefix']}',
    'connections' => [
        '{$type}' => [
            'master' => [
                'host' => '{$db['host']}',
                'port' => '{$db['port']}',
                'database' => '{$db['name']}',
                'username' => '{$db['user']}',
                'password' => '{$db['pass']}',
                'charset' => '{$charset}',
                'collation' => '{$collation}',
                'prefix' => '{$db['prefix']}',
                'engine' => '{$engine}',
                'persistent' => false,
                'timeout' => 5,
            ],
            'slaves' => [],
        ],
    ],
    'shards' => [],
];";
        file_put_contents($db_config_file, $db_tpl);

        // 5. 初始化管理员账户
        $password_hash = password_hash($admin['pass'], PASSWORD_BCRYPT, ['cost' => 12]);
        $salt = installer_random_str(16);
        $time = time();

        if ($type === 'pgsql') {
            // PostgreSQL 不支持 REPLACE INTO，使用 ON CONFLICT
            $sql = "INSERT INTO \"{$db['prefix']}user\" (id, username, email, password, salt, group_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (id) DO UPDATE SET username = EXCLUDED.username, email = EXCLUDED.email, password = EXCLUDED.password, salt = EXCLUDED.salt";
            $stmt = $pdo->prepare($sql);
        } else {
            $stmt = $pdo->prepare("REPLACE INTO `{$db['prefix']}user` (id, username, email, password, salt, group_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        }
        $stmt->execute([1, $admin['user'], $admin['email'], $password_hash, $salt, 1, $time]);

        // 5.1 更新站点设置
        if ($type === 'pgsql') {
            $stmt = $pdo->prepare("SELECT \"value\" FROM \"{$db['prefix']}kv\" WHERE \"key\" = 'setting'");
        } else {
            $stmt = $pdo->prepare("SELECT `value` FROM `{$db['prefix']}kv` WHERE `key` = 'setting'");
        }
        $stmt->execute();
        $setting_json = $stmt->fetchColumn();
        if ($setting_json) {
            $setting_data = json_decode($setting_json, true);
            if (isset($setting_data['config'])) {
                $setting_data['config']['name'] = $data['sitename'] ?? 'WellCMS';
                $setting_data['config']['installed'] = 1;
                if ($type === 'pgsql') {
                    $update_stmt = $pdo->prepare("UPDATE \"{$db['prefix']}kv\" SET \"value\" = ? WHERE \"key\" = 'setting'");
                } else {
                    $update_stmt = $pdo->prepare("UPDATE `{$db['prefix']}kv` SET `value` = ? WHERE `key` = 'setting'");
                }
                $update_stmt->execute([json_encode($setting_data)]);
            }
        }

        // 5.2 PostgreSQL 序列同步 (必须在所有初始数据插入后执行)
        if ($type === 'pgsql') {
            $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '{$db['prefix']}%'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $tbl) {
                // 查找该表下的所有序列关联列
                $stmt_seq = $pdo->prepare("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = ? AND column_default LIKE 'nextval(%'");
                $stmt_seq->execute([$tbl]);
                $seqs = $stmt_seq->fetchAll(PDO::FETCH_ASSOC);
                foreach ($seqs as $s) {
                    if (preg_match("/'([\w\.]+)'/", $s['column_default'], $seqMatch)) {
                        $seq = $seqMatch[1];
                        $col = $s['column_name'];
                        // 同步序列值到最大 ID + 1 (is_called = false，下次 nextval 将返回 MAX+1)
                        $pdo->exec("SELECT setval('\"{$seq}\"', COALESCE((SELECT MAX(\"{$col}\") FROM \"{$tbl}\"), 0) + 1, false)");
                    }
                }
            }
        }

        // 6. 处理 I18n 配置
        $i18n_config_file = $target_config_dir . 'I18n.php';
        $selected_lang = $data['selected_lang'] ?? 'zh';
        $i18n_tpl = "<?php
return [
    'timezone' => 'Asia/Shanghai',
    'locale' => '{$selected_lang}',
    'fallback_locale' => 'en',
    'supported' => ['en', 'zh'],
    'paths' => [
        'app' => '/app/Language/',
        'plugins' => '/plugins/',
        'themes' => '/themes/',
    ],
];";
        file_put_contents($i18n_config_file, $i18n_tpl);

        // 7. 锁死
        file_put_contents(INSTALL_PATH . 'install.lock', $time);

        return ['status' => true, 'message' => 'Installation successful'];
    } catch (\Throwable $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}
