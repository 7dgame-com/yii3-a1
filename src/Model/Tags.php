<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Tags ActiveRecord model.
 */
class Tags extends ActiveRecord
{
    public int $id = 0;
    public ?string $name = null;
    public ?string $key = null;
    public ?string $type = null;

    public function getTableName(): string
    {
        return 'tags';
    }
}
