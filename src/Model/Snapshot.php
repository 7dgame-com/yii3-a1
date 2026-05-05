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
                return $this->findVerse()?->get('name') ?? '';
            },
            'description' => function () {
                return $this->findVerse()?->get('description') ?? '';
            },
            'image' => function () {
                $image = $this->findFileById($this->findVerse()?->get('image_id'));
                if ($image === null) {
                    return null;
                }

                return $image->toA1Array();
            },
            'author_id' => function () {
                return $this->findVerse()?->get('author_id');
            },
            'author' => function () {
                return $this->findUserById($this->findVerse()?->get('author_id'));
            },
            'uuid' => fn() => $this->get('uuid'),
            'verse_id' => fn() => $this->get('verse_id'),
            'code' => fn() => $this->get('code'),
            'data' => fn() => $this->get('data'),
            'metas' => fn() => $this->get('metas'),
            'resources' => fn() => $this->get('resources'),
            'managers' => fn() => $this->get('managers'),
            'space' => fn() => $this->normalizeSpaceSnapshot($this->get('space')),
        ];
    }

    private function findVerse(): ?Verse
    {
        $verseId = $this->get('verse_id');
        if ($verseId === null) {
            return null;
        }

        try {
            $verse = (new ActiveQuery(Verse::class))
                ->andWhere(['id' => (int) $verseId])
                ->one();
        } catch (\Throwable) {
            return null;
        }

        return $verse instanceof Verse ? $verse : null;
    }

    private function findFileById(mixed $id): ?File
    {
        if ($id === null) {
            return null;
        }

        try {
            $file = (new ActiveQuery(File::class))
                ->andWhere(['id' => (int) $id])
                ->one();
        } catch (\Throwable) {
            return null;
        }

        return $file instanceof File ? $file : null;
    }

    private function findUserById(mixed $id): ?User
    {
        if ($id === null) {
            return null;
        }

        try {
            $user = (new ActiveQuery(User::class))
                ->andWhere(['id' => (int) $id])
                ->one();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    private function normalizeSpaceSnapshot(mixed $space): mixed
    {
        if (is_string($space)) {
            $decoded = json_decode($space, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $space;
    }
}
