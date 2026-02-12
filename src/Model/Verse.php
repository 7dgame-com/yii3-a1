<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Verse (场景) ActiveRecord model.
 *
 * @see Requirements 8.2, 8.9
 */
class Verse extends ActiveRecord
{
    public int $id = 0;
    public string $name = '';
    public ?string $description = null;
    public ?string $uuid = null;
    public ?int $author_id = null;
    public ?int $updater_id = null;
    public ?int $image_id = null;
    public int $version = 0;
    public ?string $info = null;
    public ?string $data = null;
    public \DateTimeImmutable|string|null $created_at = null;
    public \DateTimeImmutable|string|null $updated_at = null;

    public function getTableName(): string
    {
        return 'verse';
    }

    public function getAuthor(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'author_id']);
    }

    public function getImage(): ActiveQuery
    {
        return $this->hasOne(File::class, ['id' => 'image_id']);
    }

    public function getMetas(): ActiveQuery
    {
        return $this->hasMany(Meta::class, ['verse_id' => 'id']);
    }

    public function getManagers(): ActiveQuery
    {
        return $this->hasMany(Manager::class, ['verse_id' => 'id']);
    }

    public function getVerseCode(): ActiveQuery
    {
        return $this->hasOne(VerseCode::class, ['verse_id' => 'id']);
    }

    public function getProperties(): ActiveQuery
    {
        return $this->hasMany(Property::class, ['id' => 'property_id'])
            ->viaTable('verse_property', ['verse_id' => 'id']);
    }

    public function getTags(): ActiveQuery
    {
        return $this->hasMany(Tags::class, ['id' => 'tags_id'])
            ->viaTable('verse_tags', ['verse_id' => 'id']);
    }

    public function relationQuery(string $name): ActiveQuery
    {
        return match ($name) {
            'author' => $this->hasOne(User::class, ['id' => 'author_id']),
            'image' => $this->hasOne(File::class, ['id' => 'image_id']),
            'metas' => $this->hasMany(Meta::class, ['verse_id' => 'id']),
            'managers' => $this->hasMany(Manager::class, ['verse_id' => 'id']),
            'verseCode' => $this->hasOne(VerseCode::class, ['verse_id' => 'id']),
            default => parent::relationQuery($name),
        };
    }

    /**
     * Override insert to automatically apply TimestampBehavior.
     *
     * @see Requirements 8.9
     */
    public function insert(?array $properties = null): void
    {
        $this->touchTimestamps();
        parent::insert($properties);
    }

    /**
     * Override update to automatically apply TimestampBehavior.
     *
     * @see Requirements 8.9
     */
    public function update(?array $properties = null): int
    {
        $this->touchTimestampsOnUpdate();
        return parent::update($properties);
    }

    public function touchTimestamps(): void
    {
        $now = date('Y-m-d H:i:s');
        if ($this->get('created_at') === null) {
            $this->set('created_at', $now);
        }
        $this->set('updated_at', $now);
    }

    public function touchTimestampsOnUpdate(): void
    {
        $this->set('updated_at', date('Y-m-d H:i:s'));
    }

    public function touchBlame(int $userId): void
    {
        if ($this->get('author_id') === null) {
            $this->set('author_id', $userId);
        }
        $this->set('updater_id', $userId);
    }
}
