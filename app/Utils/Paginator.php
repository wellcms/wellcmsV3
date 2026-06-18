<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Utils;

class Paginator
{
    /**
     * 简单分页：上一页 / 下一页
     */
    public static function simple(int $total, int $pageSize, int $page, callable $urlGenerator): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, $pageSize);

        $totalPages = (int) ceil($total / $pageSize);
        $page       = min($page, $totalPages > 0 ? $totalPages : 1);

        $offset = ($page - 1) * $pageSize;

        $prevPage = $page > 1 ? $page - 1 : null;
        $nextPage = $page < $totalPages ? $page + 1 : null;

        return [
            'total'       => $total,
            'page'        => $page,
            'pageSize'    => $pageSize,
            'totalPages'  => $totalPages,
            'offset'      => $offset,
            'prevPage'    => $prevPage,
            'nextPage'    => $nextPage,
            'prevUrl'     => $prevPage ? $urlGenerator($prevPage) : null,
            'nextUrl'     => $nextPage ? $urlGenerator($nextPage) : null,
        ];
    }

    /**
     * 生成分页链接
     * 
     * @param int $total     总记录数
     * @param int $pageSize  每页条数
     * @param int $page      当前页
     * @param int $limit     最多显示多少页码（含首尾）
     * @param callable $urlGenerator 链接生成器 function($page): string
     * @return array
     */
    public static function paginate(int $total, int $pageSize, int $page, int $limit, callable $urlGenerator): array
    {
        // 统一入口参数下限保护，与 simple() 风格一致，彻底消除除零风险
        $page     = max(1, $page);
        $pageSize = max(1, $pageSize);

        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $totalPages);

        $pages = [];

        if ($totalPages <= $limit) {
            // 总页数不超过限制，直接显示全部
            $pages = range(1, $totalPages);
        } else {
            // 总页数大于限制，只保留首尾和中间
            $pages[] = 1;
            $pages[] = $totalPages;

            $half = (int)floor(($limit - 2) / 2);
            $start = max(2, $page - $half);
            $end = min($totalPages - 1, $page + $half);

            if ($start <= 2) {
                $end = $limit - 1;
            } elseif ($end >= $totalPages - 1) {
                $start = $totalPages - $limit + 2;
            }

            for ($i = $start; $i <= $end; $i++) {
                $pages[] = $i;
            }

            sort($pages);
        }

        // 插入省略号 ...
        $output = [];
        $last = null;
        foreach ($pages as $p) {
            if ($last !== null && $p - $last > 1) {
                $output[] = ['label' => '...', 'url' => null, 'active' => false];
            }
            $output[] = [
                'label' => (string)$p,
                'url' => $urlGenerator($p),
                'active' => $p === $page
            ];
            $last = $p;
        }

        return $output;
    }
}

/* // 1）simple
$total = 100000; // 总记录数
$pageSize = 10;
$page = 5000;

// URL 回调
$urlCallback = function (int $p) use ($extra) {
    return $this->urlGenerator->url("/y/tools/approved/{$p}", $extra);
};

$pagination = \App\Utils\Paginator::simple($total, $pageSize, $page, $urlCallback);

if ($pagination['prevUrl']) {
    echo "<a href=" . $pagination['prevUrl'] . ">上一页</a>";
}

echo '第' . $pagination['page'] . ' / ' . $pagination['totalPages'] . ' 页';

if ($pagination['nextUrl']) {
    echo "<a href=" . $pagination['nextUrl'] . ">下一页</a>";
}

// 2）paginate
$total = 100000; // 总记录数
$pageSize = 10;
$page = 5000;

// URL 回调
$urlCallback = function (int $p) use ($extra) {
    return $this->urlGenerator->url("/y/tools/approved/{$p}", $extra);
};

$pagination = \App\Utils\Paginator::paginate($total, $pageSize, $page, 10, $urlCallback);

foreach ($pagination as $p) {
    if ($p['label'] === '...') {
        echo " ... ";
    } elseif ($p['active']) {
        echo "<strong>[{$p['label']}]</strong> ";
    } else {
        echo "<a href='{$p[' url']}'>{$p['label']}</a> ";
    }
} */