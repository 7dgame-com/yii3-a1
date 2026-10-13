<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;

/** No passwords or refresh credentials are written to logs or exceptions. */
class LegacyRefreshReplayStore
{
    public const PREFIX = 'y1:refresh-replay:v1:';
    private string $script;

    public function __construct(private readonly Client $redis, private readonly LegacyRefreshReplaySettings $settings)
    {
        $this->script = (string) file_get_contents(__DIR__ . '/refresh-replay.lua');
    }

    public function enabled(): bool { return $this->settings->enabled; }

    public function inspect(string $raw, ?string $requestHash = null): array
    {
        return $this->run('inspect', ['raw' => $raw, 'hash' => hash('sha256', $raw), 'request_hash' => $requestHash]);
    }

    public function epoch(int $userId): int
    {
        return $this->run('epoch', ['user_id' => $userId])['epoch'];
    }

    public function advanceEpoch(int $userId): void
    {
        $this->run('advance-epoch', ['user_id' => $userId]);
    }

    public function create(string $raw, int $userId, array $context, string $fingerprint, string $accountEpoch): void
    {
        $result = $this->run('create', [
            'raw' => $raw, 'hash' => hash('sha256', $raw), 'user_id' => $userId,
            'user_epoch' => $this->epoch($userId), 'family_id' => bin2hex(random_bytes(16)),
            'account_fingerprint' => $fingerprint, 'account_epoch' => $accountEpoch, 'auth_context' => (object) $context,
            'value' => self::sessionValue($userId, $context),
        ]);
        if ($result['status'] !== 'ok') throw new \RuntimeException('Refresh storage changed; retry the request.', 503);
    }

    public function rotate(string $raw, array $snapshot, string $successor, array $response, ?string $requestHash): array
    {
        $meta = $snapshot['metadata'];
        $successorHash = hash('sha256', $successor);
        $encrypted = $this->encrypt($response, $meta, $successorHash);
        $result = $this->run('rotate', [
            'raw' => $raw, 'hash' => hash('sha256', $raw), 'request_hash' => $requestHash,
            'expected_metadata' => $snapshot['encoded'], 'account_fingerprint' => $meta['account_fingerprint'], 'account_epoch' => $meta['account_epoch'],
            'successor' => $successor, 'successor_hash' => $successorHash, 'encrypted_response' => $encrypted,
        ]);
        if ($result['status'] === 'conflict') throw new \RuntimeException('Refresh storage changed; retry the request.', 503);
        return $this->response($result);
    }

