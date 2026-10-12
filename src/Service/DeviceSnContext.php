<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Never silently downgrade malformed SN provenance to a password session. */
final class DeviceSnContext
{
    public static function fromArray(array $source): array
    {
        if (!array_key_exists('auth_method', $source) && !array_key_exists('device_sn_id', $source)) {
            return [];
        }

        if (($source['auth_method'] ?? null) !== 'device_sn'
            || !is_int($source['device_sn_id'] ?? null) || $source['device_sn_id'] <= 0) {
            throw new RuntimeException('Invalid device session.', 401);
        }

        return ['auth_method' => 'device_sn', 'device_sn_id' => $source['device_sn_id']];
    }
}
