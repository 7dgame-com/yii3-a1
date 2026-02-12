<?php

declare(strict_types=1);

namespace App\Search;

use App\Model\Snapshot;
use Yiisoft\ActiveRecord\ActiveQuery;

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
        $query = $this->createBaseQuery();

        $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]')
            ->innerJoin('verse_property', '{{verse_property}}.[[verse_id]] = {{verse}}.[[id]]')
            ->innerJoin('property', '{{property}}.[[id]] = {{verse_property}}.[[property_id]]')
            ->andWhere(['property.key' => 'public'])
            ->orderBy(['snapshot.id' => SORT_DESC]);

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
        $query = $this->createBaseQuery();

        $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]')
            ->innerJoin('verse_property', '{{verse_property}}.[[verse_id]] = {{verse}}.[[id]]')
            ->innerJoin('property', '{{property}}.[[id]] = {{verse_property}}.[[property_id]]')
            ->andWhere(['property.key' => 'checkin'])
            ->orderBy(['snapshot.id' => SORT_DESC]);

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
        $query = $this->createBaseQuery();

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
        $query = $this->createBaseQuery();

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
     * Filters results by joining verse_tags and matching against the provided tag IDs.
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

        // Check if verse join already exists; if not, we need it for verse_tags
        $joins = $query->getJoins();
        $hasVerseJoin = false;
        foreach ($joins as $join) {
            if (isset($join[1]) && $join[1] === 'verse') {
                $hasVerseJoin = true;
                break;
            }
        }

        if (!$hasVerseJoin) {
            $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]');
        }

        $query->innerJoin('verse_tags', '{{verse_tags}}.[[verse_id]] = {{verse}}.[[id]]')
            ->andWhere(['verse_tags.tags_id' => $tagIds]);

        return $query;
    }

    /**
     * Create the base ActiveQuery for Snapshot.
     *
     * @return ActiveQuery
     */
    private function createBaseQuery(): ActiveQuery
    {
        return (new ActiveQuery(Snapshot::class))
            ->alias('snapshot');
    }
}
