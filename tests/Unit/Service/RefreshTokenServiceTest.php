<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RefreshTokenService;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * Unit tests for RefreshTokenService.
 *
 * Validates Requirements 3.2, 3.7:
 * - RefreshToken creation stores token → userId in Redis
 * - RefreshToken validation returns correct userId
 * - RefreshToken deletion removes token from Redis
 * - Tokens use the correct key prefix
 * - Tokens have a TTL (30 days)
 */
final class RefreshTokenServiceTest extends TestCase
{
    private RedisClient $redis;
    private RefreshTokenService $service;

    protected function setUp(): void
    {
        $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
        $redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
        $redisDb = (int) (getenv('REDIS_DB') ?: 1);

        $this->redis = new RedisClient([
            'scheme' => 'tcp',
            'host' => $redisHost,
            'port' => $redisPort,
            'database' => $redisDb,
        ]);

        // Clean up any leftover test keys
        $this->cleanupTestKeys();

        $this->service = new RefreshTokenService($this->redis);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestKeys();
    }

    /**
     * Test that create() returns a non-empty token string.
     * Validates: Requirement 3.7
     */
    public function testCreateReturnsNonEmptyString(): void
    {
        $token = $this->service->create(1);

        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    /**
     * Test that create() returns a 64-character hex string (32 bytes).
     * Validates: Requirement 3.7
     */
    public function testCreateReturnsHexString(): void
    {
        $token = $this->service->create(1);

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    /**
     * Test that create() generates unique tokens for each call.
     * Validates: Requirement 3.7
     */
    public function testCreateGeneratesUniqueTokens(): void
    {
        $token1 = $this->service->create(1);
        $token2 = $this->service->create(1);

        $this->assertNotSame($token1, $token2);
    }

    /**
     * Test that create() stores the token in Redis with the correct prefix.
     * Validates: Requirement 3.7
     */
    public function testCreateStoresTokenInRedis(): void
    {
        $userId = 42;
        $token = $this->service->create($userId);

        $storedValue = $this->redis->get('refresh_token:' . $token);
        $this->assertSame((string) $userId, $storedValue);
    }

    /**
     * Test that create() sets a TTL on the Redis key.
     * Validates: Requirement 3.7
     */
    public function testCreateSetsTokenTtl(): void
    {
        $token = $this->service->create(1);

        $ttl = $this->redis->ttl('refresh_token:' . $token);
        // TTL should be positive and close to 30 days (2592000 seconds)
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(2592000, $ttl);
        // Allow a small margin for test execution time
        $this->assertGreaterThan(2591990, $ttl);
    }

    /**
     * Test that validate() returns the correct userId for a valid token.
     * Validates: Requirement 3.2
     */
    public function testValidateReturnsUserIdForValidToken(): void
    {
        $userId = 123;
        $token = $this->service->create($userId);

        $result = $this->service->validate($token);

        $this->assertSame($userId, $result);
    }

    /**
     * Test that validate() returns null for a non-existent token.
     * Validates: Requirement 3.2
     */
    public function testValidateReturnsNullForNonExistentToken(): void
    {
        $result = $this->service->validate('nonexistent_token_string');

        $this->assertNull($result);
    }

    /**
     * Test that validate() returns null for an empty token.
     * Validates: Requirement 3.2
     */
    public function testValidateReturnsNullForEmptyToken(): void
    {
        $result = $this->service->validate('');

        $this->assertNull($result);
    }

    /**
     * Test that delete() removes the token from Redis.
     * Validates: Requirement 3.2
     */
    public function testDeleteRemovesToken(): void
    {
        $userId = 99;
        $token = $this->service->create($userId);

        // Verify token exists
        $this->assertSame($userId, $this->service->validate($token));

        // Delete the token
        $this->service->delete($token);

        // Verify token is gone
        $this->assertNull($this->service->validate($token));
    }

    /**
     * Test that delete() does not throw for a non-existent token.
     * Validates: Requirement 3.2
     */
    public function testDeleteDoesNotThrowForNonExistentToken(): void
    {
        // Should not throw any exception
        $this->service->delete('nonexistent_token_string');

        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    /**
     * Test the full round-trip: create → validate → delete → validate returns null.
     * Validates: Requirements 3.2, 3.7
     */
    public function testFullRoundTrip(): void
    {
        $userId = 555;

        // Create
        $token = $this->service->create($userId);
        $this->assertNotEmpty($token);

        // Validate — should return userId
        $this->assertSame($userId, $this->service->validate($token));

        // Delete
        $this->service->delete($token);

        // Validate again — should return null
        $this->assertNull($this->service->validate($token));
    }

    /**
     * Test that multiple tokens can be created for the same user.
     * Validates: Requirement 3.7
     */
    public function testMultipleTokensForSameUser(): void
    {
        $userId = 77;

        $token1 = $this->service->create($userId);
        $token2 = $this->service->create($userId);

        // Both tokens should be valid
        $this->assertSame($userId, $this->service->validate($token1));
        $this->assertSame($userId, $this->service->validate($token2));

        // Deleting one should not affect the other
        $this->service->delete($token1);
        $this->assertNull($this->service->validate($token1));
        $this->assertSame($userId, $this->service->validate($token2));
    }

    /**
     * Test deleteByUserId removes all tokens for a specific user.
     * Validates: Requirement 3.7
     */
    public function testDeleteByUserIdRemovesAllUserTokens(): void
    {
        $userId = 88;
        $otherUserId = 99;

        $token1 = $this->service->create($userId);
        $token2 = $this->service->create($userId);
        $otherToken = $this->service->create($otherUserId);

        // Delete all tokens for userId
        $this->service->deleteByUserId($userId);

        // User's tokens should be gone
        $this->assertNull($this->service->validate($token1));
        $this->assertNull($this->service->validate($token2));

        // Other user's token should still be valid
        $this->assertSame($otherUserId, $this->service->validate($otherToken));
    }

    /**
     * Clean up all refresh_token:* keys in the test Redis database.
     */
    private function cleanupTestKeys(): void
    {
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scan($cursor, ['MATCH' => 'refresh_token:*', 'COUNT' => 100]);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } while ($cursor !== '0');
    }
}
