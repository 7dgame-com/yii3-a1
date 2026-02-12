<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * GroupVerse pivot model.
 */
class GroupVerse extends ActiveRecord
{
    public int $id = 0;
    public int $verse_id = 0;
    public int $group_id = 0;

    public function getTableName(): string
    {
        return 'group_verse';
    }
}
