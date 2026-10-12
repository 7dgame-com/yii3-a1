<?php

declare(strict_types=1);

namespace App\Search;

use App\Model\Tags;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * TagsSearch — pure service class that builds ActiveQuery instances
 * for querying tags with various filters.
 *
 * Replaces the Yii2 search model pattern. Does not extend Model.
 *
 * @see Requirements 8.10, 4.6
 */
class TagsSearch
{
    /**
     * Search tags with optional filters.
     *
     * @param array $params Search parameters:
     *   - 'name': string — filter by name (partial match)
     * @return ActiveQuery
     */
    public function search(array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery();

        if (!empty($params['name'])) {
            $query->andWhere(['like', 'tags.name', $params['name']]);
        }

        $query->orderBy(['tags.id' => SORT_ASC]);

        return $query;
    }

    /**
     * Find a tag by ID.
     *
     * @param int $id
     * @return ActiveQuery
     */
    public function findById(int $id): ActiveQuery
    {
        return $this->createBaseQuery()
            ->andWhere(['tags.id' => $id]);
    }

    /**
     * Find all available tags, regardless of type.
     *
     * @return ActiveQuery
     */
    public function findAll(): ActiveQuery
    {
        return $this->search();
    }

    /**
     * Create the base ActiveQuery for Tags.
     *
     * @return ActiveQuery
     */
    private function createBaseQuery(): ActiveQuery
    {
        return (new ActiveQuery(Tags::class))
            ->alias('tags')
            ->select(['tags.id', 'tags.name', 'tags.key'])
            ->andWhere(['or', ['<>', 'tags.key', 'public'], ['tags.key' => null]]);
    }
}
