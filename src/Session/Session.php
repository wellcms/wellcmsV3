<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Session;

/**
 * 会话实现类
 * 兼容 FPM 与 Swoole 协程环境，消除对 $_SESSION 的直接依赖
 */
class Session implements \Framework\Session\SessionInterface
{
    /**
     * 会话ID
     * @var string
     */
    private $id;
    /**
     * 旧会话ID
     * @var string|null
     */
    private $oldId;
    /**
     * 会话数据
     * @var array
     */
    private $data = [];
    /**
     * 会话是否已启动
     * @var bool
     */
    private $started = false;

    public function __construct(string $id = '', array $data = [])
    {
        $this->id = $id;
        $this->data = $data;
        $this->started = !empty($id);
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOldId(): ?string
    {
        return $this->oldId;
    }

    public function clearOldId(): void
    {
        $this->oldId = null;
    }

    /**
     * @param null $default
     * @return array
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }

    public function regenerate(): string
    {
        $this->oldId = $this->id;
        $this->id = bin2hex(random_bytes(16));
        return $this->id;
    }

    public function isThrottled(int $maxRequestsPerMinute): bool
    {
        // 基础实现，可结合 Cache 进行扩展
        return false;
    }

    public function all(): array
    {
        return $this->data;
    }
}
