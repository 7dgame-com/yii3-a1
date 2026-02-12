<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Model\User;
use App\Model\Verse;
use App\Policy\VersePolicy;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for VersePolicy authorization correctness.
 *
 * Feature: yii2-to-yii3-migration, Property 17: VersePolicy 权限正确性
 *
 * **Validates: Requirements 9.3**
 *
 * Property 17: For any user and Verse combination, when the user is the Verse's
 * author, canUpdate and canDelete should return true; when the user is NOT the
 * author, they should return false. canView always returns true.
 */
final class VersePolicyPropertyTest extends TestCase
{
    use TestTrait;

    private VersePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new VersePolicy();
    }

    /**
     * Property 17a: Owner can update and delete their own verse.
     *
     * For any positive integer user ID, when userId === authorId,
     * canUpdate and canDelete must both return true.
     *
     * **Validates: Requirements 9.3**
     *
     * @eris-repeat 100
     */
    public function testOwnerCanUpdateAndDelete(): void
    {
        $this->forAll(
            Generator\choose(1, 1_000_000)
        )
            ->then(function (int $userId): void {
                $user = $this->createUserMock($userId);
                $verse = $this->createVerseMock($userId); // same ID = owner

                $this->assertTrue(
                    $this->policy->canUpdate($user, $verse),
                    "Owner (userId={$userId}) must be able to update their verse"
                );
                $this->assertTrue(
                    $this->policy->canDelete($user, $verse),
                    "Owner (userId={$userId}) must be able to delete their verse"
                );
            });
    }

    /**
     * Property 17b: Non-owner cannot update or delete.
     *
     * For any two distinct positive integer IDs (userId !== authorId),
     * canUpdate and canDelete must both return false.
     *
     * **Validates: Requirements 9.3**
     *
     * @eris-repeat 100
     */
    public function testNonOwnerCannotUpdateOrDelete(): void
    {
        $this->forAll(
            Generator\choose(1, 1_000_000),
            Generator\choose(1, 1_000_000)
        )
            ->when(function (int $userId, int $authorId): bool {
                return $userId !== $authorId;
            })
            ->then(function (int $userId, int $authorId): void {
                $user = $this->createUserMock($userId);
                $verse = $this->createVerseMock($authorId);

                $this->assertFalse(
                    $this->policy->canUpdate($user, $verse),
                    "Non-owner (userId={$userId}, authorId={$authorId}) must NOT update"
                );
                $this->assertFalse(
                    $this->policy->canDelete($user, $verse),
                    "Non-owner (userId={$userId}, authorId={$authorId}) must NOT delete"
                );
            });
    }

    /**
     * Property 17c: canView always returns true regardless of ownership.
     *
     * For any userId and authorId (same or different), canView must return true.
     *
     * **Validates: Requirements 9.3**
     *
     * @eris-repeat 100
     */
    public function testCanViewAlwaysTrue(): void
    {
        $this->forAll(
            Generator\choose(1, 1_000_000),
            Generator\choose(1, 1_000_000)
        )
            ->then(function (int $userId, int $authorId): void {
                $user = $this->createUserMock($userId);
                $verse = $this->createVerseMock($authorId);

                $this->assertTrue(
                    $this->policy->canView($user, $verse),
                    "canView must always return true (userId={$userId}, authorId={$authorId})"
                );
            });
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    private function createUserMock(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('get')
            ->willReturnCallback(function (string $field) use ($id) {
                return match ($field) {
                    'id' => $id,
                    default => null,
                };
            });

        return $user;
    }

    private function createVerseMock(int $authorId): Verse
    {
        $verse = $this->createMock(Verse::class);
        $verse->method('get')
            ->willReturnCallback(function (string $field) use ($authorId) {
                return match ($field) {
                    'author_id' => $authorId,
                    default => null,
                };
            });

        return $verse;
    }
}
