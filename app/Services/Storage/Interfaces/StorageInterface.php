<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Interfaces;

/**
 * 原生存储接口 - 用于抹平本地磁盘和云存储的差异
 */
interface StorageInterface
{
    /**
     * 写入文件流或云储存
     * @param string $path 相对路径
     * @param resource $resource 打开的资源流
     * @return bool
     */
    public function writeStream(string $path, $resource): bool;

    /**
     * 读取文件流
     * @param string $path 相对路径
     * @return resource|false 返回资源流，失败返回false
     */
    public function readStream(string $path);

    /**
     * 追加写入流，并可选地更新 Hash 上下文
     * @param string $path 相对路径
     * @param resource $resource 分片资源流
     * @param mixed $hashCtx (可选) hash_init 返回的上下文引用
     * @return bool
     * @param bool $hashCtx
     */
    public function appendStream(string $path, $resource, &$hashCtx = null): bool;

    /**
     * 删除文件
     * @param string $path
     * @return bool
     */
    public function delete(string $path): bool;

    /**
     * 检查文件是否存在
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * 获取文件大小
     * @param string $path
     * @return int
     */
    public function size(string $path): int;

    /**
     * 获取文件 MIME 类型
     * @param string $path
     * @return string
     */
    public function mimeType(string $path): string;

    /**
     * 清空目录（递归删除）
     * @param string $path
     * @return bool
     */
    public function deleteDir(string $path): bool;
}