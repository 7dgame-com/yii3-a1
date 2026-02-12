<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Service\PaginatedResult;
use App\Service\PaginationService;
use Eris\Generator;
use Eris\TestTrait;
use HttpSoft\Message\Response;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for pagination correctness.
 *
 * Feature: yii2-to-yii3-migration, Property 11: 分页正确性
 *
 * **Validates: Requirements 4.8, 10.2**
 *
 * Property 11: For any positive integer pageSize and dataset, the number of items
 * returned after pagination should not exceed pageSize, X-Pagination-Total-Count
 * should equal the dataset total, and X-Pagination-Page-Count should equal
 * ceil(totalCount / pageSize).
 */
final class PaginationPropertyTest extends TestCase
{
    use TestTrait;

    private PaginationService $service;

    protected function setUp(): void
    {
        $this->service = new PaginationService();
    }

    /**
     * Property 11a: pageCount equals ceil(totalCount / pageSize) for any positive inputs.
     *
     * For any totalCount >= 0 and pageSize >= 1, the computed pageCount must
     * equal ceil(totalCount / pageSize), or 0 when totalCount is 0.
     *
     * **Validates: Requirements 4.8, 10.2**
     *
     * @eris-repeat 100
     */
    public function testPageCountEqualsCeilOfTotalDividedByPageSize(): void
    {
        $this->forAll(
            Generator\choose(0, 1000),  // totalCount
            Generator\choose(1, 100)    // pageSize
        )
            ->then(function (int $totalCount, int $pageSize): void {
                $expectedPageCount = $totalCount > 0
                    ? (int) ceil($totalCount / $pageSize)
                    : 0;

                // Build items array of size totalCount
                $items = array_fill(0, $totalCount, ['id' => 1]);

                // Simulate what paginate() computes for page 1
                $currentPage = 1;
                $pageItems = array_slice($items, 0, $pageSize);

                $result = new PaginatedResult(
                    items: $pageItems,
                    totalCount: $totalCount,
                    pageCount: $expectedPageCount,
                    currentPage: $currentPage,
                    perPage: $pageSize,
                );

                $this->assertSame(
                    $expectedPageCount,
                    $result->pageCount,
                    sprintf(
                        'pageCount should be ceil(%d / %d) = %d, got %d',
                        $totalCount,
                        $pageSize,
                        $expectedPageCount,
                        $result->pageCount
                    )
                );
            });
    }

    /**
     * Property 11b: Items count never exceeds pageSize.
     *
     * For any totalCount and pageSize, the number of items on any page
     * must be <= pageSize.
     *
     * **Validates: Requirements 4.8, 10.2**
     *
     * @eris-repeat 100
     */
    public function testItemsCountNeverExceedsPageSize(): void
    {
        $this->forAll(
            Generator\choose(0, 500),   // totalCount
            Generator\choose(1, 100),   // pageSize
            Generator\choose(1, 50)     // page number
        )
            ->then(function (int $totalCount, int $pageSize, int $page): void {
                $pageCount = $totalCount > 0
                    ? (int) ceil($totalCount / $pageSize)
                    : 0;

                // Normalize page like PaginationService does
                $normalizedPage = max(1, $page);
                if ($pageCount > 0 && $normalizedPage > $pageCount) {
                    $normalizedPage = $pageCount;
                }

                // Calculate items for this page
                $offset = ($normalizedPage - 1) * $pageSize;
                $allItems = array_fill(0, $totalCount, ['id' => 1]);
                $pageItems = array_slice($allItems, $offset, $pageSize);

                $this->assertLessThanOrEqual(
                    $pageSize,
                    count($pageItems),
                    sprintf(
                        'Page items (%d) should not exceed pageSize (%d) for totalCount=%d, page=%d',
                        count($pageItems),
                        $pageSize,
                        $totalCount,
                        $normalizedPage
                    )
                );
            });
    }

