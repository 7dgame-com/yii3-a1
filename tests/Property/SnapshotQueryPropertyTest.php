<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Query\Query;

/**
 * Property-based tests for V1 ServerController scene query correctness.
 *
 * Feature: yii2-to-yii3-migration
 *
 * Tests query construction logic of SnapshotSearch and TagsSearch by inspecting
 * ActiveQuery WHERE conditions and JOIN tables. No database connection required.
 *
 * **Validates: Requirements 4.2, 4.3, 4.4, 4.5, 4.6, 4.9, 5.1, 5.2, 5.3, 5.4, 5.6**
 */
final class SnapshotQueryPropertyTest extends TestCase
{
    use TestTrait;

    private SnapshotSearch $snapshotSearch;
    private TagsSearch $tagsSearch;

    protected function setUp(): void
    {
        if (!ConnectionProvider::has()) {
            $connection = $this->createMock(ConnectionInterface::class);
            ConnectionProvider::set($connection);
        }

        $this->snapshotSearch = new SnapshotSearch();
        $this->tagsSearch = new TagsSearch();
    }

    // ---------------------------------------------------------------
    // Property 7: 场景查询过滤正确性 — public/checkin
    // ---------------------------------------------------------------

    /**
     * Property 7a: searchPublic always requires the public property.
     *
     * The scope uses a correlated EXISTS so duplicate associations do not
     * multiply snapshot rows.
     *
     * **Validates: Requirements 4.2, 5.1**
     *
     * @eris-repeat 100
     */
    public function testSearchPublicAlwaysFiltersOnPublicKey(): void
    {
        $this->forAll(
            Generator\choose(0, 1) // dummy generator to drive iterations
        )
            ->then(function (int $_): void {
                $query = $this->snapshotSearch->searchPublic();

                $this->assertInstanceOf(ActiveQuery::class, $query);

                $this->assertPropertyScope($query, 'public');
                $this->assertSame([], $query->getJoins());
            });
    }

    /**
     * Property 7b: searchCheckin always requires the checkin property.
     *
     * The scope uses a correlated EXISTS so duplicate associations do not
     * multiply snapshot rows.
     *
     * **Validates: Requirements 4.3, 5.2**
     *
     * @eris-repeat 100
     */
    public function testSearchCheckinAlwaysFiltersOnCheckinKey(): void
    {
        $this->forAll(
            Generator\choose(0, 1)
        )
            ->then(function (int $_): void {
                $query = $this->snapshotSearch->searchCheckin();

                $this->assertInstanceOf(ActiveQuery::class, $query);

                $this->assertPropertyScope($query, 'checkin');
                $this->assertSame([], $query->getJoins());
            });
    }

