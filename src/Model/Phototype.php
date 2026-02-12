<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * Phototype ActiveRecord model.
 */
class Phototype extends ActiveRecord
{
    public int $id = 0;
    public string $title = '';
    public ?string $uuid = null;
    public ?string $type = null;
    public ?int $author_id = null;
    public ?int $image_id = null;
    public ?int $updater_id = null;
    public ?int $resource_id = null;
    public ?string $data = null;
    public ?string $schema = null;
    public \DateTimeImmutable|string|null $created_at = null;
    public \DateTimeImmutable|string|null $updated_at = null;

    public function getTableName(): string
    {
        return 'phototype';
    }
}
