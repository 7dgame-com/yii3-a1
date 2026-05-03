<?php

declare(strict_types=1);

namespace App\Model;

use Yiisoft\ActiveRecord\ActiveRecord;

/**
 * File ActiveRecord model.
 *
 * @see Requirements 8.7
 */
class File extends ActiveRecord
{
    public int $id = 0;
    public ?string $md5 = null;
    public ?string $type = null;
    public ?string $url = null;
    public ?string $filename = null;
    public ?string $key = null;
    public ?int $size = null;
    public ?int $user_id = null;
    public \DateTimeImmutable|string|null $created_at = null;

    public function getTableName(): string
    {
        return 'file';
    }

    /**
     * Filter URL: replace internal IP addresses with public-facing domain.
     * Replicates Yii2 URL filtering logic.
     */
    public function getFilteredUrl(string $publicDomain = ''): string
    {
        $url = $this->get('url') ?? '';
        if ($url === '' || $publicDomain === '') {
            return $url;
        }

        return preg_replace(
            '#https?://(192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)(:\d+)?#',
            $publicDomain,
            $url,
        ) ?? $url;
    }

    /**
     * A1 File::fields() compatible shape for nested resource serialization.
     *
     * @return array<string, mixed>
     */
    public function toA1Array(): array
    {
        return [
            'md5' => $this->get('md5'),
            'type' => $this->get('type'),
            'url' => $this->get('url'),
            'key' => $this->get('key'),
        ];
    }
}
