<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Watermark ActiveRecord model.
 */
class Watermark extends ActiveRecord
{
    public int $id = 0;
    public string $sn = '';
    public ?string $hardware = null;
    public ?int $user_id = null;
    public \DateTimeImmutable|string|null $created_at = null;
    public \DateTimeImmutable|string|null $updated_at = null;

    public function getTableName(): string
    {
        return 'watermark';
    }
}
