<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Session;

/**
 * 会话接口
 * - 使用 session 表 + session_data 表双表存储
 */
interface SessionInterface
{
    public function start(): void;
    public function getId(): string;
    /**
     * @return array
     */
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
    public function delete(string $key): void;
    public function destroy(): void;
    public function regenerate(): string;
    public function getOldId(): ?string;
    public function clearOldId(): void;
    // 限流检测
    public function isThrottled(int $maxRequestsPerMinute): bool;
    public function all(): array;
}
