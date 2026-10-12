<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\TagsSearch;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Unit tests for TagsSearch service class.
 *
 * Validates Requirements 8.10, 4.6
 */
final class TagsSearchTest extends TestCase
{
    private TagsSearch $search;

    protected function setUp(): void
    {
        if (!ConnectionProvider::has()) {
            $connection = $this->createMock(ConnectionInterface::class);
            ConnectionProvider::set($connection);
        }

        $this->search = new TagsSearch();
    }

    public function testSearchReturnsActiveQuery(): void
    {
        $query = $this->search->search();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchDoesNotFilterByType(): void
    {
        $query = $this->search->search();

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringNotContainsString('tags.type', $whereStr);
    }

    public function testSearchIgnoresLegacyTypeParameter(): void
    {
        $query = $this->search->search(['type' => 'Custom']);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringNotContainsString('tags.type', $whereStr);
        $this->assertSame($this->search->search()->getWhere(), $where);
    }

    public function testSearchWithNameFilter(): void
    {
        $query = $this->search->search(['name' => 'art', 'type' => 'Custom']);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString('art', $whereStr);
        $this->assertStringNotContainsString('tags.type', $whereStr);
    }

    public function testFindByIdReturnsActiveQuery(): void
    {
        $query = $this->search->findById(5);

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testFindAllReturnsActiveQuery(): void
    {
        $query = $this->search->findAll();

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $where = $query->getWhere();
        $this->assertNotNull($where);
        $this->assertSame($this->search->search()->getWhere(), $where);
    }
}
