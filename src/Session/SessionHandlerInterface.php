<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Session;

/**
 * 会话处理器接口
 * 继承原生 \SessionHandlerInterface 以兼容 session_set_save_handler()
 * 为兼容 PHP 7.2 - 8.3，在此接口中不强制返回类型，由实现类处理。
 */
interface SessionHandlerInterface extends \SessionHandlerInterface
{
    /**
     * @param string $save_path
     * @param string $session_name
     * @return bool
     */
    public function open($save_path, $session_name): bool;
    /**
     * @return bool
     */
    public function close(): bool;
    /**
     * @param string $session_id
     * @return string
     */
    public function read($session_id): string;
    /**
     * @param string $session_id
     * @param string $session_data
     * @return bool
     */
    public function write($session_id, $session_data): bool;
    /**
     * @param string $session_id
     * @return bool
     */
    public function destroy($session_id): bool;
    /**
     * @param int $maxlifetime
     * @return int
     */
    public function gc($maxlifetime): int;
}
