<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * UserLinked ActiveRecord model.
 *
 * @see Requirements 8.8
 */
class UserLinked extends ActiveRecord
{
    public int $id = 0;
    public int $user_id = 0;
    public string $key = '';
    public \DateTimeImmutable|string|null $created_at = null;

    public function getTableName(): string
    {
        return 'user_linked';
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
