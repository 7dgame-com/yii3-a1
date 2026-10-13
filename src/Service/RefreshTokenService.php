<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;

/**
 * Manages RefreshToken storage in Redis.
 *
 * Replaces Yii2's Redis ActiveRecord for RefreshToken.
 * Uses Predis\Client to store token sessions with a configurable TTL.
 *
 * Key format: refresh_token:{token}
 * Value: userId (legacy string), or versioned JSON with userId and SN provenance.
 *
 * @see Requirements 3.2, 3.7
 */
final class RefreshTokenService
{
    private const CONSUME_SCRIPT = <<<'LUA'
local user_id = redis.call('GET', KEYS[1])
if not user_id then
    return false
end

redis.call('DEL', KEYS[1])
return user_id
LUA;

    /**
     * Redis key prefix for refresh tokens.
     */
    private string $prefix = 'refresh_token:';

    /**
     * Token time-to-live in seconds (30 days).
     */
    private int $ttl = 2592000;

    /**
     * Token length in bytes (32 bytes = 64 hex characters).
     */
    private int $tokenLength = 32;

    public function __construct(
        private RedisClient $redis,
        private readonly ?IdentityAuthorityClient $identityAuthority = null,
        private readonly ?LegacyRefreshReplayService $legacyRefreshReplay = null,
    ) {
    }

    /**
     * Create a new refresh token for the given user ID.
     *
     * Generates a cryptographically secure random token string,
     * stores its identity and optional SN provenance with a TTL of 30 days.
     *
     * @param int $userId The user ID to associate with the token.
     * @return string The generated refresh token string.
     */
    public function create(int $userId, array $authContext = [], ?string $expectedFingerprint = null): string
    {
        $this->assertLegacy();
        $context = DeviceSnContext::fromArray($authContext);
        $token = ($context === [] ? '' : 'dsn_') . bin2hex(random_bytes($this->tokenLength));

        if ($this->legacyRefreshReplay?->enabled()) {
            $this->legacyRefreshReplay->create($token, $userId, $context, $expectedFingerprint);
            return $token;
        }

        $this->redis->setex(
            $this->prefix . $token,
            $this->ttl,
            $context === [] ? (string) $userId : json_encode(['version' => 1, 'user_id' => $userId] + $context, JSON_THROW_ON_ERROR),
        );

        return $token;
    }

    /**
     * Validate a refresh token and return the associated user ID.
     *
     * Looks up the token in Redis and returns the userId if found.
     *
     * @param string $token The refresh token to validate.
     * @return int|null The associated user ID, or null if the token is invalid/expired.
     */
    public function validate(string $token): ?int
    {
        $this->assertLegacy();
        if ($this->legacyRefreshReplay?->enabled()) return $this->legacyRefreshReplay->validate($token);
        $userId = $this->redis->get($this->prefix . $token);

        if ($userId === null) {
            return null;
        }

        $session = $this->decodeSession($userId, $token);
        // Identity-only consumers must not shed SN restrictions.
        return isset($session['auth_method']) ? null : ($session['user_id'] ?? null);
    }

    /**
     * Atomically read and delete a refresh token.
     *
     * This is the rotation boundary: only one concurrent request can consume
     * a token successfully.
     */
    public function consume(string $token): ?int
    {
        $session = $this->consumeSession($token);
        if (isset($session['auth_method'])) {
            throw new \RuntimeException('Device session requires provenance-aware refresh.', 401);
        }
        return $session['user_id'] ?? null;
    }

    /** Atomically rotate both identity and authentication provenance. */
    public function consumeSession(string $token): ?array
    {
        $this->assertLegacy();
        if ($this->legacyRefreshReplay?->enabled()) {
            throw new \RuntimeException('Atomic refresh replay must use the provenance-aware rotation boundary.', 503);
        }
        $result = $this->redis->eval(
            self::CONSUME_SCRIPT,
            1,
            $this->prefix . $token,
        );

        if (!is_string($result)) {
            return null;
        }

        return $this->decodeSession($result, $token);
    }

    private function decodeSession(string $value, string $token): ?array
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) === 1 && !str_starts_with($token, 'dsn_')) {
            return ['user_id' => (int) $value];
        }
        $session = json_decode($value, true);
        if (!is_array($session) || ($session['version'] ?? null) !== 1
            || !is_int($session['user_id'] ?? null) || $session['user_id'] <= 0
            || !str_starts_with($token, 'dsn_')) {
            throw new \RuntimeException('Refresh token is invalid.', 401);
        }
        $context = DeviceSnContext::fromArray($session);
        if ($context === []) {
            throw new \RuntimeException('Refresh token is invalid.', 401);
        }
        return ['user_id' => $session['user_id']] + $context;
    }

    /**
     * Delete a refresh token from Redis.
     *
     * @param string $token The refresh token to delete.
     */
    public function delete(string $token): void
    {
        $this->assertLegacy();
        if ($this->legacyRefreshReplay?->enabled()) {
            $this->legacyRefreshReplay->revoke($token);
            return;
        }
        $this->redis->del($this->prefix . $token);
    }

    /**
     * Delete all refresh tokens for a given user ID.
     *
     * Scans Redis for all keys matching the prefix and removes those
     * whose value matches the given user ID.
     *
     * Note: This is an O(N) operation and should be used sparingly.
     *
     * @param int $userId The user ID whose tokens should be deleted.
     */
    public function deleteByUserId(int $userId): void
    {
        $this->assertLegacy();
        if ($this->legacyRefreshReplay?->enabled()) {
            $this->legacyRefreshReplay->revokeUser($userId, fn () => $this->removeUserKeys($userId));
            return;
        }
        $this->removeUserKeys($userId);
    }

    private function removeUserKeys(int $userId): void
    {
        // Full, read-only namespace preflight before deleting any Y1 key. AR
        // hashes/counter/list are isolated, never GET as strings or mutated.
        $inventory = LegacyRefreshNamespaceInspector::scan($this->redis);
        $keys = []; $values = [];
        foreach ($inventory['tokens'] as $token) {
            if ($token['userId'] !== $userId) continue;
            $keys[] = $this->prefix . $token['raw']; $values[] = $token['value'];
        }
        if ($keys === []) return;
        try {
            $result = $this->redis->eval(<<<'LUA'
-- y1-namespace-compare-delete-v1
-- Compare every target before the first delete, including a late type/owner swap.
for i, key in ipairs(KEYS) do
    local kind = redis.call('TYPE', key).ok
    if kind ~= 'none' and (kind ~= 'string' or redis.call('GET', key) ~= ARGV[i]) then return -1 end
end
local removed = 0
for _, key in ipairs(KEYS) do removed = removed + redis.call('DEL', key) end
return removed
LUA, count($keys), ...array_merge($keys, $values));
            if (!is_int($result) || $result < 0) throw new \RuntimeException();
        } catch (\Throwable) {
            throw new \RuntimeException('Refresh namespace changed during revocation.', 503);
        }
    }

    private function assertLegacy(): void
    {
        if ($this->identityAuthority?->enabled()) throw new \RuntimeException('Local refresh session storage is retired.', 503);
    }
}
