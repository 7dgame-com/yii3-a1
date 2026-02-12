<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * GroupUser pivot model.
 */
class GroupUser extends ActiveRecord
{
    public int $id = 0;
    public int $user_id = 0;
    public int $group_id = 0;

    public function getTableName(): string
    {
        return 'group_user';
    }
}
