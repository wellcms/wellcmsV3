<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Interfaces;

/** -----------------------------
 * 抽象：上传会话存储（Redis）
 * ----------------------------- */
interface UploadSessionStoreInterface
{
    /** @return string $uploadId */
    public function create(array $meta);
    /** @return array|null */
    public function getMeta(string $uploadId);
    /** @return int[] 已上传分片索引 */
    public function getUploadedParts(string $uploadId);
    /** 标记分片完成 */
    public function addPart(string $uploadId, int $index);
    /** 刷新 TTL */
    public function touch(string $uploadId);
    /** 删除会话 */
    public function destroy(string $uploadId);
}
