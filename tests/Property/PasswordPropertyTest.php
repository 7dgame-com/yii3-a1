<?php

declare(strict_types=1);

namespace App\Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for User password validation.
 *
 * Feature: yii2-to-yii3-migration, Property 14: User 密码验证 round-trip
 *
 * **Validates: Requirements 8.1**
 *
 * Property 14: For any non-empty password string, hashing with password_hash
 * and then verifying with password_verify should return true. For any different
 * password, password_verify should return false.
 *
 * This tests the core logic that User::validatePassword delegates to.
 */
final class PasswordPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 14a: password_hash → password_verify round-trip returns true.
     *
     * For any non-empty password string, password_verify(password, password_hash(password))
     * must return true. This is the positive round-trip that User::validatePassword relies on.
     *
     * **Validates: Requirements 8.1**
     *
     * @eris-repeat 100
     */
    public function testPasswordHashVerifyRoundTrip(): void
    {
        $this->forAll(
            Generator\string()
        )
            ->when(static fn (string $password): bool => $password !== '')
            ->then(function (string $password): void {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $this->assertTrue(
                    password_verify($password, $hash),
                    'password_verify must return true for the original password after hashing'
                );
            });
    }

    /**
     * Property 14b: password_verify rejects different passwords.
     *
     * For any two distinct non-empty passwords, hashing the first and verifying
     * with the second must return false.
     *
     * **Validates: Requirements 8.1**
     *
     * @eris-repeat 100
     */
    public function testPasswordVerifyRejectsDifferentPassword(): void
    {
        $this->forAll(
            Generator\string(),
            Generator\string()
        )
            ->when(static fn (string $a, string $b): bool => $a !== '' && $b !== '' && $a !== $b)
            ->then(function (string $original, string $different): void {
                $hash = password_hash($original, PASSWORD_DEFAULT);

                $this->assertFalse(
                    password_verify($different, $hash),
                    'password_verify must return false for a different password'
                );
            });
    }
}