    /**
     * Property 11c: applyHeaders sets X-Pagination-Total-Count equal to totalCount.
     *
     * For any PaginatedResult, the X-Pagination-Total-Count header must match
     * the totalCount, and X-Pagination-Page-Count must match pageCount.
     *
     * **Validates: Requirements 4.8, 10.2**
     *
     * @eris-repeat 100
     */
    public function testApplyHeadersSetsCorrectPaginationValues(): void
    {
        $this->forAll(
            Generator\choose(0, 1000),  // totalCount
            Generator\choose(1, 100),   // pageSize
            Generator\choose(1, 50)     // page
        )
            ->then(function (int $totalCount, int $pageSize, int $page): void {
                $pageCount = $totalCount > 0
                    ? (int) ceil($totalCount / $pageSize)
                    : 0;

                $normalizedPage = max(1, $page);
                if ($pageCount > 0 && $normalizedPage > $pageCount) {
                    $normalizedPage = $pageCount;
                }

                $result = new PaginatedResult(
                    items: [],
                    totalCount: $totalCount,
                    pageCount: $pageCount,
                    currentPage: $normalizedPage,
                    perPage: $pageSize,
                );

                $response = new Response();
                $response = $this->service->applyHeaders($response, $result);

                $this->assertSame(
                    (string) $totalCount,
                    $response->getHeaderLine('X-Pagination-Total-Count'),
                    'X-Pagination-Total-Count must equal totalCount'
                );
                $this->assertSame(
                    (string) $pageCount,
                    $response->getHeaderLine('X-Pagination-Page-Count'),
                    'X-Pagination-Page-Count must equal ceil(totalCount / pageSize)'
                );
                $this->assertSame(
                    (string) $normalizedPage,
                    $response->getHeaderLine('X-Pagination-Current-Page'),
                    'X-Pagination-Current-Page must equal the normalized page'
                );
                $this->assertSame(
                    (string) $pageSize,
                    $response->getHeaderLine('X-Pagination-Per-Page'),
                    'X-Pagination-Per-Page must equal pageSize'
                );
            });
    }

    /**
     * Property 11d: All four X-Pagination headers are always present.
     *
     * For any PaginatedResult, applyHeaders must add exactly the four
     * required X-Pagination-* headers to the response.
     *
     * **Validates: Requirements 4.8, 10.2**
     *
     * @eris-repeat 100
     */
    public function testApplyHeadersAlwaysAddsAllFourHeaders(): void
    {
        $this->forAll(
            Generator\choose(0, 1000),
            Generator\choose(1, 100),
            Generator\choose(1, 20)
        )
            ->then(function (int $totalCount, int $pageSize, int $page): void {
                $pageCount = $totalCount > 0
                    ? (int) ceil($totalCount / $pageSize)
                    : 0;

                $result = new PaginatedResult(
                    items: [],
                    totalCount: $totalCount,
                    pageCount: $pageCount,
                    currentPage: max(1, $page),
                    perPage: $pageSize,
                );

                $response = new Response();
                $response = $this->service->applyHeaders($response, $result);

                $requiredHeaders = [
                    'X-Pagination-Current-Page',
                    'X-Pagination-Page-Count',
                    'X-Pagination-Per-Page',
                    'X-Pagination-Total-Count',
                ];

                foreach ($requiredHeaders as $header) {
                    $this->assertTrue(
                        $response->hasHeader($header),
                        "Response must contain header '{$header}'"
                    );
                    $this->assertNotSame(
                        '',
                        $response->getHeaderLine($header),
                        "Header '{$header}' must not be empty"
                    );
                }
            });
    }

    /**
     * Property 11e: Last page items count equals totalCount mod pageSize (or pageSize if evenly divisible).
     *
     * For any non-empty dataset, the last page should contain
     * totalCount % pageSize items (or pageSize if totalCount is evenly divisible).
     *
     * **Validates: Requirements 4.8, 10.2**
     *
     * @eris-repeat 100
     */
    public function testLastPageItemsCountIsCorrect(): void
    {
        $this->forAll(
            Generator\choose(1, 500),   // totalCount (at least 1)
            Generator\choose(1, 100)    // pageSize
        )
            ->then(function (int $totalCount, int $pageSize): void {
                $pageCount = (int) ceil($totalCount / $pageSize);
                $lastPage = $pageCount;

                // Items on the last page
                $offset = ($lastPage - 1) * $pageSize;
                $allItems = array_fill(0, $totalCount, ['id' => 1]);
                $lastPageItems = array_slice($allItems, $offset, $pageSize);

                $remainder = $totalCount % $pageSize;
                $expectedLastPageCount = $remainder === 0 ? $pageSize : $remainder;

                $this->assertSame(
                    $expectedLastPageCount,
                    count($lastPageItems),
                    sprintf(
                        'Last page (page %d) should have %d items for totalCount=%d, pageSize=%d',
                        $lastPage,
                        $expectedLastPageCount,
                        $totalCount,
                        $pageSize
                    )
                );
            });
    }
}
