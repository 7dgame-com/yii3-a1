<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Phototype;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Query service for A1-compatible phototype endpoints.
 */
class PhototypeQueryService
{
    /**
     * Find phototype info by type using the A1 response shape.
     *
     * @return array<string, mixed>|null
     */
    public function findInfoByType(string $type): ?array
    {
        $phototype = (new ActiveQuery(Phototype::class))
            ->where(['type' => $type])
            ->one();

        return $phototype instanceof Phototype ? $phototype->toInfoArray() : null;
    }
}
