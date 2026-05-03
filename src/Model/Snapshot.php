<?php

declare(strict_types=1);

namespace App\Model;

use JsonSerializable;
use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Snapshot (场景快照) ActiveRecord model.
 *
 * Matches Yii2 behavior: fields() returns [] (empty), all data is in extraFields().
 * The REST serializer only includes extraFields when ?expand=... is specified.
 *
 * @see Requirements 8.3, 8.4
 */
class Snapshot extends ActiveRecord implements JsonSerializable
{
    public int $id = 0;
    public int $verse_id = 0;
    public ?string $uuid = null;
    public ?string $code = null;
    public string|array|object|null $data = null;
    public string|array|object|null $metas = null;
    public string|array|object|null $resources = null;
    public string|array|object|null $managers = null;
    public string|array|object|null $space = null;
    public ?int $created_by = null;
    public \DateTimeImmutable|string|null $created_at = null;

    public function getTableName(): string
    {
        return 'snapshot';
    }

    public function getVerse(): ActiveQuery
    {
        return $this->hasOne(Verse::class, ['id' => 'verse_id']);
    }

    public function getCreatedBy(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function relationQuery(string $name): ActiveQuery
    {
        return match ($name) {
            'verse' => $this->hasOne(Verse::class, ['id' => 'verse_id']),
            'createdBy' => $this->hasOne(User::class, ['id' => 'created_by']),
            default => parent::relationQuery($name),
        };
    }

    /**
     * Default JSON serialization — matches Yii2 fields()=[] behavior.
     * Returns empty array (serialized as [] in JSON), matching Yii2 REST serializer.
     */
    public function jsonSerialize(): array
    {
        return [];
    }

    /**
     * Get expanded fields matching Yii2 extraFields().
     * Only the requested expand fields are included.
     *
     * @param array $expandFields List of field names to expand
     * @return array
     */
    public function toExpandedArray(array $expandFields): array
    {
        $available = $this->getExtraFieldsMap();
        $result = [];

        foreach ($expandFields as $field) {
            if (isset($available[$field])) {
                $result[$field] = $available[$field]();
            }
        }

        return $result;
    }

    /**
     * Get all extra fields as array (for full expansion).
     *
     * @return array
     */
    public function toFullArray(): array
    {
        $result = [];
        foreach ($this->getExtraFieldsMap() as $key => $getter) {
            $result[$key] = $getter();
        }
        return $result;
    }

    /**
     * Map of extra field names to getter closures, matching Yii2 extraFields().
     */
    private function getExtraFieldsMap(): array
    {
        $createdAt = $this->get('created_at');
        if ($createdAt instanceof \DateTimeImmutable) {
            $createdAt = $createdAt->format('Y-m-d H:i:s');
        }

        return [
            'id' => fn() => $this->get('id'),
            'name' => function () {
                try {
                    $verse = $this->relation('verse');
                    return $verse?->get('name') ?? '';
                } catch (\Throwable) {
                    return '';
                }
            },
            'description' => function () {
                try {
                    $verse = $this->relation('verse');
                    return $verse?->get('description') ?? '';
                } catch (\Throwable) {
                    return '';
                }
            },
            'image' => function () {
                try {
                    $verse = $this->relation('verse');
                    return $verse?->relation('image');
                } catch (\Throwable) {
                    return null;
                }
            },
            'author_id' => function () {
                try {
                    $verse = $this->relation('verse');
                    return $verse?->get('author_id');
                } catch (\Throwable) {
                    return null;
                }
            },
            'author' => function () {
                try {
                    $verse = $this->relation('verse');
                    return $verse?->relation('author');
                } catch (\Throwable) {
                    return null;
                }
            },
            'uuid' => fn() => $this->get('uuid'),
            'verse_id' => fn() => $this->get('verse_id'),
            'code' => fn() => $this->get('code'),
            'data' => fn() => $this->get('data'),
            'metas' => fn() => $this->get('metas'),
            'resources' => fn() => $this->get('resources'),
            'managers' => fn() => $this->get('managers'),
        ];
    }
}
