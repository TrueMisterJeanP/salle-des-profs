<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Retourne le numéro de page courant depuis $_GET.
 */
function current_page(string $param = 'page'): int
{
    $page = (int)get_value($param, '1');

    return max(1, $page);
}

/**
 * Calcule l’offset SQL.
 */
function pagination_offset(int $page, int $perPage): int
{
    return max(0, ($page - 1) * $perPage);
}

/**
 * Calcule le nombre total de pages.
 */
function total_pages(int $totalItems, int $perPage): int
{
    if ($totalItems <= 0) {
        return 1;
    }

    return (int)ceil($totalItems / $perPage);
}

/**
 * Construit une URL paginée en conservant les autres paramètres GET.
 */
function page_url(int $page, string $param = 'page'): string
{
    $params = $_GET;
    $params[$param] = $page;

    return '?' . http_build_query($params);
}

/**
 * Affiche une pagination simple.
 */
function render_pagination(int $page, int $totalPages, string $param = 'page'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav class="pagination" aria-label="Pagination">';

    if ($page > 1) {
        $html .= '<a class="button-secondary" href="' . e(page_url($page - 1, $param)) . '">Précédent</a>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
        $html .= '<a class="button-secondary" href="' . e(page_url(1, $param)) . '">1</a>';

        if ($start > 2) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<span class="button-primary pagination-current">' . e((string)$i) . '</span>';
        } else {
            $html .= '<a class="button-secondary" href="' . e(page_url($i, $param)) . '">' . e((string)$i) . '</a>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }

        $html .= '<a class="button-secondary" href="' . e(page_url($totalPages, $param)) . '">' . e((string)$totalPages) . '</a>';
    }

    if ($page < $totalPages) {
        $html .= '<a class="button-secondary" href="' . e(page_url($page + 1, $param)) . '">Suivant</a>';
    }

    $html .= '</nav>';

    return $html;
}
