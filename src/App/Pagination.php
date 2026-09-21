<?php

declare(strict_types=1);

namespace App;

/**
 * Jednotná stránkovací logika pro HTML seznamy aplikace.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    public static function currentPage(string $parameter = 'page'): int
    {
        return max(1, (int)($_GET[$parameter] ?? 1));
    }

    /**
     * @param array<int,mixed> $items
     * @return array{items:array<int,mixed>,pagination:array<string,int|string>}
     */
    public static function slice(array $items, string $parameter = 'page', int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $total = count($items);
        $pagination = self::meta($total, self::currentPage($parameter), $perPage, $parameter);
        $offset = ((int)$pagination['page'] - 1) * (int)$pagination['per_page'];

        return [
            'items' => array_values(array_slice($items, $offset, (int)$pagination['per_page'])),
            'pagination' => $pagination,
        ];
    }

    /** @return array<string,int|string> */
    public static function meta(int $total, int $page, int $perPage = self::DEFAULT_PER_PAGE, string $parameter = 'page'): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? 0 : min($total, $page * $perPage);

        return [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'from' => $from,
            'to' => $to,
            'parameter' => $parameter,
        ];
    }
}
