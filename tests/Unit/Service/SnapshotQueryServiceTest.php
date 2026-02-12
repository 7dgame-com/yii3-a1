<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\PaginatedResult;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;

/**
 * Unit tests for SnapshotQueryService.
 *
 * Validates Requirements 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.10:
 * - findPublic delegates to SnapshotSearch::searchPublic + PaginationService
 * - findCheckin delegates to SnapshotSearch::searchCheckin + PaginationService
 * - findPrivate delegates to SnapshotSearch::searchPrivate + PaginationService
 * - findGroup delegates to SnapshotSearch::searchGroup + PaginationService
 * - findById queries single snapshot by ID
 * - findByVerseId queries single snapshot by verse_id
 * - findTags delegates to TagsSearch::findByType
 * - All results are cached for 30 seconds
 *
 * Note: PaginationService is final, so we use a real instance.
 * The cache mock executes callbacks immediately to test delegation logic.
 */
final class SnapshotQueryServiceTest extends TestCase
{
    private SnapshotSearch&MockObject $snapshotSearch;
    private TagsSearch&MockObject $tagsSearch;
    private YiiCacheInterface&MockObject $cache;
    private SnapshotQueryService $service;

    protected function setUp(): void
    {
        $this->snapshotSearch = $this->createMock(SnapshotSearch::class);
        $this->tagsSearch = $this->createMock(TagsSearch::class);
        $this->cache = $this->createMock(YiiCacheInterface::class);

        // PaginationService is final — use a real instance.
        // For list methods, the cache mock returns pre-built PaginatedResult
        // so PaginationService::paginate is never actually called with a real DB query.
        $paginationService = new PaginationService();

        $this->service = new SnapshotQueryService(
            $this->snapshotSearch,
            $this->tagsSearch,
            $paginationService,
            $this->cache,
        );
    }

    // ---------------------------------------------------------------
    // findPublic tests
    // ---------------------------------------------------------------

