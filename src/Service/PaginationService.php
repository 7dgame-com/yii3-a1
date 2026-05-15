<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Pagination service for ActiveRecord queries.
 *
 * Provides pagination logic and Yii2-compatible X-Pagination-* response headers.
 * Replaces Yii2's built-in REST pagination (yii\data\ActiveDataProvider + Serializer).
 *
 * Headers match Yii2 format exactly:
 * - X-Pagination-Current-Page
 * - X-Pagination-Page-Count
 * - X-Pagination-Per-Page
 * - X-Pagination-Total-Count
 *
 * @see Requirements 4.8, 10.2
 */
final class PaginationService
{
    private const MAX_PAGE_SIZE = 50;

    /**
     * Paginate an ActiveQuery.
     *
     * Counts the total matching records, calculates page count,
     * applies offset/limit, and returns a PaginatedResult value object.
     *
     * @param ActiveQuery $query    The query to paginate.
     * @param int         $page     The current page number (1-based). Values < 1 are normalized to 1.
     * @param int         $pageSize The number of items per page. Values outside 1..50 are normalized.
     * @return PaginatedResult The paginated result containing items and metadata.
     */
    public function paginate(ActiveQuery $query, int $page, int $pageSize): PaginatedResult
    {
        // Normalize inputs
        $page = max(1, $page);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));

        // Count total records
        $totalCount = (int) $query->count();

        // Calculate page count
        $pageCount = $totalCount > 0 ? (int) ceil($totalCount / $pageSize) : 0;

        // Normalize page to not exceed pageCount (if there are results)
        if ($pageCount > 0 && $page > $pageCount) {
            $page = $pageCount;
        }

        // Apply offset and limit, then fetch items
        $offset = ($page - 1) * $pageSize;
        $items = $query->offset($offset)->limit($pageSize)->all();

        return new PaginatedResult(
            items: $items,
            totalCount: $totalCount,
            pageCount: $pageCount,
            currentPage: $page,
            perPage: $pageSize,
        );
    }

    /**
     * Apply Yii2-compatible pagination headers to a PSR-7 response.
     *
     * Adds the following headers:
     * - X-Pagination-Current-Page: The current page number
     * - X-Pagination-Page-Count: The total number of pages
     * - X-Pagination-Per-Page: The number of items per page
     * - X-Pagination-Total-Count: The total number of items
     *
     * @param ResponseInterface $response The PSR-7 response to add headers to.
     * @param PaginatedResult   $result   The pagination result containing metadata.
     * @return ResponseInterface The response with pagination headers added.
     */
    public function applyHeaders(ResponseInterface $response, PaginatedResult $result): ResponseInterface
    {
        return $response
            ->withHeader('X-Pagination-Current-Page', (string) $result->currentPage)
            ->withHeader('X-Pagination-Page-Count', (string) $result->pageCount)
            ->withHeader('X-Pagination-Per-Page', (string) $result->perPage)
            ->withHeader('X-Pagination-Total-Count', (string) $result->totalCount);
    }
}
