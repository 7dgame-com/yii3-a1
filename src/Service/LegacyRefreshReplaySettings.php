<?php

declare(strict_types=1);

namespace App\Service;

final class LegacyRefreshReplaySettings
{
    public readonly ?string $key;

    public function __construct(public readonly bool $enabled = false, string $encryptionKey = '')
    {
        $key = $encryptionKey === '' ? null : base64_decode(strtr($encryptionKey, '-_', '+/'), true);
        if ($encryptionKey !== '' && (!is_string($key) || strlen($key) !== 32
            || rtrim(strtr(base64_encode($key), '+/', '-_'), '=') !== $encryptionKey)) {
            throw new \InvalidArgumentException('Y1_REFRESH_REPLAY_KEY must canonically encode 32 bytes as unpadded base64url.');
        }
        if ($enabled && $key === null) {
            throw new \InvalidArgumentException('Y1_REFRESH_REPLAY_ENABLED requires a stable server-side replay key.');
        }
        $this->key = $key;
    }
}
