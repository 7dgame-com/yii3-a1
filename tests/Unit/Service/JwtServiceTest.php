<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JwtService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Unit tests for JwtService.
 *
 * Validates Requirements 3.1, 3.6:
 * - JWT token generation with HS256 signing
 * - Token contains user_id claim
 * - Token has 3-hour expiry
 * - Token validation (signature + expiry)
 * - Token parsing returns user identity
 */
final class JwtServiceTest extends TestCase
{
    private string $keyFilePath;
    private JwtService $jwtService;

    protected function setUp(): void
    {
        // Create a temporary key file for testing
        $this->keyFilePath = tempnam(sys_get_temp_dir(), 'jwt_test_key_');
        file_put_contents($this->keyFilePath, 'test-secret-key-for-jwt-signing-minimum-length');

        $this->jwtService = new JwtService($this->keyFilePath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }
    }

    /**
     * Test that generateToken returns a non-empty string.
     * Validates: Requirement 3.1
     */
    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = $this->jwtService->generateToken(42);

        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    /**
     * Test that generated token is a valid JWT format (3 dot-separated parts).
     * Validates: Requirement 3.6
     */
    public function testGenerateTokenReturnsValidJwtFormat(): void
    {
        $token = $this->jwtService->generateToken(1);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT should have 3 parts separated by dots');
    }

    /**
     * Test that parseToken returns correct user_id for a valid token.
     * Validates: Requirement 3.1
     */
    public function testParseTokenReturnsUserId(): void
    {
        $userId = 123;
        $token = $this->jwtService->generateToken($userId);

        $result = $this->jwtService->parseToken($token);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertSame($userId, $result['user_id']);
    }

    /**
     * Test that validateToken returns true for a freshly generated token.
     * Validates: Requirement 3.6
     */
    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $token = $this->jwtService->generateToken(1);

        $this->assertTrue($this->jwtService->validateToken($token));
    }

    /**
     * Test that parseToken returns null for an invalid token string.
     * Validates: Requirement 3.6
     */
    public function testParseTokenReturnsNullForInvalidToken(): void
    {
        $result = $this->jwtService->parseToken('invalid.token.string');

        $this->assertNull($result);
    }

    /**
     * Test that validateToken returns false for an invalid token string.
     * Validates: Requirement 3.6
     */
    public function testValidateTokenReturnsFalseForInvalidToken(): void
    {
        $this->assertFalse($this->jwtService->validateToken('invalid.token.string'));
    }

    /**
     * Test that validateToken returns false for an empty string.
     * Validates: Requirement 3.6
     */
    public function testValidateTokenReturnsFalseForEmptyString(): void
    {
        $this->assertFalse($this->jwtService->validateToken(''));
    }

    /**
     * Test that parseToken returns null for an empty string.
     * Validates: Requirement 3.6
     */
    public function testParseTokenReturnsNullForEmptyString(): void
    {
        $this->assertNull($this->jwtService->parseToken(''));
    }

    /**
     * Test that a token signed with a different key fails validation.
     * Validates: Requirement 3.6
     */
    public function testTokenSignedWithDifferentKeyFailsValidation(): void
    {
        // Create a second JwtService with a different key
        $otherKeyFile = tempnam(sys_get_temp_dir(), 'jwt_test_key_other_');
        file_put_contents($otherKeyFile, 'a-completely-different-secret-key-for-testing');

        try {
            $otherService = new JwtService($otherKeyFile);
            $token = $otherService->generateToken(1);

            // Validate with the original service (different key)
            $this->assertFalse($this->jwtService->validateToken($token));
            $this->assertNull($this->jwtService->parseToken($token));
        } finally {
            unlink($otherKeyFile);
        }
    }

    /**
     * Test that an expired token fails validation.
     * Validates: Requirement 3.1 (3-hour expiry)
     */
    public function testExpiredTokenFailsValidation(): void
    {
        // Create a clock that returns a time in the past (4 hours ago)
        $pastClock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('-4 hours', new DateTimeZone('Asia/Shanghai'));
            }
        };

        $pastService = new JwtService($this->keyFilePath, $pastClock);
        $token = $pastService->generateToken(1);

        // Validate with the default (current time) service — token should be expired
        $this->assertFalse($this->jwtService->validateToken($token));
        $this->assertNull($this->jwtService->parseToken($token));
    }

    /**
     * Test that different user IDs produce different tokens.
     * Validates: Requirement 3.1
     */
    public function testDifferentUserIdsProduceDifferentTokens(): void
    {
        $token1 = $this->jwtService->generateToken(1);
        $token2 = $this->jwtService->generateToken(2);

        $this->assertNotSame($token1, $token2);
    }

    /**
     * Test that parseToken round-trips correctly for various user IDs.
     * Validates: Requirement 3.1
     */
    public function testParseTokenRoundTripsForVariousUserIds(): void
    {
        foreach ([1, 100, 999999, PHP_INT_MAX] as $userId) {
            $token = $this->jwtService->generateToken($userId);
            $result = $this->jwtService->parseToken($token);

            $this->assertNotNull($result, "parseToken should succeed for userId: $userId");
            $this->assertSame($userId, $result['user_id'], "user_id should match for userId: $userId");
        }
    }

    /**
     * Test that a tampered token fails validation.
     * Validates: Requirement 3.6
     */
    public function testTamperedTokenFailsValidation(): void
    {
        $token = $this->jwtService->generateToken(1);

        // Tamper with the payload (second part)
        $parts = explode('.', $token);
        $parts[1] = $parts[1] . 'tampered';
        $tamperedToken = implode('.', $parts);

        $this->assertFalse($this->jwtService->validateToken($tamperedToken));
        $this->assertNull($this->jwtService->parseToken($tamperedToken));
    }

    /**
     * Test that the generated token uses HS256 algorithm.
     * Validates: Requirement 3.6
     */
    public function testTokenUsesHs256Algorithm(): void
    {
        $token = $this->jwtService->generateToken(1);

        // Decode the header (first part) to check the algorithm
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('HS256', $header['alg']);
    }
}
