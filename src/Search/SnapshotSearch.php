<?php

declare(strict_types=1);

namespace App\Search;

use App\Model\Snapshot;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Query\Query;

/**
 * SnapshotSearch — pure service class that builds ActiveQuery instances
 * for querying snapshots by various scopes (public, checkin, private, group).
 *
 * Replaces the Yii2 search model pattern. Does not extend Model.
 *
 * @see Requirements 8.10, 4.2, 4.3, 4.4, 4.5, 4.9
 */
class SnapshotSearch
{
    /**
     * Search for public snapshots.
     *
     * Queries snapshots whose associated verse has a property with key='public'
     * via the verse_property + property join tables.
     *
     * @param array $params Optional query parameters (e.g., 'tags' for tag filtering)
     * @return ActiveQuery
     *
     * @see Requirements 4.2
     */
    public function searchPublic(array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery($params);

        $query->andWhere(['exists', $this->propertyQuery($query, 'public')]);

        if (!empty($params['tags'])) {
            $this->applyTagFilter($query, $params['tags']);
        }

        return $query;
    }

    /**
     * Search for checkin snapshots.
     *
     * Queries snapshots whose associated verse has a property with key='checkin'
     * via the verse_property + property join tables.
     *
     * @param array $params Optional query parameters (e.g., 'tags' for tag filtering)
     * @return ActiveQuery
     *
     * @see Requirements 4.3
     */
    public function searchCheckin(array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery($params);

        $query->andWhere(['exists', $this->propertyQuery($query, 'checkin')]);

        if (!empty($params['tags'])) {
            $this->applyTagFilter($query, $params['tags']);
        }

        return $query;
    }

    /**
     * Search for private snapshots belonging to a specific user.
     *
     * Filters snapshots by the author_id of the associated verse.
     *
     * @param int $userId The authenticated user's ID
     * @param array $params Optional query parameters (e.g., 'tags' for tag filtering)
     * @return ActiveQuery
     *
     * @see Requirements 4.4
     */
    public function searchPrivate(int $userId, array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery($params);

        $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]')
            ->andWhere(['verse.author_id' => $userId])
            ->orderBy(['snapshot.id' => SORT_DESC]);

        if (!empty($params['tags'])) {
            $this->applyTagFilter($query, $params['tags']);
        }

