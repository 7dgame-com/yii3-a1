<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Group ActiveRecord model.
 */
class Group extends ActiveRecord
{
    public int $id = 0;
    public ?string $name = null;
    public ?string $description = null;
    public ?int $image_id = null;
    public ?int $user_id = null;
    public ?string $info = null;
    public \DateTimeImmutable|string|null $created_at = null;
    public \DateTimeImmutable|string|null $updated_at = null;

    public function getTableName(): string
    {
        return 'group';
    }

    public function getUsers(): ActiveQuery
    {
        return $this->hasMany(User::class, ['id' => 'user_id'])
            ->viaTable('group_user', ['group_id' => 'id']);
    }

    public function getVerses(): ActiveQuery
    {
        return $this->hasMany(Verse::class, ['id' => 'verse_id'])
            ->viaTable('group_verse', ['group_id' => 'id']);
    }
}
