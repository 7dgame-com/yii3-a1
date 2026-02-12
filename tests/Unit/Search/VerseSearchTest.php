<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\VerseSearch;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Unit tests for VerseSearch service class.
 *
 * Validates Requirements 8.10
 */
final class VerseSearchTest extends TestCase
{
    private VerseSearch $search;

    protected function setUp(): void
    {
        if (!ConnectionProvider::has()) {
            $connection = $this->createMock(ConnectionInterface::class);
            ConnectionProvider::set($connection);
        }

        $this->search = new VerseSearch();
    }

    public function testSearchReturnsActiveQuery(): void
    {
        $query = $this->search->search();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchWithNameFilter(): void
    {
        $query = $this->search->search(['name' => 'test']);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString('test', $whereStr);
    }

    public function testSearchWithAuthorIdFilter(): void
    {
        $query = $this->search->search(['author_id' => 5]);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString('5', $whereStr);
    }

    public function testFindByIdReturnsActiveQuery(): void
    {
        $query = $this->search->findById(10);

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $where = $query->getWhere();
        $this->assertNotNull($where);
    }

    public function testFindByAuthorReturnsActiveQuery(): void
    {
        $query = $this->search->findByAuthor(3);

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $where = $query->getWhere();
        $this->assertNotNull($where);
    }
}
