<?php

/**
 * WellCMS 3.0 Installer
 */

define('IN_WELLCMS', true);
define('INSTALL_PATH', __DIR__ . DIRECTORY_SEPARATOR);
define('APP_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 锁检查
if (file_exists(INSTALL_PATH . 'install.lock')) {
    // 拦截任何 AJAX 操作
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => false, 'message' => 'WellCMS has already been installed.']);
        exit;
    }
    // 如果不是在访问完成页面，重定向到 Step 5
    if (($_GET['step'] ?? 1) != 5) {
        header('Location: ?step=5');
        exit;
    }
}

require INSTALL_PATH . 'bin/installer.func.php';

// 语言处理
$lang_config = file_exists(APP_PATH . 'config/I18n.php') ? include APP_PATH . 'config/I18n.php' : ['locale' => 'zh'];
$lang_code = $_GET['lang'] ?? ($_COOKIE['wellcms_install_lang'] ?? ($lang_config['locale'] ?? 'zh'));
if (!in_array($lang_code, ['zh', 'en'])) $lang_code = 'zh';

// 强制保存语言到 cookie
setcookie('wellcms_install_lang', $lang_code, time() + 3600, '/');

$lang = include INSTALL_PATH . 'language/' . $lang_code . '.php';

$step = (int)($_GET['step'] ?? 1);
$action = $_GET['action'] ?? '';

// 处理 AJAX 请求
if ($action === 'check_db') {
    $db_config = $_POST['db'] ?? [];
    $result = check_db_connection($db_config);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($action === 'install') {
    $config = $_POST;
    // 注入当前选择的语言代码，以便安装程序写入 config/I18n.php
    $config['selected_lang'] = $lang_code;
    $result = execute_install($config);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 渲染视图
include INSTALL_PATH . 'view/header.inc.php';

switch ($step) {
    case 1:
        include INSTALL_PATH . 'view/license.php';
        break;
    case 2:
        $env = check_environment();
        include INSTALL_PATH . 'view/env.php';
        break;
    case 3:
        include INSTALL_PATH . 'view/config.php';
        break;
    case 4:
        include INSTALL_PATH . 'view/processing.php';
        break;
    case 5:
        include INSTALL_PATH . 'view/completed.php';
        break;
    default:
        include INSTALL_PATH . 'view/license.php';
        break;
}

include INSTALL_PATH . 'view/footer.inc.php';
