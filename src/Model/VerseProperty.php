<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * VerseProperty pivot model.
 */
class VerseProperty extends ActiveRecord
{
    public int $id = 0;
    public int $verse_id = 0;
    public int $property_id = 0;

    public function getTableName(): string
    {
        return 'verse_property';
    }
}
