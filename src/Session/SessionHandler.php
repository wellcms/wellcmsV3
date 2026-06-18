<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Session;

class SessionHandler implements \Framework\Session\SessionHandlerInterface
{
    /**
     * @param string $save_path
     * @param string $session_name
     */
    public function open($save_path, $session_name): bool{
        // 初始化数据库连接或其他存储资源
        return true;
    }

    public function close(): bool{
        // 关闭资源
        return true;
    }

    /**
     * @param int $session_id
     */
    public function read($session_id): string{
        // 从存储中读取数据，返回空字符串表示无数据
        return '';
    }

    /**
     * @param int $session_id
     * @param array $session_data
     */
    public function write($session_id, $session_data): bool{
        // 写入数据到存储
        return true;
    }

    /**
     * @param int $session_id
     */
    public function destroy($session_id): bool{
        // 删除指定会话数据
        return true;
    }

    /**
     * @param int $maxlifetime
     */
    public function gc($maxlifetime): int{
        // 清理过期数据
        return 0;
    }
}
