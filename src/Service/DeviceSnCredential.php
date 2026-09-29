<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Login-only credential handling. The y1 service never decrypts stored SNs. */
final class DeviceSnCredential
{
    public static function normalize(string $value): string
    {
        if (strlen($value) > 128) {
            throw new RuntimeException('Invalid SN format.', 400);
        }

        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', $value));
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{16}$/D', $normalized) !== 1) {
            throw new RuntimeException('Invalid SN format.', 400);
        }

        return $normalized;
    }

    public static function digest(string $value): string
    {
        return hash('sha256', self::normalize($value));
    }
}
