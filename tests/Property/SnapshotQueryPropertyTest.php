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
     * Property 7a: searchPublic always builds a query with property.key = 'public'.
     *
     * For any invocation, the WHERE clause must contain 'public' and the query
     * must join verse_property and property tables using the A1 query shape.
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

                // WHERE must contain property.key = 'public'
                $where = $query->getWhere();
                $this->assertNotNull($where, 'searchPublic must have a WHERE clause');
                $whereStr = json_encode($where);
                $this->assertStringContainsString('property.key', $whereStr);
                $this->assertStringContainsString('public', $whereStr);

                // Must join verse_property and property, without the extra verse join.
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertNotContains('verse', $joinTables);
                $this->assertContains('verse_property', $joinTables);
                $this->assertContains('property', $joinTables);
            });
    }

    /**
     * Property 7b: searchCheckin always builds a query with property.key = 'checkin'.
     *
     * For any invocation, the WHERE clause must contain 'checkin' and the query
     * must join verse_property and property tables using the A1 query shape.
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

                // WHERE must contain property.key = 'checkin'
                $where = $query->getWhere();
                $this->assertNotNull($where, 'searchCheckin must have a WHERE clause');
                $whereStr = json_encode($where);
                $this->assertStringContainsString('property.key', $whereStr);
                $this->assertStringContainsString('checkin', $whereStr);

                // Must join verse_property and property, without the extra verse join.
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertNotContains('verse', $joinTables);
                $this->assertContains('verse_property', $joinTables);
                $this->assertContains('property', $joinTables);
            });
    }

    /**
     * Property 7c: searchPublic and searchCheckin produce mutually exclusive filters.
     *
     * The public query must NOT contain 'checkin' in its property filter,
     * and the checkin query must NOT contain 'public' in its property filter.
     *
     * **Validates: Requirements 4.2, 4.3, 5.1, 5.2**
     *
     * @eris-repeat 100
     */
    public function testPublicAndCheckinFiltersAreMutuallyExclusive(): void
    {
        $this->forAll(
            Generator\choose(0, 1)
        )
            ->then(function (int $_): void {
                $publicQuery = $this->snapshotSearch->searchPublic();
                $checkinQuery = $this->snapshotSearch->searchCheckin();

                $publicWhere = json_encode($publicQuery->getWhere());
                $checkinWhere = json_encode($checkinQuery->getWhere());

                // Extract the property.key value from each WHERE
                // public query should filter on 'public', not 'checkin'
                $this->assertStringContainsString('"property.key":"public"', $publicWhere);
                $this->assertStringNotContainsString('"property.key":"checkin"', $publicWhere);

                // checkin query should filter on 'checkin', not 'public'
                $this->assertStringContainsString('"property.key":"checkin"', $checkinWhere);
                $this->assertStringNotContainsString('"property.key":"public"', $checkinWhere);
            });
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
    // Property 10: 标签查询类型过滤
    // ---------------------------------------------------------------

    /**
     * Property 10: TagsSearch.search() defaults to type = 'Classify'.
     *
     * For any invocation without explicit type parameter, the WHERE clause
     * must contain tags.type = 'Classify'.
     *
     * **Validates: Requirements 4.6, 5.6**
     *
     * @eris-repeat 100
     */
    public function testTagsSearchDefaultsToClassifyType(): void
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
                $this->assertStringContainsString('tags.type', $whereStr);
                $this->assertStringContainsString('Classify', $whereStr);
            });
    }

    /**
     * Property 10b: TagsSearch.findByType() filters on the given type.
     *
     * For any random type string, the WHERE clause must contain that type
     * associated with tags.type.
     *
     * **Validates: Requirements 4.6, 5.6**
     *
     * @eris-repeat 100
     */
    public function testTagsFindByTypeFiltersOnGivenType(): void
    {
        $typeNames = ['Classify', 'Scene', 'Material', 'Effect', 'Custom'];

        $this->forAll(
            Generator\elements(...$typeNames)
        )
            ->then(function (string $type): void {
                $query = $this->tagsSearch->findByType($type);

                $this->assertInstanceOf(ActiveQuery::class, $query);

                $where = $query->getWhere();
                $this->assertNotNull($where, "findByType('{$type}') must have a WHERE clause");
                $whereStr = json_encode($where);
                $this->assertStringContainsString('tags.type', $whereStr);
                $this->assertStringContainsString($type, $whereStr);
            });
    }

    // ---------------------------------------------------------------
    // Property 12: 标签过滤正确性
    // ---------------------------------------------------------------

    /**
     * Property 12: applyTagFilter adds verse_tags join with correct tag IDs.
     *
     * For any non-empty set of positive tag IDs, applying the tag filter must
     * add a verse_tags join and include the tag IDs in the WHERE clause.
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
                $joinCountBefore = count($query->getJoins());

                $this->snapshotSearch->applyTagFilter($query, $tagIds);

                // verse_tags join must be added
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertContains(
                    'verse_tags',
                    $joinTables,
                    'applyTagFilter must add verse_tags join for tagIds: ' . implode(',', $tagIds)
                );

                // WHERE must contain the tag IDs
                $where = $query->getWhere();
                $whereStr = json_encode($where);
                $this->assertStringContainsString('verse_tags.tags_id', $whereStr);
            });
    }

    /**
     * Property 12b: applyTagFilter with comma-separated string works identically.
     *
     * For any non-empty set of positive tag IDs provided as a comma-separated
     * string, the filter must add the verse_tags join.
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

                // verse_tags join must be added
                $joins = $query->getJoins();
                $joinTables = array_map(fn($j) => $j[1], $joins);
                $this->assertContains(
                    'verse_tags',
                    $joinTables,
                    "applyTagFilter must add verse_tags join for string '{$tagString}'"
                );

                // WHERE must reference verse_tags.tags_id
                $where = $query->getWhere();
                $whereStr = json_encode($where);
                $this->assertStringContainsString('verse_tags.tags_id', $whereStr);
            });
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
