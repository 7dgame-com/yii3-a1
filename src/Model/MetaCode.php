<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * MetaCode ActiveRecord model.
 */
class MetaCode extends ActiveRecord
{
    public int $id = 0;
    public ?string $blockly = null;
    public int $meta_id = 0;
    public ?int $code_id = null;

    public function getTableName(): string
    {
        return 'meta_code';
    }

    public function getMeta(): ActiveQuery
    {
        return $this->hasOne(Meta::class, ['id' => 'meta_id']);
    }

    public function getCode(): ActiveQuery
    {
        return $this->hasOne(Code::class, ['id' => 'code_id']);
    }
}
