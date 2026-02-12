<?php

declare(strict_types=1);

namespace App\Service;

use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;

/**
 * Snapshot query service — orchestrates snapshot search, pagination, and caching.
 *
 * Delegates query building to SnapshotSearch/TagsSearch, pagination to PaginationService,
 * and caches results for 30 seconds via yiisoft/cache.
 *
 * @see Requirements 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.10
 */
final class SnapshotQueryService
{
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly SnapshotSearch $snapshotSearch,
        private readonly TagsSearch $tagsSearch,
        private readonly PaginationService $paginationService,
        private readonly YiiCacheInterface $cache,
    ) {
    }

    /**
     * Find public snapshots (property key='public').
     *
     * @param array $params Query parameters:
     *   - 'page': int (default 1)
     *   - 'pageSize': int (default 15)
     *   - 'tags': string — comma-separated tag IDs
     * @return PaginatedResult
     *
     * @see Requirements 4.2
     */
    public function findPublic(array $params): PaginatedResult
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 15);
        $cacheKey = $this->buildCacheKey('snapshot_public', $params);

        return $this->cache->getOrSet(
            $cacheKey,
            fn () => $this->paginationService->paginate(
                $this->snapshotSearch->searchPublic($params),
                $page,
                $pageSize,
            ),
            self::CACHE_TTL,
        );
    }

    /**
     * Find checkin snapshots (property key='checkin').
     *
     * @param array $params Query parameters:
     *   - 'page': int (default 1)
     *   - 'pageSize': int (default 15)
     *   - 'tags': string — comma-separated tag IDs
     * @return PaginatedResult
     *
     * @see Requirements 4.3
     */
    public function findCheckin(array $params): PaginatedResult
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 15);
        $cacheKey = $this->buildCacheKey('snapshot_checkin', $params);

        return $this->cache->getOrSet(
            $cacheKey,
            fn () => $this->paginationService->paginate(
                $this->snapshotSearch->searchCheckin($params),
                $page,
                $pageSize,
            ),
            self::CACHE_TTL,
        );
    }

    /**
     * Find private snapshots for a specific user (by author_id).
     *
     * @param int   $userId The authenticated user's ID
     * @param array $params Query parameters:
     *   - 'page': int (default 1)
     *   - 'pageSize': int (default 15)
     *   - 'tags': string — comma-separated tag IDs
     * @return PaginatedResult
     *
     * @see Requirements 4.4
     */
    public function findPrivate(int $userId, array $params): PaginatedResult
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 15);
        $cacheKey = $this->buildCacheKey("snapshot_private_{$userId}", $params);

        return $this->cache->getOrSet(
            $cacheKey,
            fn () => $this->paginationService->paginate(
                $this->snapshotSearch->searchPrivate($userId, $params),
                $page,
                $pageSize,
            ),
            self::CACHE_TTL,
        );
    }

    /**
     * Find group snapshots accessible to a specific user.
     *
     * @param int   $userId The authenticated user's ID
     * @param array $params Query parameters:
     *   - 'page': int (default 1)
     *   - 'pageSize': int (default 15)
     *   - 'tags': string — comma-separated tag IDs
     * @return PaginatedResult
     *
     * @see Requirements 4.5
     */
    public function findGroup(int $userId, array $params): PaginatedResult
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 15);
        $cacheKey = $this->buildCacheKey("snapshot_group_{$userId}", $params);

        return $this->cache->getOrSet(
            $cacheKey,
            fn () => $this->paginationService->paginate(
                $this->snapshotSearch->searchGroup($userId, $params),
                $page,
                $pageSize,
            ),
            self::CACHE_TTL,
        );
    }

    /**
     * Find a single snapshot by its ID (returns array for backward compat).
     */
    public function findById(int $id): ?array
    {
        $snapshot = $this->findSnapshotModel($id);
        return $snapshot !== null ? $snapshot->toFullArray() : null;
    }

    /**
     * Find a single snapshot model by its ID.
     */
    public function findSnapshotModel(int $id): ?\App\Model\Snapshot
    {
        $cacheKey = "snapshot_model_by_id_{$id}";

        return $this->cache->getOrSet(
            $cacheKey,
            function () use ($id): ?\App\Model\Snapshot {
                return $this->createSnapshotQuery()
                    ->andWhere(['snapshot.id' => $id])
                    ->one();
            },
            self::CACHE_TTL,
        );
    }

    /**
     * Find a single snapshot by verse_id (returns array for backward compat).
     */
    public function findByVerseId(int $verseId): ?array
    {
        $snapshot = $this->findSnapshotModelByVerseId($verseId);
        return $snapshot !== null ? $snapshot->toFullArray() : null;
    }

    /**
     * Find a single snapshot model by verse_id.
     */
    public function findSnapshotModelByVerseId(int $verseId): ?\App\Model\Snapshot
    {
        $cacheKey = "snapshot_model_by_verse_id_{$verseId}";

        return $this->cache->getOrSet(
            $cacheKey,
            function () use ($verseId): ?\App\Model\Snapshot {
                return $this->createSnapshotQuery()
                    ->andWhere(['snapshot.verse_id' => $verseId])
                    ->orderBy(['snapshot.id' => SORT_DESC])
                    ->one();
            },
            self::CACHE_TTL,
        );
    }

    /**
     * Find tags of a specific type (default: 'Classify').
     *
     * @param string $type Tag type (default: 'Classify')
     * @return array List of tag records
     *
     * @see Requirements 4.6
     */
    public function findTags(string $type = 'Classify'): array
    {
        $cacheKey = "tags_by_type_{$type}";

        return $this->cache->getOrSet(
            $cacheKey,
            fn () => $this->tagsSearch->findByType($type)->all(),
            self::CACHE_TTL,
        );
    }

    /**
     * Build a deterministic cache key from a prefix and params array.
     *
     * @param string $prefix Cache key prefix
     * @param array  $params Query parameters
     * @return string
     */
    private function buildCacheKey(string $prefix, array $params): string
    {
        // Only include relevant params for cache key
        $relevant = [
            'page' => $params['page'] ?? 1,
            'pageSize' => $params['pageSize'] ?? 15,
            'tags' => $params['tags'] ?? '',
        ];

        return $prefix . '_' . md5(serialize($relevant));
    }

    /**
     * Create a base ActiveQuery for Snapshot with alias.
     *
     * @return ActiveQuery
     */
    private function createSnapshotQuery(): ActiveQuery
    {
        return (new ActiveQuery(\App\Model\Snapshot::class))
            ->alias('snapshot');
    }
}
