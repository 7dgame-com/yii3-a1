<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * User ActiveRecord model.
 *
 * @see Requirements 8.1
 */
class User extends ActiveRecord
{
    public int $id = 0;
    public string $username = '';
    public ?string $auth_key = null;
    public string $password_hash = '';
    public ?string $password_reset_token = null;
    public ?string $nickname = null;
    public ?int $created_at = null;
    public ?int $updated_at = null;

    public function getTableName(): string
    {
        return 'user';
    }

    /**
     * Validate a password against the stored hash.
     */
    public function validatePassword(string $password): bool
    {
        return password_verify($password, $this->get('password_hash'));
    }

    /**
     * Relation: linked keys (UserLinked).
     */
    public function getLinkedKeys(): ActiveQuery
    {
        return $this->hasMany(UserLinked::class, ['user_id' => 'id']);
    }
}
