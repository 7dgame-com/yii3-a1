<?php

declare(strict_types=1);

namespace App\Search;

use App\Model\Verse;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * VerseSearch — pure service class that builds ActiveQuery instances
 * for querying verses with various filters.
 *
 * Replaces the Yii2 search model pattern. Does not extend Model.
 *
 * @see Requirements 8.10
 */
class VerseSearch
{
    /**
     * Search verses with optional filters.
     *
     * @param array $params Search parameters:
     *   - 'name': string — filter by name (partial match)
     *   - 'author_id': int — filter by author
     *   - 'description': string — filter by description (partial match)
     * @return ActiveQuery
     */
    public function search(array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery();

        if (!empty($params['name'])) {
            $query->andWhere(['like', 'verse.name', $params['name']]);
        }

        if (!empty($params['author_id'])) {
            $query->andWhere(['verse.author_id' => (int) $params['author_id']]);
        }

        if (!empty($params['description'])) {
            $query->andWhere(['like', 'verse.description', $params['description']]);
        }

        $query->orderBy(['verse.id' => SORT_DESC]);

        return $query;
    }

    /**
     * Find a verse by ID.
     *
     * @param int $id
     * @return ActiveQuery
     */
    public function findById(int $id): ActiveQuery
    {
        return $this->createBaseQuery()
            ->andWhere(['verse.id' => $id]);
    }

    /**
     * Find verses by author ID.
     *
     * @param int $authorId
     * @return ActiveQuery
     */
    public function findByAuthor(int $authorId): ActiveQuery
    {
        return $this->createBaseQuery()
            ->andWhere(['verse.author_id' => $authorId])
            ->orderBy(['verse.id' => SORT_DESC]);
    }

    /**
     * Create the base ActiveQuery for Verse.
     *
     * @return ActiveQuery
     */
    private function createBaseQuery(): ActiveQuery
    {
        return (new ActiveQuery(Verse::class))
            ->alias('verse');
    }
}