    public function response(array $snapshot): array
    {
        if ($snapshot['status'] !== 'replay') throw new \RuntimeException('Refresh token is invalid.', 401);
        $meta = $snapshot['metadata'];
        $replay = $snapshot['replay'];
        try {
            $blob = self::decodeBase64($replay['encrypted_response']);
            if (strlen($blob) < 29) throw new \UnexpectedValueException();
            $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
                substr($blob, 0, 12), substr($blob, 12, 16), $this->aad($meta, $replay['successor_hash']));
            if (!is_string($plain)) throw new \UnexpectedValueException();
            $response = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            $this->assertResponse($response, $meta['user_id'], $replay['successor_hash']);
            return $response;
        } catch (\Throwable) {
            throw new \RuntimeException('Refresh replay storage is unavailable.', 503);
        }
    }

    public function revoke(string $raw): void
    {
        $this->run('revoke', ['raw' => $raw, 'hash' => hash('sha256', $raw)]);
    }

    /** Offline review only: callers never print this credential-bearing result. */
    public function capture(string $raw): array
    {
        return $this->run('capture', ['raw' => $raw, 'hash' => hash('sha256', $raw)]);
    }

    public function backfill(string $raw, string $value, array $reviewed): void
    {
        $result = $this->run('backfill', [
            'raw' => $raw, 'hash' => hash('sha256', $raw), 'value' => $value,
            'user_id' => $reviewed['userId'], 'user_epoch' => $reviewed['userEpoch'],
            'family_id' => $reviewed['familyId'], 'account_fingerprint' => $reviewed['accountFingerprint'], 'account_epoch' => $reviewed['accountEpoch'],
            'auth_context' => (object) $reviewed['authContext'], 'expires_at_ms' => $reviewed['expiresAtMs'],
        ]);
        if ($result['status'] !== 'ok') throw new \RuntimeException('Reviewed refresh source changed; no automatic retry is allowed.', 409);
    }

    public static function decodeSession(string $value, string $token): array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) === 1 && !str_starts_with($token, 'dsn_')) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9007199254740991]]);
            if ($id !== false) return ['user_id' => $id];
        }
        $session = json_decode($value, true);
        if (!is_array($session) || ($session['version'] ?? null) !== 1 || !str_starts_with($token, 'dsn_')
            || array_keys($session) !== ['version', 'user_id', 'auth_method', 'device_sn_id']
            || !is_int($session['user_id'] ?? null) || $session['user_id'] <= 0 || $session['user_id'] > 9007199254740991) {
            throw new \RuntimeException('Refresh token is invalid.', 401);
        }
        $context = DeviceSnContext::fromArray($session);
        if ($context === []) throw new \RuntimeException('Refresh token is invalid.', 401);
        return ['user_id' => $session['user_id']] + $context;
    }

    private static function sessionValue(int $userId, array $context): string
    {
        return $context === [] ? (string) $userId : json_encode(['version' => 1, 'user_id' => $userId] + $context, JSON_THROW_ON_ERROR);
    }

    private function run(string $operation, array $arguments): array
    {
        try {
            $result = json_decode((string) $this->redis->eval($this->script, 0, $operation,
                json_encode($arguments, JSON_THROW_ON_ERROR)), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !is_string($result['status'] ?? null)) throw new \UnexpectedValueException();
            return $result;
        } catch (\Throwable) {
            // Do not expose Predis's command arguments: they can contain credentials.
            throw new \RuntimeException('Refresh storage is unavailable.', 503);
        }
    }

    private function encrypt(array $response, array $meta, string $successorHash): string
    {
        $this->assertResponse($response, $meta['user_id'], $successorHash);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $this->aad($meta, $successorHash), 16);
        if (!is_string($cipher)) throw new \RuntimeException('Refresh replay storage is unavailable.', 503);
        return rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
    }

    private function aad(array $meta, string $successorHash): string
    {
        return json_encode(['y1-refresh-response-v1', $meta['credential_hash'], $successorHash,
            $meta['user_id'], $meta['family_id'], $meta['user_epoch'], $meta['account_fingerprint'], $meta['account_epoch']], JSON_THROW_ON_ERROR);
    }

    private function key(): string
    {
        return $this->settings->key ?? throw new \RuntimeException('Refresh replay storage is unavailable.', 503);
    }

    private static function decodeBase64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded) || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) throw new \UnexpectedValueException();
        return $decoded;
    }

    private function assertResponse(mixed $value, int $userId, string $successorHash): void
    {
        if (!is_array($value) || array_diff(array_keys($value), ['token', 'nickname', 'user']) || count($value) !== 3
            || !is_string($value['nickname'] ?? null) || strlen($value['nickname']) > 4096
            || !is_array($value['token'] ?? null) || array_keys($value['token']) !== ['accessToken', 'expires', 'refreshToken']
            || !is_string($value['token']['accessToken']) || strlen($value['token']['accessToken']) > 16384
            || !is_string($value['token']['expires']) || strlen($value['token']['expires']) > 64
            || !is_string($value['token']['refreshToken']) || hash('sha256', $value['token']['refreshToken']) !== $successorHash
            || !is_array($value['user'] ?? null) || array_keys($value['user']) !== ['id', 'username', 'nickname', 'fixture']
            || $value['user']['id'] !== $userId || !is_string($value['user']['username']) || preg_match('/\A.{0,255}\z/us', $value['user']['username']) !== 1
            || !is_string($value['user']['nickname']) || strlen($value['user']['nickname']) > 4096 || $value['user']['fixture'] !== false) {
            throw new \RuntimeException('Refresh response cannot be replayed safely.', 503);
        }
        try {
            // The Lua envelope permits 32768 base64url bytes. With its 12-byte
            // IV and 16-byte tag, 24548 is the largest bounded plaintext.
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > 24548) throw new \UnexpectedValueException();
        } catch (\Throwable) {
            throw new \RuntimeException('Refresh response cannot be replayed safely.', 503);
        }
    }
}
