<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** The authenticated source cache stores one canonical pair shared by v1/v2. */
final class LegacyIdentityRefreshResponse
{
    public static function body(mixed $legacy, array $tokens, int $ownerId): array
    {
        self::keys($legacy, ['version', 'consumer', 'endpoint', 'body']);
        if ($legacy['version'] !== 'legacy-refresh-response-v1' || $legacy['consumer'] !== 'yii3'
            || $legacy['endpoint'] !== 'y1-refresh-pair') self::fail();
        $body = $legacy['body'];
        self::keys($body, ['token', 'nickname', 'user']);
        self::keys($body['token'], ['accessToken', 'refreshToken', 'expires']);
        self::keys($body['user'], ['id', 'username', 'nickname', 'fixture']);
        if ($body['token']['accessToken'] !== $tokens['accessToken'] || $body['token']['refreshToken'] !== $tokens['refreshToken']
            || $body['user']['id'] !== $ownerId || $body['user']['fixture'] !== false) self::fail();
        foreach ([$body['nickname'], $body['user']['username'], $body['user']['nickname']] as $value) {
            if (!is_string($value) || strlen($value) > 4096 || preg_match('//u', $value) !== 1) self::fail();
        }
        $expires = $body['token']['expires'];
        if (!is_string($expires) || strlen($expires) !== 19) self::fail();
        // Keep the source display date. Authority already checked JWT exp and
        // returned its ISO equivalent separately; do not guess a timezone here.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expires, new \DateTimeZone('UTC'));
        if ($date === false || \DateTimeImmutable::getLastErrors() !== false || $date->format('Y-m-d H:i:s') !== $expires
            || strlen(json_encode($body, JSON_THROW_ON_ERROR)) > 65536) self::fail();
        return $body;
    }

    private static function keys(mixed $value, array $expected): void
    {
        if (!is_array($value)) self::fail();
        $actual = array_keys($value); sort($actual); sort($expected);
        if ($actual !== $expected) self::fail();
    }

    private static function fail(): never { throw new RuntimeException('Identity returned invalid legacy response data.', 503); }
}
