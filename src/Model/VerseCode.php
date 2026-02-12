<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * VerseCode ActiveRecord model.
 */
class VerseCode extends ActiveRecord
{
    public int $id = 0;
    public ?string $blockly = null;
    public int $verse_id = 0;
    public ?int $code_id = null;

    public function getTableName(): string
    {
        return 'verse_code';
    }

    public function getVerse(): ActiveQuery
    {
        return $this->hasOne(Verse::class, ['id' => 'verse_id']);
    }

    public function getCode(): ActiveQuery
    {
        return $this->hasOne(Code::class, ['id' => 'code_id']);
    }
}
