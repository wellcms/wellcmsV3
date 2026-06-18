<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Controllers\Base;

// 同步/异步提示，支持json
class MessageController extends \App\Controllers\Base\BaseController {}

/* 
$messageController = new \App\Controllers\Base\MessageController($container);
return $messageController->errorMessage('Admin SignIn verification error: ' . $e->getMessage(), 500);

return $messageController->successMessage($this->language->get('sign_in_success'), 0, '/');
 */