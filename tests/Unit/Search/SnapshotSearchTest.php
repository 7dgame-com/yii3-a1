<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Model\Snapshot;
use App\Search\SnapshotSearch;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Unit tests for SnapshotSearch service class.
 *
 * Validates Requirements 8.10, 4.2, 4.3, 4.4, 4.5, 4.9
 */
final class SnapshotSearchTest extends TestCase
{
    private SnapshotSearch $search;

    protected function setUp(): void
    {
        // Set up a mock connection in ConnectionProvider so ActiveQuery can be constructed
        if (!ConnectionProvider::has()) {
            $connection = $this->createMock(ConnectionInterface::class);
            ConnectionProvider::set($connection);
        }

        $this->search = new SnapshotSearch();
    }

    public function testSearchPublicReturnsActiveQuery(): void
    {
        $query = $this->search->searchPublic();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchPublicJoinsPropertyTable(): void
    {
        $query = $this->search->searchPublic();

        $joins = $query->getJoins();
        $this->assertNotEmpty($joins, 'searchPublic should have joins');

        // Should have 3 inner joins: verse, verse_property, property
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse', $joinTables);
        $this->assertContains('verse_property', $joinTables);
        $this->assertContains('property', $joinTables);
    }

    public function testSearchPublicFiltersOnPublicKey(): void
    {
        $query = $this->search->searchPublic();

        $where = $query->getWhere();
        $this->assertNotNull($where, 'searchPublic should have a WHERE clause');

        // The where clause should contain property.key = 'public'
        $whereStr = json_encode($where);
        $this->assertStringContainsString('public', $whereStr);
    }

    public function testSearchCheckinReturnsActiveQuery(): void
    {
        $query = $this->search->searchCheckin();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchCheckinJoinsPropertyTable(): void
    {
        $query = $this->search->searchCheckin();

        $joins = $query->getJoins();
        $this->assertNotEmpty($joins, 'searchCheckin should have joins');

        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse', $joinTables);
        $this->assertContains('verse_property', $joinTables);
        $this->assertContains('property', $joinTables);
    }

    public function testSearchCheckinFiltersOnCheckinKey(): void
    {
        $query = $this->search->searchCheckin();

        $where = $query->getWhere();
        $this->assertNotNull($where, 'searchCheckin should have a WHERE clause');

        $whereStr = json_encode($where);
        $this->assertStringContainsString('checkin', $whereStr);
    }

    public function testSearchPrivateReturnsActiveQuery(): void
    {
        $query = $this->search->searchPrivate(42);

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchPrivateFiltersOnAuthorId(): void
    {
        $userId = 42;
        $query = $this->search->searchPrivate($userId);

        $where = $query->getWhere();
        $this->assertNotNull($where, 'searchPrivate should have a WHERE clause');

        $whereStr = json_encode($where);
        $this->assertStringContainsString((string) $userId, $whereStr);
    }

    public function testSearchPrivateJoinsVerseTable(): void
    {
        $query = $this->search->searchPrivate(1);

        $joins = $query->getJoins();
        $this->assertNotEmpty($joins, 'searchPrivate should have joins');

        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse', $joinTables);
    }

    public function testSearchGroupReturnsActiveQuery(): void
    {
        $query = $this->search->searchGroup(42);

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchGroupJoinsGroupTables(): void
    {
        $userId = 42;
        $query = $this->search->searchGroup($userId);

        $joins = $query->getJoins();
        $this->assertNotEmpty($joins, 'searchGroup should have joins');

        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse', $joinTables);
        $this->assertContains('group_verse', $joinTables);
        $this->assertContains('group_user', $joinTables);
    }

    public function testSearchGroupFiltersOnUserId(): void
    {
        $userId = 42;
        $query = $this->search->searchGroup($userId);

        $where = $query->getWhere();
        $this->assertNotNull($where, 'searchGroup should have a WHERE clause');

        $whereStr = json_encode($where);
        $this->assertStringContainsString((string) $userId, $whereStr);
    }

    public function testSearchGroupUsesDistinct(): void
    {
        $query = $this->search->searchGroup(1);

        $this->assertTrue($query->getDistinct(), 'searchGroup should use DISTINCT');
    }

    public function testApplyTagFilterWithStringIds(): void
    {
        $query = $this->search->searchPublic();
        $joinCountBefore = count($query->getJoins());

        $this->search->applyTagFilter($query, '1,2,3');

        $joins = $query->getJoins();
        // Should have added verse_tags join
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testApplyTagFilterWithArrayIds(): void
    {
        $query = $this->search->searchPublic();

        $this->search->applyTagFilter($query, [1, 2, 3]);

        $joins = $query->getJoins();
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testApplyTagFilterWithEmptyStringDoesNotAddJoin(): void
    {
        $query = $this->search->searchPublic();
        $joinCountBefore = count($query->getJoins());

        $this->search->applyTagFilter($query, '');

        $this->assertCount($joinCountBefore, $query->getJoins());
    }

    public function testApplyTagFilterWithEmptyArrayDoesNotAddJoin(): void
    {
        $query = $this->search->searchPublic();
        $joinCountBefore = count($query->getJoins());

        $this->search->applyTagFilter($query, []);

        $this->assertCount($joinCountBefore, $query->getJoins());
    }

    public function testSearchPublicWithTagsParam(): void
    {
        $query = $this->search->searchPublic(['tags' => '1,2']);

        $joins = $query->getJoins();
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testSearchCheckinWithTagsParam(): void
    {
        $query = $this->search->searchCheckin(['tags' => '5']);

        $joins = $query->getJoins();
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testSearchPrivateWithTagsParam(): void
    {
        $query = $this->search->searchPrivate(1, ['tags' => '3,4']);

        $joins = $query->getJoins();
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testSearchGroupWithTagsParam(): void
    {
        $query = $this->search->searchGroup(1, ['tags' => '7']);

        $joins = $query->getJoins();
        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('verse_tags', $joinTables);
    }

    public function testApplyTagFilterWithInvalidStringIds(): void
    {
        $query = $this->search->searchPublic();
        $joinCountBefore = count($query->getJoins());

        // "0,-1,abc" should all be filtered out (only positive ints kept)
        $this->search->applyTagFilter($query, '0,-1,abc');

        // No valid tag IDs, so no join should be added
        $this->assertCount($joinCountBefore, $query->getJoins());
    }
}
