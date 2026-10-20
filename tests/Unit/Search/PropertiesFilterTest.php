<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Controller\V1\ServerController;
use App\Controller\V2\SnapshotController;
use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Constant\ColumnType;
use Yiisoft\Db\Expression\Value\Param;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;
use Yiisoft\Db\Mysql\QueryBuilder;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchema;
use Yiisoft\Router\CurrentRoute;

/**
 * Exercise real search SQL, pagination, models, caching and HTTP serialization.
 * MySQL's builder generates the SELECTs; only transport uses isolated SQLite.
 * These fixtures never connect to an application database.
 */
final class PropertiesFilterTest extends TestCase
{
    private PDO $fixture;
    private ?ConnectionInterface $previousConnection = null;

    protected function setUp(): void
    {
        $this->previousConnection = ConnectionProvider::has() ? ConnectionProvider::get() : null;
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The isolated SQL fixtures require pdo_sqlite.');
        }
        ConnectionProvider::set(new Connection(new Driver('mysql:host=unused'), new SchemaCache(new ArrayCache())));
        $this->fixture = new PDO('sqlite::memory:');
        $this->fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE snapshot (id INTEGER PRIMARY KEY, verse_id INTEGER)',
            'CREATE TABLE verse (id INTEGER PRIMARY KEY, author_id INTEGER)',
            'CREATE TABLE property (id INTEGER PRIMARY KEY, key TEXT)',
            'CREATE TABLE verse_property (verse_id INTEGER, property_id INTEGER)',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, key TEXT)',
            'CREATE TABLE verse_tags (verse_id INTEGER, tags_id INTEGER)',
            'CREATE TABLE group_verse (group_id INTEGER, verse_id INTEGER)',
            'CREATE TABLE group_user (group_id INTEGER, user_id INTEGER)',
            'INSERT INTO snapshot VALUES (1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8)',
            'INSERT INTO verse VALUES (1,10),(2,10),(3,20),(4,10),(5,10),(6,20),(7,20),(8,10)',
            "INSERT INTO property VALUES (1,'public'),(2,'checkin'),(3,'education'),(4,'games'),(5,'public,checkin'),(6,'教育')",
            'INSERT INTO verse_property VALUES (1,1),(1,2),(1,3),(2,2),(2,3),(3,1),(3,2),(3,3),(4,1),(4,2),(4,4),(6,3),(7,2),(7,4),(8,5),(8,6)',
            "INSERT INTO tags VALUES (1,'public'),(2,'a'),(3,'b'),(4,NULL)",
            'INSERT INTO verse_tags VALUES (1,2),(1,3),(2,1),(2,2),(3,2),(4,3),(5,4),(6,2)',
            'INSERT INTO group_verse VALUES (7,1),(7,2),(7,4),(7,6),(8,3),(8,7)',
            'INSERT INTO group_user VALUES (7,10),(8,20)',
        ] as $sql) {
            $this->fixture->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousConnection !== null) {
            ConnectionProvider::set($this->previousConnection);
        } else {
            ConnectionProvider::remove();
        }
    }

    public function testOmittedFilterLeavesExistingScopeUnchanged(): void
    {
        $this->assertSame([], SnapshotSearch::normalizePropertiesFilter([]));
        $this->assertSame([1, 3, 4], $this->ids($this->search('public', [])));
        $this->assertSame([1, 2, 3, 4, 7], $this->ids($this->search('checkin', [])));
        $this->assertSame([1, 2, 4, 5, 8], $this->ids($this->search('private', [])));
        $this->assertSame([1, 2, 4, 6], $this->ids($this->search('group', [])));
    }

    #[DataProvider('validFilters')]
    public function testNormalizePropertyKeys(mixed $input, array $expected): void
    {
        $this->assertSame($expected, SnapshotSearch::normalizePropertiesFilter(['properties' => $input]));
    }

    public static function validFilters(): iterable
    {
        yield 'CSV is trimmed, sorted and deduplicated' => [' public,checkin, public ', ['checkin', 'public']];
        yield 'array has same canonical form' => [[' public ', 'checkin', 'public'], ['checkin', 'public']];
        yield 'array items are complete keys' => [['public,checkin'], ['public,checkin']];
        yield 'array may be associative' => [['first' => 'public', 'second' => 'checkin'], ['checkin', 'public']];
        yield 'Unicode keys' => [['教育', '公開', '教育'], ['公開', '教育']];
        yield 'numeric string key' => ['0', ['0']];
        yield 'Unicode limit counts characters, not bytes' => [str_repeat('教', 255), [str_repeat('教', 255)]];
        yield 'SQL punctuation is an ordinary key' => ["x') OR 1=1 --", ["x') OR 1=1 --"]];
        $keys = array_map(static fn(int $index): string => 'key_' . $index, range(1, 50));
        $expected = $keys;
        sort($expected, SORT_STRING);
        yield '50 keys are supported' => [$keys, $expected];
    }

    #[DataProvider('invalidFilters')]
    public function testMalformedPropertiesAreRejectedWith400(mixed $input): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(400);
        SnapshotSearch::normalizePropertiesFilter(['properties' => $input]);
    }

    public static function invalidFilters(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
        yield 'object' => [(object) ['key' => 'public']];
        yield 'empty string' => [''];
        yield 'only whitespace' => ['   '];
        yield 'leading CSV empty' => [',public'];
        yield 'trailing CSV empty' => ['public,'];
        yield 'middle CSV empty' => ['public, ,checkin'];
        yield 'empty array' => [[]];
        yield 'empty array item' => [['public', ' ']];
        yield 'numeric array item' => [['public', 1]];
        yield 'nested array item' => [[['public']]];
        yield 'invalid UTF8' => ["\xC3\x28"];
        yield 'NUL' => ["pub\0lic"];
        yield 'line break' => ["pub\nlic"];
        yield 'DEL' => ["pub\x7Flic"];
        yield 'Unicode C1 control' => ["pub\u{0085}lic"];
        yield 'more than 255 Unicode characters' => [str_repeat('教', 256)];
        yield 'more than 50 keys' => [array_map(static fn(int $index): string => 'key_' . $index, range(1, 51))];
    }

    #[DataProvider('scopedFilters')]
    public function testPropertiesAreAndedWithExistingScopeTagsAndPublic(string $scope, array $params, array $expected): void
    {
        $this->assertSame($expected, $this->ids($this->search($scope, $params)));
    }

    public static function scopedFilters(): iterable
    {
        yield 'public scope only matching property' => ['public', ['properties' => 'education'], [1, 3]];
        yield 'checkin scope only matching property' => ['checkin', ['properties' => 'education'], [1, 2, 3]];
        yield 'private scope retains authorship' => ['private', ['properties' => 'education'], [1, 2]];
        yield 'group scope retains membership' => ['group', ['properties' => 'education'], [1, 2, 6]];
        yield 'public plus checkin is AND' => ['private', ['properties' => 'public,checkin'], [1, 4]];
        yield 'three properties are AND' => ['private', ['properties' => ['checkin', 'education', 'public']], [1]];
        yield 'AND is independent of parameter order and duplicates' => ['private', ['properties' => ' checkin,public,checkin '], [1, 4]];
        yield 'public scope plus checkin' => ['public', ['properties' => 'checkin'], [1, 3, 4]];
        yield 'checkin scope plus public' => ['checkin', ['properties' => 'public'], [1, 3, 4]];
        yield 'properties AND tag OR filter' => ['private', ['properties' => 'checkin', 'tags' => '2,3'], [1, 2, 4]];
        yield 'properties AND single tag' => ['private', ['properties' => 'public,checkin', 'tags' => '2'], [1]];
        yield 'properties AND public exclusion' => ['private', ['properties' => 'education', 'public' => '0'], [2]];
        yield 'contradictory public exclusion is empty' => ['private', ['properties' => 'public', 'public' => '0'], []];
        yield 'unknown key is empty' => ['private', ['properties' => 'not-a-property'], []];
        yield 'unknown key in AND is empty' => ['public', ['properties' => 'public,not-a-property'], []];
        yield 'complete array key is not parsed as CSV' => ['private', ['properties' => ['public,checkin']], [8]];
        yield 'Unicode key matches' => ['private', ['properties' => '教育'], [8]];
        yield 'properties do not use legacy tags' => ['private', ['properties' => 'public', 'tags' => '1'], []];
    }

    public function testPropertyKeysAreBoundValuesAndCannotChangeThePredicate(): void
    {
        $key = "x') OR 1=1 --";
        $this->assertSame([], $this->ids($this->search('private', ['properties' => $key])));
        $statement = $this->fixture->prepare('INSERT INTO property VALUES (9, :key)');
        $statement->execute([':key' => $key]);
        $this->fixture->exec('INSERT INTO verse_property VALUES (8,9)');
        $this->assertSame([8], $this->ids($this->search('private', ['properties' => $key])));
        $this->assertSame([1], $this->ids($this->search('private', ['properties' => 'education,public'])));
    }

    #[DataProvider('scopes')]
    public function testRepeatedPivotsDoNotMultiplySnapshotsOrPaginationCounts(string $scope): void
    {
        $this->fixture->exec('INSERT INTO verse_property VALUES (1,1),(1,2),(1,3),(1,3)');
        $this->fixture->exec("INSERT INTO property VALUES (9,'public'),(10,'checkin'),(11,'education')");
        $this->fixture->exec('INSERT INTO verse_property VALUES (1,9),(1,10),(1,11)');
        $expected = match ($scope) {
            'public' => [1, 3],
            'checkin' => [1, 2, 3],
            'private' => [1, 2],
            'group' => [1, 2, 6],
        };
        $this->assertSame($expected, $this->ids($this->search($scope, ['properties' => 'education'])));
        $this->useFixtureConnectionForActiveRecord();
        $result = (new PaginationService())->paginate($this->search($scope, ['properties' => 'education']), 1, 1);
        $this->assertSame(count($expected), $result->totalCount);
        $this->assertSame(count($expected), $result->pageCount);
        $this->assertCount(1, $result->items);
    }

    public function testFilteredPaginationCountsMatchesAndLoadsTheCorrectPage(): void
    {
        $this->useFixtureConnectionForActiveRecord();
        $service = $this->service($this->passthroughCache());
        $result = $service->findPrivate(10, ['properties' => 'education', 'page' => 2, 'pageSize' => 1]);
        $this->assertSame(2, $result->totalCount);
        $this->assertSame(2, $result->pageCount);
        $this->assertSame(2, $result->currentPage);
        $this->assertSame(1, $result->perPage);
        $this->assertSame([1], array_map(static fn($snapshot): int => $snapshot->get('id'), $result->items));
    }

    public function testCacheSeparatesFiltersScopesAndUsersWhileReusingCanonicalProperties(): void
    {
        $this->useFixtureConnectionForActiveRecord();
        $keys = [];
        $values = [];
        $misses = 0;
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('getOrSet')->willReturnCallback(function (string $key, callable $callback, int $ttl) use (&$keys, &$values, &$misses) {
            $this->assertSame(30, $ttl);
            $keys[] = $key;
            if (!array_key_exists($key, $values)) {
                ++$misses;
                $values[$key] = $callback();
            }
            return $values[$key];
        });
        $service = $this->service($cache);
        $first = $service->findPrivate(10, ['properties' => 'public,checkin']);
        $equivalent = $service->findPrivate(10, ['properties' => [' checkin ', 'public', 'checkin']]);
        $different = $service->findPrivate(10, ['properties' => 'education']);
        $otherUser = $service->findPrivate(20, ['properties' => 'public,checkin']);
        $otherScope = $service->findGroup(10, ['properties' => 'public,checkin']);
        $commaKey = $service->findPrivate(10, ['properties' => ['public,checkin']]);
        $public = $service->findPublic(['properties' => 'education']);
        $checkin = $service->findCheckin(['properties' => 'education']);
        $this->assertSame($first, $equivalent);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertCount(7, array_unique($keys));
        $this->assertSame(7, $misses);
        $this->assertSame([4, 1], $this->resultIds($first));
        $this->assertSame([2, 1], $this->resultIds($different));
        $this->assertSame([3], $this->resultIds($otherUser));
        $this->assertSame([4, 1], $this->resultIds($otherScope));
        $this->assertSame([8], $this->resultIds($commaKey));
        $this->assertSame([1, 3], $this->sortedResultIds($public));
        $this->assertSame([1, 2, 3], $this->sortedResultIds($checkin));
    }

    public function testWarmCacheCannotBypassMalformedPropertyValidation(): void
    {
        $this->useFixtureConnectionForActiveRecord();
        $calls = 0;
        $values = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('getOrSet')->willReturnCallback(function (string $key, callable $callback) use (&$calls, &$values) {
            ++$calls;
            if (!array_key_exists($key, $values)) {
                $values[$key] = $callback();
            }
            return $values[$key];
        });
        $service = $this->service($cache);
        $service->findPublic([]);
        foreach (['', [], ['public', ''], "\xC3\x28"] as $input) {
            try {
                $service->findPublic(['properties' => $input]);
                $this->fail('Malformed properties must be rejected before any cache lookup.');
            } catch (RuntimeException $exception) {
                $this->assertSame(400, $exception->getCode());
            }
        }
        $this->assertSame(1, $calls);
    }

    #[DataProvider('invalidEndpointFilters')]
    public function testBothApiVersionsReturnHttp400BeforeCacheLookup(string $scope, mixed $input): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('getOrSet');
        [$v1, $v2] = $this->controllers($cache);
        $request = (new ServerRequest())->withQueryParams(['scope' => $scope, 'properties' => $input])
            ->withAttribute('user', ['user_id' => 10]);
        $method = $this->v1Method($scope);
        foreach ([$v1->$method($request), $v2->index($request)] as $response) {
            $this->assertSame(400, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(400, $body['status']);
            $this->assertSame('Bad Request', $body['name']);
        }
    }

    public static function invalidEndpointFilters(): iterable
    {
        foreach (self::scopes() as [$scope]) {
            foreach (self::invalidFilters() as $name => [$input]) {
                yield $scope . ': ' . $name => [$scope, $input];
            }
        }
    }

    #[DataProvider('scopes')]
    public function testBothApiVersionsApplyPropertiesAndPaginationHeaders(string $scope): void
    {
        $this->useFixtureConnectionForActiveRecord();
        [$v1, $v2] = $this->controllers($this->passthroughCache());
        $expected = match ($scope) {
            'public' => [1, 3],
            'checkin' => [1, 2, 3],
            'private' => [1, 2],
            'group' => [1, 2, 6],
        };
        $request = (new ServerRequest())->withQueryParams([
            'scope' => $scope,
            'properties' => 'education',
            'expand' => 'id',
            'pageSize' => 50,
        ])->withAttribute('user', ['user_id' => 10]);
        $method = $this->v1Method($scope);
        foreach ([$v1->$method($request), $v2->index($request)] as $response) {
            $this->assertSame(200, $response->getStatusCode());
            $ids = array_column(json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR), 'id');
            sort($ids);
            $this->assertSame($expected, $ids);
            $this->assertSame((string) count($expected), $response->getHeaderLine('X-Pagination-Total-Count'));
            $this->assertSame('1', $response->getHeaderLine('X-Pagination-Page-Count'));
        }
    }

    public function testPropertiesCannotMakePrivateOrGroupEndpointsAnonymous(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('getOrSet');
        [$v1, $v2] = $this->controllers($cache);
        foreach (['private', 'group'] as $scope) {
            $request = (new ServerRequest())->withQueryParams(['scope' => $scope, 'properties' => 'public,checkin']);
            $method = $this->v1Method($scope);
            $this->assertSame(401, $v1->$method($request)->getStatusCode());
            $this->assertSame(403, $v2->index($request)->getStatusCode());
        }
    }

    public function testV2DefaultScopeRemainsPublicWhenPropertiesAreSpecified(): void
    {
        $this->useFixtureConnectionForActiveRecord();
        [, $v2] = $this->controllers($this->passthroughCache());
        $request = (new ServerRequest())->withQueryParams([
            'properties' => 'public,checkin',
            'expand' => 'id',
        ]);
        $response = $v2->index($request);
        $this->assertSame(200, $response->getStatusCode());
        $ids = array_column(json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR), 'id');
        sort($ids);
        $this->assertSame([1, 3, 4], $ids);
        $this->assertSame('3', $response->getHeaderLine('X-Pagination-Total-Count'));
    }

    public static function scopes(): iterable
    {
        foreach (['public', 'checkin', 'private', 'group'] as $scope) {
            yield $scope => [$scope];
        }
    }

    private function search(string $scope, array $params): ActiveQuery
    {
        $search = new SnapshotSearch();
        return match ($scope) {
            'public' => $search->searchPublic($params),
            'checkin' => $search->searchCheckin($params),
            'private' => $search->searchPrivate(10, $params),
            'group' => $search->searchGroup(10, $params),
        };
    }

    private function ids(ActiveQuery $query): array
    {
        $command = $query->select(['snapshot.id'])->createCommand();
        $ids = array_map('intval', $this->execute($command->getSql(), $command->getParams())->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        return $ids;
    }

    private function resultIds(object $result): array
    {
        return array_map(static fn($snapshot): int => $snapshot->get('id'), $result->items);
    }

    private function sortedResultIds(object $result): array
    {
        $ids = $this->resultIds($result);
        sort($ids);
        return $ids;
    }

    private function v1Method(string $scope): string
    {
        return match ($scope) {
            'public' => 'listPublic',
            'private' => 'listPrivate',
            default => $scope,
        };
    }

    private function service(CacheInterface $cache): SnapshotQueryService
    {
        return new SnapshotQueryService(new SnapshotSearch(), new TagsSearch(), new PaginationService(), $cache);
    }

    private function passthroughCache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('getOrSet')->willReturnCallback(static fn(string $key, callable $callback) => $callback());
        return $cache;
    }

    private function controllers(CacheInterface $cache): array
    {
        $pagination = new PaginationService();
        $service = $this->service($cache);
        $responses = new ResponseFactory();
        $streams = new StreamFactory();
        $rest = new Yii2RestResponseFactory($responses, $streams);
        return [
            new ServerController($service, $pagination, $responses, $streams, $rest),
            new SnapshotController($service, $pagination, $responses, $streams, new CurrentRoute(), $rest),
        ];
    }

    private function execute(string $sql, array $params): PDOStatement
    {
        $statement = $this->fixture->prepare($sql);
        foreach ($params as $name => $value) {
            if ($value instanceof Param) {
                $statement->bindValue($name, $value->value, $value->type);
            } else {
                $statement->bindValue($name, $value);
            }
        }
        $statement->execute();
        return $statement;
    }

    /** Keep real query building, ActiveQuery hydration and COUNT/pagination. */
    private function useFixtureConnectionForActiveRecord(): void
    {
        $mysql = ConnectionProvider::get();
        $factory = $mysql->getColumnFactory();
        $tables = [];
        foreach (['snapshot', 'verse', 'property', 'verse_property', 'tags', 'verse_tags', 'group_verse', 'group_user'] as $name) {
            $table = new TableSchema($name);
            foreach ($this->fixture->query("PRAGMA table_info({$name})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $type = $column['type'] === 'INTEGER' ? ColumnType::INTEGER : ColumnType::STRING;
                $table->column($column['name'], $factory->fromType($type, [
                    'name' => $column['name'],
                    'primaryKey' => (bool) $column['pk'],
                ]));
            }
            $tables[$name] = $table;
        }
        $schema = $this->createMock(SchemaInterface::class);
        $schema->method('getTableSchema')->willReturnCallback(function (string $name) use ($tables, $mysql): TableSchema {
            $name = $mysql->getQuoter()->getRawTableName($name);
            $this->assertArrayHasKey($name, $tables);
            return $tables[$name];
        });
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('getColumnFactory')->willReturn($factory);
        $connection->method('getQuoter')->willReturn($mysql->getQuoter());
        $connection->method('getQueryBuilder')->willReturn(new QueryBuilder($connection));
        $connection->method('createCommand')->willReturnCallback(function (?string $sql, array $params) use ($mysql): CommandInterface {
            $sql = $mysql->getQuoter()->quoteSql($sql);
            $command = $this->createMock(CommandInterface::class);
            $command->method('queryAll')->willReturnCallback(fn(): array => $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
            $command->method('queryScalar')->willReturnCallback(fn(): mixed => $this->execute($sql, $params)->fetchColumn());
            return $command;
        });
        ConnectionProvider::set($connection);
    }
}
