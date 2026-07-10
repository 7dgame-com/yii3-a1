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
    public const LOGIN_CODE_TTL_SECONDS = 60;

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

    public function loginCodeExpiresAt(): int
    {
        $createdAt = $this->createdAtTimestamp();
        if ($createdAt <= 0) {
            return 0;
        }

        return $createdAt + self::LOGIN_CODE_TTL_SECONDS;
    }

    public function isLoginCodeExpired(): bool
    {
        $expiresAt = $this->loginCodeExpiresAt();

        return $expiresAt <= 0 || $expiresAt <= time();
    }

    private function createdAtTimestamp(): int
    {
        $createdAt = $this->get('created_at');

        if ($createdAt instanceof \DateTimeInterface) {
            return $createdAt->getTimestamp();
        }

        if (is_int($createdAt)) {
            return $createdAt;
        }

        if (is_string($createdAt) && ctype_digit($createdAt)) {
            return (int) $createdAt;
        }

        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($createdAt, new \DateTimeZone('Asia/Shanghai')))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }
}