        return $query;
    }

    /**
     * Search for group snapshots accessible to a specific user.
     *
     * Queries snapshots whose associated verse belongs to a group
     * that the user is a member of, via group_user + group_verse join tables.
     *
     * @param int $userId The authenticated user's ID
     * @param array $params Optional query parameters (e.g., 'tags' for tag filtering)
     * @return ActiveQuery
     *
     * @see Requirements 4.5
     */
    public function searchGroup(int $userId, array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery($params);

        $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]')
            ->innerJoin('group_verse', '{{group_verse}}.[[verse_id]] = {{verse}}.[[id]]')
            ->innerJoin('group_user', '{{group_user}}.[[group_id]] = {{group_verse}}.[[group_id]]')
            ->andWhere(['group_user.user_id' => $userId])
            ->distinct()
            ->orderBy(['snapshot.id' => SORT_DESC]);

        if (!empty($params['tags'])) {
            $this->applyTagFilter($query, $params['tags']);
        }

        return $query;
    }

    /**
     * Apply tag filter to an existing query.
     *
     * Matches any requested classification tag without multiplying snapshot rows.
     * Tag IDs can be provided as a comma-separated string or an array of integers.
     *
     * @param ActiveQuery $query The query to apply the filter to
     * @param string|array $tagIds Comma-separated tag IDs string or array of tag IDs
     * @return ActiveQuery The modified query
     *
     * @see Requirements 4.9
     */
    public function applyTagFilter(ActiveQuery $query, string|array $tagIds): ActiveQuery
    {
        if (is_string($tagIds)) {
            $tagIds = array_filter(
                array_map('intval', explode(',', $tagIds)),
                static fn(int $id): bool => $id > 0,
            );
        }

        if (empty($tagIds)) {
            return $query;
        }

        $classifications = (new Query($query->getModel()->db()))
            ->select(['filter_vt.verse_id'])
            ->from(['filter_vt' => 'verse_tags'])
            ->innerJoin('tags AS filter_tags', '{{filter_tags}}.[[id]] = {{filter_vt}}.[[tags_id]]')
            ->where('{{filter_vt}}.[[verse_id]] = {{snapshot}}.[[verse_id]]')
            ->andWhere(['filter_vt.tags_id' => $tagIds])
            ->andWhere(['or', ['<>', 'filter_tags.key', 'public'], ['filter_tags.key' => null]]);
        $query->andWhere(['exists', $classifications]);

        return $query;
    }

    /**
     * Create the base ActiveQuery for Snapshot.
     *
     * @return ActiveQuery
     */
    private function createBaseQuery(array $params = []): ActiveQuery
    {
        $query = (new ActiveQuery(Snapshot::class))
            ->alias('snapshot');

        $public = self::normalizePublicFilter($params);
        if ($public !== null) {
            $publicProperty = $this->propertyQuery($query, 'public');
            $query->andWhere([$public === 1 ? 'exists' : 'not exists', $publicProperty]);
        }

        // Each requested key must exist on this verse. EXISTS keeps pagination
        // stable even when legacy data contains duplicate property associations.
        foreach (self::normalizePropertiesFilter($params) as $key) {
            $query->andWhere(['exists', $this->propertyQuery($query, $key)]);
        }

        return $this->applyBaseFilters($query, $params);
    }

    private function propertyQuery(ActiveQuery $query, string $key): Query
    {
        return (new Query($query->getModel()->db()))
            ->select(['filter_vp.verse_id'])
            ->from(['filter_vp' => 'verse_property'])
            ->innerJoin('property AS filter_p', '{{filter_p}}.[[id]] = {{filter_vp}}.[[property_id]]')
            ->where('{{filter_vp}}.[[verse_id]] = {{snapshot}}.[[verse_id]]')
            ->andWhere(['filter_p.key' => $key]);
    }

    /**
     * Canonical property keys shared by query construction and cache lookup.
     * CSV strings separate keys; array items are complete keys, including commas.
     * An omitted filter preserves the scope, but an invalid filter must not widen it.
     *
     * @return list<string>
     */
    public static function normalizePropertiesFilter(array $params): array
    {
        if (!array_key_exists('properties', $params)) {
            return [];
        }

        $value = $params['properties'];
        $keys = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($keys) || $keys === [] || count($keys) > 50) {
            throw new \RuntimeException('properties must contain 1 to 50 property keys.', 400);
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('//u', $key) !== 1 || preg_match('/\p{Cc}/u', $key) !== 0) {
                throw new \RuntimeException('properties must contain valid UTF-8 strings without control characters.', 400);
            }
            $key = trim($key);
            if (preg_match('/\A.{1,255}\z/us', $key) !== 1) {
                throw new \RuntimeException('Each property key must contain 1 to 255 characters.', 400);
            }
            $normalized[] = $key;
        }

        $normalized = array_values(array_unique($normalized, SORT_STRING));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Validate before both querying and cache lookup. An omitted filter keeps
     * the existing scope; public=0 must not be treated as an empty value.
     */
    public static function normalizePublicFilter(array $params): ?int
    {
        if (!array_key_exists('public', $params)) {
            return null;
        }

        return match ($params['public']) {
            0, '0', false, 'false' => 0,
            1, '1', true, 'true' => 1,
            default => throw new \RuntimeException('public must be 0 or 1.', 400),
        };
    }

    /**
     * Apply Yii2 SnapshotSearch::search() compatible filters.
     */
    private function applyBaseFilters(ActiveQuery $query, array $params): ActiveQuery
    {
        $exactFilters = [];

        foreach (['id', 'verse_id', 'created_at', 'created_by'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $exactFilters["snapshot.{$field}"] = in_array($field, ['id', 'verse_id', 'created_by'], true)
                    ? (int) $params[$field]
                    : $params[$field];
            }
        }

        $query->andFilterWhere($exactFilters);

        foreach (['uuid', 'code', 'data', 'metas', 'resources'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $query->andFilterWhere(['like', "snapshot.{$field}", (string) $params[$field]]);
            }
        }

        return $query;
    }
}
