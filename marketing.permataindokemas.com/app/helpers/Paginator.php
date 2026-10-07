<?php

declare(strict_types=1);

namespace App\Helpers;

/** Pagination hasil query SQL. */
final class Paginator
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }

    /**
     * @param string $selectSql query SELECT tanpa ORDER BY / LIMIT
     * @param array<string,mixed> $params
     * @param string $orderBy ekspresi ORDER BY (WAJIB dari whitelist, bukan input user)
     */
    public static function query(string $selectSql, array $params, string $orderBy, int $page, ?int $perPage = null): self
    {
        $perPage = max(1, min(200, $perPage ?? (int) config('app.pagination.per_page', 25)));
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM (' . $selectSql . ') AS paginate_count', $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $items = Database::fetchAll($selectSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);
        return new self($items, $total, $page, $perPage);
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** HTML navigasi halaman (mempertahankan filter di query string). */
    public function links(string $pageParam = 'page'): string
    {
        $pages = $this->pages();
        if ($pages <= 1) {
            return '';
        }
        $window = [];
        for ($i = max(1, $this->page - 2); $i <= min($pages, $this->page + 2); $i++) {
            $window[] = $i;
        }
        if (!in_array(1, $window, true)) {
            array_unshift($window, 1, '…');
        }
        if (!in_array($pages, $window, true)) {
            $window[] = '…';
            $window[] = $pages;
        }
        $html = '<nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm">';
        $prev = $this->page - 1;
        $html .= '<li class="page-item' . ($prev < 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e(query_with([$pageParam => max(1, $prev)])) . '" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a></li>';
        foreach ($window as $item) {
            if ($item === '…') {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
                continue;
            }
            $active = $item === $this->page;
            $html .= '<li class="page-item' . ($active ? ' active' : '') . '"><a class="page-link" href="' . e(query_with([$pageParam => $item])) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $item . '</a></li>';
        }
        $next = $this->page + 1;
        $html .= '<li class="page-item' . ($next > $pages ? ' disabled' : '') . '"><a class="page-link" href="' . e(query_with([$pageParam => min($pages, $next)])) . '" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a></li>';
        return $html . '</ul></nav>';
    }

    /** Footer standar: "Menampilkan 1–25 dari 120" + links */
    public function footer(string $unit = 'data', string $pageParam = 'page'): string
    {
        return '<div class="list-footer"><span>Menampilkan ' . number_format($this->from(), 0, ',', '.') . '–' . number_format($this->to(), 0, ',', '.')
            . ' dari ' . number_format($this->total, 0, ',', '.') . ' ' . e($unit) . '</span>' . $this->links($pageParam) . '</div>';
    }
}