    /**
     * Property 7c: public and checkin retain independent scope predicates.
     *
     * The public query must NOT contain 'checkin' in its property filter,
     * and the checkin query must NOT contain 'public' in its property filter.
     *
     * **Validates: Requirements 4.2, 4.3, 5.1, 5.2**
     *
     * @eris-repeat 100
     */
    public function testPublicAndCheckinKeepIndependentScopePredicates(): void
    {
        $this->forAll(
            Generator\choose(0, 1)
        )
            ->then(function (int $_): void {
                $publicQuery = $this->snapshotSearch->searchPublic();
                $checkinQuery = $this->snapshotSearch->searchCheckin();

                $this->assertPropertyScope($publicQuery, 'public');
                $this->assertPropertyScope($checkinQuery, 'checkin');
            });
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

    // ---------------------------------------------------------------
    // Property 8: 场景查询过滤正确性 — private
    // ---------------------------------------------------------------

    /**
     * Property 8: searchPrivate builds a query with verse.author_id = $userId.
     *
     * For any random positive userId, the WHERE clause must contain that userId
     * associated with verse.author_id, and the query must join the verse table.
     *
     * **Validates: Requirements 4.4, 5.4**
     *
     * @eris-repeat 100
     */
    public function testSearchPrivateAlwaysFiltersOnAuthorId(): void
    {
        $this->forAll(
            Generator\choose(1, 1_000_000)
        )
            ->then(function (int $userId): void {
                $query = $this->snapshotSearch->searchPrivate($userId);

                $this->assertInstanceOf(ActiveQuery::class, $query);

                // WHERE must contain verse.author_id = $userId
                $where = $query->getWhere();
                $this->assertNotNull($where, 'searchPrivate must have a WHERE clause');
                $whereStr = json_encode($where);
                $this->assertStringContainsString('verse.author_id', $whereStr);
                $this->assertStringContainsString((string) $userId, $whereStr);

                // Must join verse table
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertContains('verse', $joinTables);

                // Must NOT join property tables (not a public/checkin query)
                $this->assertNotContains('property', $joinTables);
                $this->assertNotContains('verse_property', $joinTables);
            });
    }

    // ---------------------------------------------------------------
    // Property 9: 场景查询过滤正确性 — group
    // ---------------------------------------------------------------

    /**
     * Property 9: searchGroup builds a query with group_user.user_id = $userId.
     *
     * For any random positive userId, the WHERE clause must contain that userId
     * associated with group_user.user_id, and the query must join verse,
     * group_verse, and group_user tables. The query must also use DISTINCT.
     *
     * **Validates: Requirements 4.5, 5.3**
     *
     * @eris-repeat 100
     */
    public function testSearchGroupAlwaysFiltersOnGroupUserId(): void
    {
        $this->forAll(
            Generator\choose(1, 1_000_000)
        )
            ->then(function (int $userId): void {
                $query = $this->snapshotSearch->searchGroup($userId);

                $this->assertInstanceOf(ActiveQuery::class, $query);

                // WHERE must contain group_user.user_id = $userId
                $where = $query->getWhere();
                $this->assertNotNull($where, 'searchGroup must have a WHERE clause');
                $whereStr = json_encode($where);
                $this->assertStringContainsString('group_user.user_id', $whereStr);
                $this->assertStringContainsString((string) $userId, $whereStr);

                // Must join verse, group_verse, group_user
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertContains('verse', $joinTables);
                $this->assertContains('group_verse', $joinTables);
                $this->assertContains('group_user', $joinTables);

                // Must use DISTINCT
                $this->assertTrue(
                    $query->getDistinct(),
                    "searchGroup must use DISTINCT for userId={$userId}"
                );
            });
    }

    // ---------------------------------------------------------------
    // Property 10: 标签查询不按类型过滤
    // ---------------------------------------------------------------

    /**
     * Property 10: TagsSearch.search() does not filter on tag type.
     *
     * The default query must retain retired-public exclusion without
     * adding any tags.type condition.
     *
     * **Validates: Requirements 4.6, 5.6**
     *
     * @eris-repeat 100
     */
    public function testTagsSearchDoesNotFilterByType(): void
    {
        $this->forAll(
            Generator\choose(0, 1)
        )
            ->then(function (int $_): void {
                $query = $this->tagsSearch->search();

                $this->assertInstanceOf(ActiveQuery::class, $query);

                $where = $query->getWhere();
                $this->assertNotNull($where, 'TagsSearch.search() must have a WHERE clause');
                $whereStr = json_encode($where);
                $this->assertStringNotContainsString('tags.type', $whereStr);
                $this->assertSame($this->tagsSearch->findAll()->getWhere(), $where);
            });
    }

    /**
     * Property 10b: TagsSearch.search() ignores legacy type parameters.
     *
     * For any legacy type value, the query must return the same tag set
     * as the default query.
     *
     * **Validates: Requirements 4.6, 5.6**
     *
     * @eris-repeat 100
     */
    public function testTagsSearchIgnoresLegacyTypeValues(): void
    {
        $typeNames = ['Classify', 'Scene', 'Material', 'Effect', 'Custom'];

        $this->forAll(
            Generator\elements(...$typeNames)
        )
            ->then(function (string $type): void {
                $query = $this->tagsSearch->search(['type' => $type]);

                $this->assertInstanceOf(ActiveQuery::class, $query);

                $where = $query->getWhere();
                $this->assertNotNull($where, "search(['type' => '{$type}']) must have a WHERE clause");
                $whereStr = json_encode($where);
                $this->assertStringNotContainsString('tags.type', $whereStr);
                $this->assertSame($this->tagsSearch->search()->getWhere(), $where);
            });
    }

    // ---------------------------------------------------------------
    // Property 12: 标签过滤正确性
    // ---------------------------------------------------------------

    /**
     * Property 12: applyTagFilter matches classification IDs without multiplying rows.
     *
     * For any non-empty set of positive tag IDs, applying the tag filter must
     * add an EXISTS condition containing the requested tag IDs and exclude the old public tag.
     *
     * **Validates: Requirements 4.9**
     *
     * @eris-repeat 100
     */
    public function testApplyTagFilterAddsCorrectConditions(): void
    {
        $this->forAll(
            Generator\vector(3, Generator\choose(1, 1000))
        )
            ->then(function (array $tagIds): void {
                // Start with a fresh public query as base
                $query = $this->snapshotSearch->searchPublic();
                $this->snapshotSearch->applyTagFilter($query, $tagIds);

                $this->assertClassificationFilter($query, $tagIds);
            });
    }

    /**
     * Property 12b: applyTagFilter with comma-separated string works identically.
     *
     * For any non-empty set of positive tag IDs provided as a comma-separated
     * string, the filter must apply the same classification condition.
     *
     * **Validates: Requirements 4.9**
     *
     * @eris-repeat 100
     */
    public function testApplyTagFilterWithStringIdsAddsCorrectConditions(): void
    {
        $this->forAll(
            Generator\vector(3, Generator\choose(1, 1000))
        )
            ->then(function (array $tagIds): void {
                $tagString = implode(',', $tagIds);

                $query = $this->snapshotSearch->searchPublic();
                $this->snapshotSearch->applyTagFilter($query, $tagString);

                $this->assertClassificationFilter($query, $tagIds);
            });
    }

    private function assertClassificationFilter(ActiveQuery $query, array $tagIds): void
    {
        $where = $query->getWhere();
        $this->assertSame('exists', $where[2][0]);
        $classification = $where[2][1];
        $this->assertSame(['filter_vt' => 'verse_tags'], $classification->getFrom());
        $this->assertSame(['filter_vt.tags_id' => $tagIds], $classification->getWhere()[2]);
        $this->assertSame(['or', ['<>', 'filter_tags.key', 'public'], ['filter_tags.key' => null]], $classification->getWhere()[3]);
    }

    /**
     * Property 12c: Empty tag IDs do not modify the query.
     *
     * For any base query, applying an empty tag filter (empty string or empty array)
     * must not add any additional joins.
     *
     * **Validates: Requirements 4.9**
     *
     * @eris-repeat 100
     */
    public function testEmptyTagFilterDoesNotModifyQuery(): void
    {
        $this->forAll(
            Generator\elements('', '0', '0,0', '-1')
        )
            ->then(function (string $emptyTags): void {
                $query = $this->snapshotSearch->searchPublic();
                $joinCountBefore = count($query->getJoins());

                $this->snapshotSearch->applyTagFilter($query, $emptyTags);

                $this->assertCount(
                    $joinCountBefore,
                    $query->getJoins(),
                    "Empty/invalid tag filter '{$emptyTags}' must not add joins"
                );
            });
    }
}
