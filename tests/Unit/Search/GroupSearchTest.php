<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\GroupSearch;
use PHPUnit\Framework\TestCase;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Unit tests for GroupSearch service class.
 *
 * Validates Requirements 8.10
 */
final class GroupSearchTest extends TestCase
{
    private GroupSearch $search;

    protected function setUp(): void
    {
        if (!ConnectionProvider::has()) {
            $connection = $this->createMock(ConnectionInterface::class);
            ConnectionProvider::set($connection);
        }

        $this->search = new GroupSearch();
    }

    public function testSearchReturnsActiveQuery(): void
    {
        $query = $this->search->search();

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testSearchWithNameFilter(): void
    {
        $query = $this->search->search(['name' => 'team']);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString('team', $whereStr);
    }

    public function testSearchWithCreatedByFilter(): void
    {
        $query = $this->search->search(['created_by' => 7]);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString('7', $whereStr);
    }

    public function testFindByIdReturnsActiveQuery(): void
    {
        $query = $this->search->findById(3);

        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    public function testFindByUserJoinsGroupUserTable(): void
    {
        $query = $this->search->findByUser(10);

        $joins = $query->getJoins();
        $this->assertNotEmpty($joins, 'findByUser should have joins');

        $joinTables = array_map(fn($j) => $j[1], $joins);
        $this->assertContains('group_user', $joinTables);
    }

    public function testFindByUserFiltersOnUserId(): void
    {
        $userId = 10;
        $query = $this->search->findByUser($userId);

        $where = $query->getWhere();
        $this->assertNotNull($where);
        $whereStr = json_encode($where);
        $this->assertStringContainsString((string) $userId, $whereStr);
    }
}
