<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Meta (元数据) ActiveRecord model.
 *
 * @see Requirements 8.5
 */
class Meta extends ActiveRecord
{
    public int $id = 0;
    public ?string $uuid = null;
    public ?int $author_id = null;
    public ?int $updater_id = null;
    public ?int $image_id = null;
    public ?string $info = null;
    public ?string $data = null;
    public ?string $events = null;
    public int $prefab = 0;
    public \DateTimeImmutable|string|null $created_at = null;
    public \DateTimeImmutable|string|null $updated_at = null;

    public function getTableName(): string
    {
        return 'meta';
    }

    public function getAuthor(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'author_id']);
    }

    public function getImage(): ActiveQuery
    {
        return $this->hasOne(File::class, ['id' => 'image_id']);
    }

    /**
     * Data upgrade logic — replaces Yii2 afterFind behavior.
     * Upgrades old data formats to current format.
     * This operation is idempotent: f(x) = f(f(x)).
     */
    public function upgradeData(): void
    {
        $data = $this->get('data');
        if ($data === null || $data === '') {
            return;
        }

        $decoded = is_string($data) ? json_decode($data, true) : $data;
        if (!is_array($decoded)) {
            return;
        }

        $upgraded = $this->performDataUpgrade($decoded);
        $this->set('data', json_encode($upgraded, JSON_UNESCAPED_UNICODE));
    }

    public static function performDataUpgrade(array $data): array
    {
        return self::upgradeNode($data);
    }

    private static function upgradeNode(array $data): array
    {
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            if (isset($data['parameters']['transfrom'])) {
                $data['parameters']['transform'] = $data['parameters']['transfrom'];
                unset($data['parameters']['transfrom']);
            }
        }

        if (isset($data['chieldren'])) {
            $data['children'] = $data['chieldren'];
            unset($data['chieldren']);
        }

        if (isset($data['children']) && is_array($data['children'])) {
            foreach (['entities', 'addons', 'components', 'modules'] as $childKey) {
                if (isset($data['children'][$childKey]) && is_array($data['children'][$childKey])) {
                    foreach ($data['children'][$childKey] as $i => $child) {
                        if (is_array($child)) {
                            $data['children'][$childKey][$i] = self::upgradeNode($child);
                        }
                    }
                }
            }
        }

        return $data;
    }
}
