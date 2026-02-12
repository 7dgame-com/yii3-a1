<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * VerseTags pivot model.
 */
class VerseTags extends ActiveRecord
{
    public int $id = 0;
    public int $verse_id = 0;
    public int $tags_id = 0;

    public function getTableName(): string
    {
        return 'verse_tags';
    }
}
