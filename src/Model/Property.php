<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Property ActiveRecord model.
 */
class Property extends ActiveRecord
{
    public int $id = 0;
    public string $key = '';
    public ?string $info = null;

    public function getTableName(): string
    {
        return 'property';
    }
}
