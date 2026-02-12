<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;

/**
 * Manages RefreshToken storage in Redis.
 *
 * Replaces Yii2's Redis ActiveRecord for RefreshToken.
 * Uses Predis\Client to store token → userId mappings with a configurable TTL.
 *
 * Key format: refresh_token:{token}
 * Value: userId (as string)
 *
 * @see Requirements 3.2, 3.7
 */
final class RefreshTokenService
{
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
    ) {
    }

    /**
     * Create a new refresh token for the given user ID.
     *
     * Generates a cryptographically secure random token string,
     * stores it in Redis mapping token → userId with a TTL of 30 days.
     *
     * @param int $userId The user ID to associate with the token.
     * @return string The generated refresh token string.
     */
    public function create(int $userId): string
    {
        $token = bin2hex(random_bytes($this->tokenLength));

        $this->redis->setex(
            $this->prefix . $token,
            $this->ttl,
            (string) $userId,
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
        $userId = $this->redis->get($this->prefix . $token);

        if ($userId === null) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * Delete a refresh token from Redis.
     *
     * @param string $token The refresh token to delete.
     */
    public function delete(string $token): void
    {
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
        $cursor = '0';
        $pattern = $this->prefix . '*';

        do {
            [$cursor, $keys] = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);

            if (!empty($keys)) {
                foreach ($keys as $key) {
                    $value = $this->redis->get($key);
                    if ($value !== null && (int) $value === $userId) {
                        $this->redis->del($key);
                    }
                }
            }
        } while ($cursor !== '0');
    }
}
