<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Model\Snapshot;
use App\Search\SnapshotSearch;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Query\Query;

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

    public function testSearchPublicUsesPropertyExistsWithoutOuterJoins(): void
    {
        $query = $this->search->searchPublic();

        $this->assertSame([], $query->getJoins(), 'Property associations must not multiply snapshot rows');
        $this->assertPropertyScope($query, 'public');
    }

    public function testSearchPublicDoesNotJoinVerseOrForceOrder(): void
    {
        $query = $this->search->searchPublic();

        $joinTables = array_map(fn($j) => $j[1], $query->getJoins());

        $this->assertNotContains('verse', $joinTables);
        $this->assertSame([], $query->getOrderBy());
    }

    public function testSearchPublicFiltersOnPublicKey(): void
    {
        $query = $this->search->searchPublic();

        $this->assertPropertyScope($query, 'public');
    }

    public function testSearchPublicAppliesA1ExactFilters(): void
    {
        $query = $this->search->searchPublic([
            'id' => '8',
            'verse_id' => '466',
            'created_by' => '24',
            'created_at' => '2026-05-02 10:00:00',
        ]);

        $whereStr = json_encode($query->getWhere());

        $this->assertStringContainsString('snapshot.id', $whereStr);
        $this->assertStringContainsString('8', $whereStr);
        $this->assertStringContainsString('snapshot.verse_id', $whereStr);
        $this->assertStringContainsString('466', $whereStr);
        $this->assertStringContainsString('snapshot.created_by', $whereStr);
        $this->assertStringContainsString('24', $whereStr);
        $this->assertStringContainsString('snapshot.created_at', $whereStr);
        $this->assertStringContainsString('2026-05-02 10:00:00', $whereStr);
    }

    public function testSearchPublicAppliesA1LikeFilters(): void
    {
        $query = $this->search->searchPublic([
            'uuid' => 'b20b',
            'code' => 'local verse',
            'data' => 'MetaRoot',
            'metas' => 'prefab',
            'resources' => 'polygen',
        ]);

        $whereStr = json_encode($query->getWhere());

        foreach (['snapshot.uuid', 'snapshot.code', 'snapshot.data', 'snapshot.metas', 'snapshot.resources'] as $column) {
            $this->assertStringContainsString($column, $whereStr);
        }

        foreach (['b20b', 'local verse', 'MetaRoot', 'prefab', 'polygen'] as $value) {
            $this->assertStringContainsString($value, $whereStr);
        }
    }

    public function testSearchCheckinReturnsActiveQuery(): void
    {
        $query = $this->search->searchCheckin();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchCheckinUsesPropertyExistsWithoutOuterJoins(): void
    {
        $query = $this->search->searchCheckin();

        $this->assertSame([], $query->getJoins(), 'Property associations must not multiply snapshot rows');
        $this->assertPropertyScope($query, 'checkin');
    }

    public function testSearchCheckinDoesNotJoinVerseOrForceOrder(): void
    {
        $query = $this->search->searchCheckin();

        $joinTables = array_map(fn($j) => $j[1], $query->getJoins());

        $this->assertNotContains('verse', $joinTables);
        $this->assertSame([], $query->getOrderBy());
    }

    public function testSearchCheckinFiltersOnCheckinKey(): void
    {
        $query = $this->search->searchCheckin();

        $this->assertPropertyScope($query, 'checkin');
    }

    private function assertPropertyScope(ActiveQuery $query, string $key): void
    {
        $where = $query->getWhere();
        $this->assertIsArray($where);
        $this->assertCount(2, $where);
        $this->assertSame('exists', $where[0]);
        $this->assertInstanceOf(Query::class, $where[1]);

        $property = $where[1];
        $this->assertSame(['filter_vp' => 'verse_property'], $property->getFrom());
        $joins = $property->getJoins();
        $this->assertCount(1, $joins);
        $this->assertSame('property AS filter_p', $joins[0][1]);
        $this->assertSame('{{filter_p}}.[[id]] = {{filter_vp}}.[[property_id]]', $joins[0][2]);
        $this->assertSame([
            'and',
            '{{filter_vp}}.[[verse_id]] = {{snapshot}}.[[verse_id]]',
            ['filter_p.key' => $key],
        ], $property->getWhere());
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
        $this->search->applyTagFilter($query, '1,2,3');

        $this->assertClassificationFilter($query);
    }

    public function testApplyTagFilterWithArrayIds(): void
    {
        $query = $this->search->searchPublic();

        $this->search->applyTagFilter($query, [1, 2, 3]);

        $this->assertClassificationFilter($query);
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

        $this->assertClassificationFilter($query);
    }

    public function testSearchCheckinWithTagsParam(): void
    {
        $query = $this->search->searchCheckin(['tags' => '5']);

        $this->assertClassificationFilter($query);
    }

    public function testSearchPrivateWithTagsParam(): void
    {
        $query = $this->search->searchPrivate(1, ['tags' => '3,4']);

        $this->assertClassificationFilter($query);
    }

    public function testSearchGroupWithTagsParam(): void
    {
        $query = $this->search->searchGroup(1, ['tags' => '7']);

        $this->assertClassificationFilter($query);
    }

    private function assertClassificationFilter(ActiveQuery $query): void
    {
        $where = $query->getWhere();
        $this->assertSame('and', $where[0]);
        $this->assertSame('exists', $where[2][0]);
        $classification = $where[2][1];
        $this->assertSame(['filter_vt' => 'verse_tags'], $classification->getFrom());
        $this->assertStringContainsString('public', json_encode($classification->getWhere()));
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
