<?php

declare(strict_types=1);

namespace App\Tests\Unit\Policy;

use App\Model\User;
use App\Model\Verse;
use App\Policy\VersePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VersePolicy.
 *
 * Validates Requirements 9.3:
 * - canView, canUpdate, canDelete methods for Verse-level access control
 * - Ownership-based authorization using author_id
 */
final class VersePolicyTest extends TestCase
{
    private VersePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new VersePolicy();
    }

    // ---------------------------------------------------------------
    // canView tests
    // ---------------------------------------------------------------

    /**
     * Test canView returns true when user is the author.
     */
    public function testCanViewReturnsTrueForAuthor(): void
    {
        $user = $this->createUserMock(1);
        $verse = $this->createVerseMock(1);

        $this->assertTrue($this->policy->canView($user, $verse));
    }

    /**
     * Test canView returns true when user is NOT the author.
     * All authenticated users can view any verse.
     */
    public function testCanViewReturnsTrueForNonAuthor(): void
    {
        $user = $this->createUserMock(2);
        $verse = $this->createVerseMock(1);

        $this->assertTrue($this->policy->canView($user, $verse));
    }

    // ---------------------------------------------------------------
    // canUpdate tests
    // ---------------------------------------------------------------

    /**
     * Test canUpdate returns true when user is the author.
     */
    public function testCanUpdateReturnsTrueForAuthor(): void
    {
        $user = $this->createUserMock(5);
        $verse = $this->createVerseMock(5);

        $this->assertTrue($this->policy->canUpdate($user, $verse));
    }

    /**
     * Test canUpdate returns false when user is NOT the author.
     */
    public function testCanUpdateReturnsFalseForNonAuthor(): void
    {
        $user = $this->createUserMock(5);
        $verse = $this->createVerseMock(10);

        $this->assertFalse($this->policy->canUpdate($user, $verse));
    }

    // ---------------------------------------------------------------
    // canDelete tests
    // ---------------------------------------------------------------

    /**
     * Test canDelete returns true when user is the author.
     */
    public function testCanDeleteReturnsTrueForAuthor(): void
    {
        $user = $this->createUserMock(7);
        $verse = $this->createVerseMock(7);

        $this->assertTrue($this->policy->canDelete($user, $verse));
    }

    /**
     * Test canDelete returns false when user is NOT the author.
     */
    public function testCanDeleteReturnsFalseForNonAuthor(): void
    {
        $user = $this->createUserMock(7);
        $verse = $this->createVerseMock(99);

        $this->assertFalse($this->policy->canDelete($user, $verse));
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    /**
     * Test that canUpdate and canDelete are consistent — both depend on ownership.
     */
    public function testUpdateAndDeleteConsistentForOwner(): void
    {
        $user = $this->createUserMock(42);
        $verse = $this->createVerseMock(42);

        $this->assertTrue($this->policy->canUpdate($user, $verse));
        $this->assertTrue($this->policy->canDelete($user, $verse));
    }

    /**
     * Test that canUpdate and canDelete are consistent — both deny for non-owner.
     */
    public function testUpdateAndDeleteConsistentForNonOwner(): void
    {
        $user = $this->createUserMock(42);
        $verse = $this->createVerseMock(43);

        $this->assertFalse($this->policy->canUpdate($user, $verse));
        $this->assertFalse($this->policy->canDelete($user, $verse));
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
