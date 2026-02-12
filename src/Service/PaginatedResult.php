<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Value object representing a paginated query result.
 *
 * Contains the items for the current page along with pagination metadata
 * needed to generate X-Pagination-* response headers compatible with Yii2.
 *
 * @see Requirements 4.8, 10.2
 */
final class PaginatedResult
{
    /**
     * @param array  $items       The items for the current page.
     * @param int    $totalCount  The total number of items across all pages.
     * @param int    $pageCount   The total number of pages.
     * @param int    $currentPage The current page number (1-based).
     * @param int    $perPage     The number of items per page.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $totalCount,
        public readonly int $pageCount,
        public readonly int $currentPage,
        public readonly int $perPage,
    ) {
    }
}
