<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

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

    public function getResource(): ActiveQuery
    {
        return $this->hasOne(Resource::class, ['id' => 'resource_id']);
    }

    public function relationQuery(string $name): ActiveQuery
    {
        return match ($name) {
            'resource' => $this->hasOne(Resource::class, ['id' => 'resource_id']),
            default => parent::relationQuery($name),
        };
    }

    /**
     * A1-compatible response for GET /v1/phototype/info.
     *
     * @return array<string, mixed>
     */
    public function toInfoArray(): array
    {
        $resource = null;

        try {
            $related = $this->relation('resource');
            $resource = $related instanceof Resource ? $related->toA1Array() : $related;
        } catch (\Throwable) {
            $resource = null;
        }

        return [
            'id' => $this->get('id'),
            'data' => $this->get('data'),
            'title' => $this->get('title'),
            'resource' => $resource,
        ];
    }
}
