<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PaginatedResult;
use App\Service\PaginationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Unit tests for PaginationService and PaginatedResult.
 *
 * Validates Requirements 4.8, 10.2:
 * - paginate() correctly counts, calculates pages, applies offset/limit
 * - applyHeaders() adds Yii2-compatible X-Pagination-* headers
 * - PaginatedResult value object holds correct pagination metadata
 */
final class PaginationServiceTest extends TestCase
{
    private PaginationService $service;

    protected function setUp(): void
    {
        $this->service = new PaginationService();
    }

    // ---------------------------------------------------------------
    // PaginatedResult value object tests
    // ---------------------------------------------------------------

    /**
     * Test PaginatedResult stores all properties correctly.
     */
    public function testPaginatedResultProperties(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $result = new PaginatedResult(
            items: $items,
            totalCount: 50,
            pageCount: 5,
            currentPage: 2,
            perPage: 10,
        );

        $this->assertSame($items, $result->items);
        $this->assertSame(50, $result->totalCount);
        $this->assertSame(5, $result->pageCount);
        $this->assertSame(2, $result->currentPage);
        $this->assertSame(10, $result->perPage);
    }

    /**
     * Test PaginatedResult with empty items.
     */
    public function testPaginatedResultEmptyItems(): void
    {
        $result = new PaginatedResult(
            items: [],
            totalCount: 0,
            pageCount: 0,
            currentPage: 1,
            perPage: 20,
        );

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->totalCount);
        $this->assertSame(0, $result->pageCount);
    }

    // ---------------------------------------------------------------
    // applyHeaders() tests
    // ---------------------------------------------------------------

    /**
     * Test applyHeaders adds all four X-Pagination-* headers.
     * Validates: Requirement 10.2
     */
    public function testApplyHeadersAddsAllPaginationHeaders(): void
    {
        $result = new PaginatedResult(
            items: [],
            totalCount: 100,
            pageCount: 10,
            currentPage: 3,
            perPage: 10,
        );

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        $this->service->applyHeaders($response, $result);

        $this->assertArrayHasKey('X-Pagination-Current-Page', $headerCalls);
        $this->assertArrayHasKey('X-Pagination-Page-Count', $headerCalls);
        $this->assertArrayHasKey('X-Pagination-Per-Page', $headerCalls);
        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);

        $this->assertSame('3', $headerCalls['X-Pagination-Current-Page']);
        $this->assertSame('10', $headerCalls['X-Pagination-Page-Count']);
        $this->assertSame('10', $headerCalls['X-Pagination-Per-Page']);
        $this->assertSame('100', $headerCalls['X-Pagination-Total-Count']);
    }

    /**
     * Test applyHeaders with zero total count.
     * Validates: Requirement 10.2
     */
    public function testApplyHeadersWithZeroTotalCount(): void
    {
        $result = new PaginatedResult(
            items: [],
            totalCount: 0,
            pageCount: 0,
            currentPage: 1,
            perPage: 20,
        );

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        $this->service->applyHeaders($response, $result);

        $this->assertSame('1', $headerCalls['X-Pagination-Current-Page']);
        $this->assertSame('0', $headerCalls['X-Pagination-Page-Count']);
        $this->assertSame('20', $headerCalls['X-Pagination-Per-Page']);
        $this->assertSame('0', $headerCalls['X-Pagination-Total-Count']);
    }

    /**
     * Test applyHeaders returns a ResponseInterface.
     * Validates: Requirement 10.2
     */
    public function testApplyHeadersReturnsResponseInterface(): void
    {
        $result = new PaginatedResult(
            items: [],
            totalCount: 5,
            pageCount: 1,
            currentPage: 1,
            perPage: 10,
        );

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $returned = $this->service->applyHeaders($response, $result);

        $this->assertInstanceOf(ResponseInterface::class, $returned);
    }

    /**
     * Test applyHeaders header values are strings.
     * Validates: Requirement 10.2
     */
    public function testApplyHeadersValuesAreStrings(): void
    {
        $result = new PaginatedResult(
            items: [],
            totalCount: 42,
            pageCount: 3,
            currentPage: 2,
            perPage: 15,
        );

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        $this->service->applyHeaders($response, $result);

        foreach ($headerCalls as $name => $value) {
            $this->assertIsString($value, "Header $name value should be a string");
        }
    }

    /**
     * Test paginate caps pageSize to Yii2-compatible default maximum.
     */
    public function testPaginateCapsPageSizeAtFifty(): void
    {
        $query = $this->createActiveQueryMock(1000);
        $query->expects($this->once())
            ->method('limit')
            ->with(50)
            ->willReturnSelf();

        $result = $this->service->paginate($query, 1, 5000);

        $this->assertSame(50, $result->perPage);
        $this->assertSame(20, $result->pageCount);
    }

    private function createActiveQueryMock(int $totalCount, array $items = []): ActiveQuery&MockObject
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn((string) $totalCount);
        $query->method('offset')->willReturnSelf();
        $query->method('all')->willReturn($items);

        return $query;
    }
}
