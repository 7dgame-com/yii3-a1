<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Code ActiveRecord model.
 */
class Code extends ActiveRecord
{
    public int $id = 0;
    public ?string $lua = null;
    public ?string $js = null;

    public function getTableName(): string
    {
        return 'code';
    }
}
