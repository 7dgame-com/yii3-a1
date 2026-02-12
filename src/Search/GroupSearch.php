<?php

declare(strict_types=1);

namespace App\Search;

use App\Model\Group;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * GroupSearch — pure service class that builds ActiveQuery instances
 * for querying groups with various filters.
 *
 * Replaces the Yii2 search model pattern. Does not extend Model.
 *
 * @see Requirements 8.10
 */
class GroupSearch
{
    /**
     * Search groups with optional filters.
     *
     * @param array $params Search parameters:
     *   - 'name': string — filter by name (partial match)
     *   - 'created_by': int — filter by creator
     * @return ActiveQuery
     */
    public function search(array $params = []): ActiveQuery
    {
        $query = $this->createBaseQuery();

        if (!empty($params['name'])) {
            $query->andWhere(['like', 'group.name', $params['name']]);
        }

        if (!empty($params['created_by'])) {
            $query->andWhere(['group.created_by' => (int) $params['created_by']]);
        }

        $query->orderBy(['group.id' => SORT_DESC]);

        return $query;
    }

    /**
     * Find a group by ID.
     *
     * @param int $id
     * @return ActiveQuery
     */
    public function findById(int $id): ActiveQuery
    {
        return $this->createBaseQuery()
            ->andWhere(['group.id' => $id]);
    }

    /**
     * Find groups that a user belongs to.
     *
     * @param int $userId
     * @return ActiveQuery
     */
    public function findByUser(int $userId): ActiveQuery
    {
        return $this->createBaseQuery()
            ->innerJoin('group_user', '{{group_user}}.[[group_id]] = {{group}}.[[id]]')
            ->andWhere(['group_user.user_id' => $userId])
            ->orderBy(['group.id' => SORT_DESC]);
    }

    /**
     * Create the base ActiveQuery for Group.
     *
     * @return ActiveQuery
     */
    private function createBaseQuery(): ActiveQuery
    {
        return (new ActiveQuery(Group::class))
            ->alias('group');
    }
}
