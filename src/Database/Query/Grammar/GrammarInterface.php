<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Query\Grammar;

interface GrammarInterface
{
    public function wrap(string $identifier): string;
    public function compileSelect(array $columns, string $table, array $joins, array $wheres, array $orders, ?int $limit, ?int $offset): string;
    public function compileInsert(string $table, array $data, bool $batch = false): string;
    public function compileUpdate(string $table, array $data, array $wheres): string;
    public function compileBulkUpdate(string $table, string $key, array $rows, array $wheres = []): string;
    public function compileDelete(string $table, array $wheres): string;

    /**
     * 方言特定元数据与环境指令 (Stage 1 重构)
     */
    public function compileTableListing(): string;
    public function compileColumnListing(string $table): string;
    public function compileIndexListing(string $table): string;
    public function compileTableExists(string $table): string;
    public function compileSetCharset(string $charset, string $collation): string;
    public function compileIsolationLevel(string $level): string;
    public function compileTruncate(string $table): string;
    public function compileSchema(string $schema): string;
    public function getSequenceName(string $table): ?string;
    public function prepareSchema(string $sql): array;

    public static function clearCache(): void;
}
