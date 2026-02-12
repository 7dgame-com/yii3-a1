<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Manager ActiveRecord model.
 */
class Manager extends ActiveRecord
{
    public int $id = 0;
    public int $verse_id = 0;
    public string $type = '';
    public ?string $data = null;

    public function getTableName(): string
    {
        return 'manager';
    }

    public function getVerse(): ActiveQuery
    {
        return $this->hasOne(Verse::class, ['id' => 'verse_id']);
    }
}
