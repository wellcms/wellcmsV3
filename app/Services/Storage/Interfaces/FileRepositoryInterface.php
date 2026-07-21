<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Interfaces;

/** -----------------------------
 * 抽象：文件元数据持久化（MySQL）
 * ----------------------------- */
interface FileRepositoryInterface
{
    /** upsert */
    public function save(array $row);
}