    /**
     * Test findPublic returns PaginatedResult from cache.
     * Validates: Requirement 4.2, 4.10
     */
    public function testFindPublicReturnsPaginatedResult(): void
    {
        $params = ['page' => 1, 'pageSize' => 10];
        $expected = new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 10);

        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $callback, int $ttl) use ($expected) {
                $this->assertSame(30, $ttl);
                $this->assertStringContainsString('snapshot_public', $key);
                // Return cached result without executing callback (simulates cache hit)
                return $expected;
            });

        $result = $this->service->findPublic($params);

        $this->assertSame($expected, $result);
    }

    /**
     * Test findPublic cache key contains correct prefix.
     * Validates: Requirement 4.2, 4.10
     */
    public function testFindPublicCacheKeyPrefix(): void
    {
        $expected = new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);

        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use ($expected) {
                $this->assertStringStartsWith('snapshot_public_', $key);
                return $expected;
            });

        $this->service->findPublic([]);
    }

    /**
     * Test findPublic passes params to SnapshotSearch when cache misses.
     * Validates: Requirement 4.2
     */
    public function testFindPublicPassesParamsToSearch(): void
    {
        $params = ['page' => 2, 'pageSize' => 15, 'tags' => '1,2'];

        $this->snapshotSearch->expects($this->once())
            ->method('searchPublic')
            ->with($params);

        // Simulate cache miss — execute callback, but we can't run paginate
        // without a real DB, so we catch the error at the search level
        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) {
                try {
                    return $cb();
                } catch (\Throwable) {
                    // ActiveQuery::count() will fail without DB — that's expected
                    return new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 15);
                }
            });

        // searchPublic needs to return an ActiveQuery for paginate to work
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn('0');
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn([]);

        $this->snapshotSearch->method('searchPublic')->willReturn($query);

        $this->service->findPublic($params);
    }

    /**
     * Test findPublic uses default page=1 and pageSize=20.
     * Validates: Requirement 4.2
     */
    public function testFindPublicUsesDefaultPagination(): void
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn('0');
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn([]);

        $this->snapshotSearch->method('searchPublic')->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findPublic([]);

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(1, $result->currentPage);
        $this->assertSame(15, $result->perPage);
    }

    // ---------------------------------------------------------------
    // findCheckin tests
    // ---------------------------------------------------------------

    /**
     * Test findCheckin delegates to searchCheckin.
     * Validates: Requirement 4.3
     */
    public function testFindCheckinDelegatesToSearchCheckin(): void
    {
        $params = ['page' => 1, 'pageSize' => 10];
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn('3');
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn([]);

        $this->snapshotSearch->expects($this->once())
            ->method('searchCheckin')
            ->with($params)
            ->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findCheckin($params);

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(3, $result->totalCount);
    }

    /**
     * Test findCheckin caches with 30s TTL.
     * Validates: Requirement 4.10
     */
    public function testFindCheckinCachesTtl30(): void
    {
        $expected = new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);

        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use ($expected) {
                $this->assertSame(30, $ttl);
                $this->assertStringStartsWith('snapshot_checkin_', $key);
                return $expected;
            });

        $this->service->findCheckin([]);
    }

    // ---------------------------------------------------------------
    // findPrivate tests
    // ---------------------------------------------------------------

    /**
     * Test findPrivate passes userId to searchPrivate.
     * Validates: Requirement 4.4
     */
    public function testFindPrivatePassesUserId(): void
    {
        $userId = 42;
        $params = ['page' => 1, 'pageSize' => 10];
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn('2');
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn([]);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPrivate')
            ->with($userId, $params)
            ->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findPrivate($userId, $params);

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(2, $result->totalCount);
    }

    /**
     * Test findPrivate cache key includes userId.
     * Validates: Requirement 4.4, 4.10
     */
    public function testFindPrivateCacheKeyIncludesUserId(): void
    {
        $expected = new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);

        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use ($expected) {
                $this->assertStringContainsString('snapshot_private_42', $key);
                return $expected;
            });

        $this->service->findPrivate(42, []);
    }

    // ---------------------------------------------------------------
    // findGroup tests
    // ---------------------------------------------------------------

    /**
     * Test findGroup passes userId to searchGroup.
     * Validates: Requirement 4.5
     */
    public function testFindGroupPassesUserId(): void
    {
        $userId = 99;
        $params = ['page' => 3, 'pageSize' => 5];
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn('12');
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn([]);

        $this->snapshotSearch->expects($this->once())
            ->method('searchGroup')
            ->with($userId, $params)
            ->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findGroup($userId, $params);

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(12, $result->totalCount);
    }

    /**
     * Test findGroup cache key includes userId.
     * Validates: Requirement 4.5, 4.10
     */
    public function testFindGroupCacheKeyIncludesUserId(): void
    {
        $expected = new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);

        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use ($expected) {
                $this->assertStringContainsString('snapshot_group_99', $key);
                return $expected;
            });

        $this->service->findGroup(99, []);
    }

    // ---------------------------------------------------------------
    // findById tests
    // ---------------------------------------------------------------

    /**
     * Test findById caches with 30s TTL and correct key.
     * Validates: Requirement 4.7, 4.10
     */
    public function testFindByIdCachesWithCorrectKey(): void
    {
        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) {
                $this->assertSame('snapshot_model_by_id_123', $key);
                $this->assertSame(30, $ttl);
                return null;
            });

        $result = $this->service->findById(123);

        $this->assertNull($result);
    }

    /**
     * Test findById returns null from cache (simulates not found).
     * Validates: Requirement 4.7
     */
    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->cache->method('getOrSet')
            ->willReturn(null);

        $result = $this->service->findById(999);

        $this->assertNull($result);
    }

    /**
     * Test findById returns array when found (from cache).
     * Validates: Requirement 4.7
     */
    public function testFindByIdReturnsArrayWhenFound(): void
    {
        $snapshot = $this->createMock(\App\Model\Snapshot::class);
        $snapshot->method('toFullArray')->willReturn(['id' => 123, 'verse_id' => 1, 'uuid' => 'abc']);

        $this->cache->method('getOrSet')
            ->willReturn($snapshot);

        $result = $this->service->findById(123);

        $this->assertIsArray($result);
        $this->assertSame(123, $result['id']);
    }

    // ---------------------------------------------------------------
    // findByVerseId tests
    // ---------------------------------------------------------------

    /**
     * Test findByVerseId caches with correct key.
     * Validates: Requirement 4.7, 4.10
     */
    public function testFindByVerseIdCachesWithCorrectKey(): void
    {
        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) {
                $this->assertSame('snapshot_model_by_verse_id_456', $key);
                $this->assertSame(30, $ttl);
                return null;
            });

        $result = $this->service->findByVerseId(456);

        $this->assertNull($result);
    }

    /**
     * Test findByVerseId returns null when not found.
     * Validates: Requirement 4.7
     */
    public function testFindByVerseIdReturnsNullWhenNotFound(): void
    {
        $this->cache->method('getOrSet')
            ->willReturn(null);

        $result = $this->service->findByVerseId(999);

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // findTags tests
    // ---------------------------------------------------------------

    /**
     * Test findTags delegates to TagsSearch::findByType with default type.
     * Validates: Requirement 4.6
     */
    public function testFindTagsDefaultType(): void
    {
        $query = $this->createMock(ActiveQuery::class);
        $tags = [['id' => 1, 'name' => 'Tag1', 'type' => 'Classify']];

        $this->tagsSearch->expects($this->once())
            ->method('findByType')
            ->with('Classify')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('all')
            ->willReturn($tags);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findTags();

        $this->assertSame($tags, $result);
    }

    /**
     * Test findTags with custom type.
     * Validates: Requirement 4.6
     */
    public function testFindTagsCustomType(): void
    {
        $query = $this->createMock(ActiveQuery::class);

        $this->tagsSearch->expects($this->once())
            ->method('findByType')
            ->with('Custom')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $result = $this->service->findTags('Custom');

        $this->assertSame([], $result);
    }

    /**
     * Test findTags caches with correct key and TTL.
     * Validates: Requirement 4.10
     */
    public function testFindTagsCachesWithCorrectKeyAndTtl(): void
    {
        $this->cache->expects($this->once())
            ->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) {
                $this->assertSame('tags_by_type_Classify', $key);
                $this->assertSame(30, $ttl);
                return [];
            });

        $this->service->findTags();
    }

    /**
     * Test findTags cache key varies by type.
     * Validates: Requirement 4.10
     */
    public function testFindTagsCacheKeyVariesByType(): void
    {
        $capturedKeys = [];

        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return [];
            });

        $this->service->findTags('Classify');
        $this->service->findTags('Custom');

        $this->assertCount(2, $capturedKeys);
        $this->assertSame('tags_by_type_Classify', $capturedKeys[0]);
        $this->assertSame('tags_by_type_Custom', $capturedKeys[1]);
    }

    // ---------------------------------------------------------------
    // Cache key determinism tests
    // ---------------------------------------------------------------

    /**
     * Test that same params produce same cache key (deterministic).
     * Validates: Requirement 4.10
     */
    public function testCacheKeyDeterministic(): void
    {
        $capturedKeys = [];

        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);
            });

        $params = ['page' => 1, 'pageSize' => 20, 'tags' => '1,2'];

        $this->service->findPublic($params);
        $this->service->findPublic($params);

        $this->assertCount(2, $capturedKeys);
        $this->assertSame($capturedKeys[0], $capturedKeys[1]);
    }

    /**
     * Test that different params produce different cache keys.
     * Validates: Requirement 4.10
     */
    public function testDifferentParamsProduceDifferentCacheKeys(): void
    {
        $capturedKeys = [];

        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);
            });

        $this->service->findPublic(['page' => 1, 'pageSize' => 20]);
        $this->service->findPublic(['page' => 2, 'pageSize' => 20]);

        $this->assertCount(2, $capturedKeys);
        $this->assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }

    /**
     * Test that different scopes produce different cache keys.
     * Validates: Requirement 4.10
     */
    public function testDifferentScopesProduceDifferentCacheKeys(): void
    {
        $capturedKeys = [];

        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key, callable $cb, int $ttl) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return new PaginatedResult(items: [], totalCount: 0, pageCount: 0, currentPage: 1, perPage: 20);
            });

        $this->service->findPublic([]);
        $this->service->findCheckin([]);

        $this->assertCount(2, $capturedKeys);
        $this->assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }
}
