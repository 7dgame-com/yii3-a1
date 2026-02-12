<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Verse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Verse model TimestampBehavior and BlameableBehavior.
 *
 * Validates Requirements 8.9:
 * - TimestampBehavior: auto-set created_at, updated_at
 * - BlameableBehavior: auto-set created_by, updated_by
 */
final class VerseTimestampBehaviorTest extends TestCase
{
    /**
     * Test that touchTimestamps sets created_at when it is null.
     */
    public function testTouchTimestampsSetsCreatedAtWhenNull(): void
    {
        $verse = new Verse();

        $verse->touchTimestamps();

        $createdAt = $verse->get('created_at');
        $updatedAt = $verse->get('updated_at');

        $this->assertNotNull($createdAt);
        $this->assertNotNull($updatedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updatedAt);
    }

    /**
     * Test that touchTimestamps does not overwrite existing created_at.
     */
    public function testTouchTimestampsPreservesExistingCreatedAt(): void
    {
        $verse = new Verse();
        $existingCreatedAt = '2023-01-01 00:00:00';
        $verse->set('created_at', $existingCreatedAt);

        $verse->touchTimestamps();

        $this->assertSame($existingCreatedAt, $verse->get('created_at'));
        $this->assertNotNull($verse->get('updated_at'));
    }

    /**
     * Test that touchTimestamps always updates updated_at.
     */
    public function testTouchTimestampsAlwaysUpdatesUpdatedAt(): void
    {
        $verse = new Verse();
        $verse->set('updated_at', '2020-01-01 00:00:00');

        $verse->touchTimestamps();

        $this->assertNotSame('2020-01-01 00:00:00', $verse->get('updated_at'));
    }

    /**
     * Test that touchTimestampsOnUpdate only sets updated_at.
     */
    public function testTouchTimestampsOnUpdateSetsUpdatedAt(): void
    {
        $verse = new Verse();

        $verse->touchTimestampsOnUpdate();

        $updatedAt = $verse->get('updated_at');
        $this->assertNotNull($updatedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updatedAt);
    }

    /**
     * Test that touchBlame sets author_id when it is null.
     */
    public function testTouchBlameSetsCreatedByWhenNull(): void
    {
        $verse = new Verse();

        $verse->touchBlame(42);

        $this->assertSame(42, $verse->get('author_id'));
        $this->assertSame(42, $verse->get('updater_id'));
    }

    /**
     * Test that touchBlame does not overwrite existing author_id.
     */
    public function testTouchBlamePreservesExistingCreatedBy(): void
    {
        $verse = new Verse();
        $verse->set('author_id', 10);

        $verse->touchBlame(42);

        $this->assertSame(10, $verse->get('author_id'));
        $this->assertSame(42, $verse->get('updater_id'));
    }

    /**
     * Test that touchBlame always updates updater_id.
     */
    public function testTouchBlameAlwaysUpdatesUpdatedBy(): void
    {
        $verse = new Verse();
        $verse->set('updater_id', 10);

        $verse->touchBlame(42);

        $this->assertSame(42, $verse->get('updater_id'));
    }
}
