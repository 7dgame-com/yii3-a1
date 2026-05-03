<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Resource ActiveRecord model.
 */
class Resource extends ActiveRecord
{
    public int $id = 0;
    public string $name = '';
    public string $type = '';
    public ?string $uuid = null;
    public int $file_id = 0;
    public ?int $image_id = null;
    public ?int $author_id = null;
    public ?int $updater_id = null;
    public ?string $info = null;
    public \DateTimeImmutable|string|null $created_at = null;

    public function getTableName(): string
    {
        return 'resource';
    }

    public function getFile(): ActiveQuery
    {
        return $this->hasOne(File::class, ['id' => 'file_id']);
    }

    public function getImage(): ActiveQuery
    {
        return $this->hasOne(File::class, ['id' => 'image_id']);
    }

    public function relationQuery(string $name): ActiveQuery
    {
        return match ($name) {
            'file' => $this->hasOne(File::class, ['id' => 'file_id']),
            'image' => $this->hasOne(File::class, ['id' => 'image_id']),
            default => parent::relationQuery($name),
        };
    }

    /**
     * A1 Resource::fields() compatible shape.
     *
     * @return array<string, mixed>
     */
    public function toA1Array(): array
    {
        $info = $this->get('info');
        if (!is_string($info) && $info !== null) {
            $info = json_encode($info, JSON_THROW_ON_ERROR);
        }

        $file = null;
        try {
            $related = $this->relation('file');
            $file = $related instanceof File ? $related->toA1Array() : $related;
        } catch (\Throwable) {
            $file = null;
        }

        return [
            'id' => $this->get('id'),
            'info' => $info,
            'uuid' => $this->get('uuid'),
            'type' => $this->get('type'),
            'file' => $file,
        ];
    }
}
