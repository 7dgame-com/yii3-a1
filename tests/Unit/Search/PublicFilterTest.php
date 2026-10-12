<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Controller\V1\ServerController;
use App\Controller\V2\SnapshotController;
use App\Controller\V2\TagController;
use App\Model\Tags;
use App\Model\Verse;
use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use DOMDocument;
use DOMXPath;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
 * Execute Y1's generated MySQL SELECT syntax against isolated SQLite fixtures.
 * No MySQL connection is opened, and no application database is touched.
 */
final class PublicFilterTest extends TestCase
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
            'CREATE TABLE snapshot (id INTEGER, verse_id INTEGER)',
            'CREATE TABLE verse (id INTEGER, author_id INTEGER)',
            'CREATE TABLE property (id INTEGER, key TEXT)',
            'CREATE TABLE verse_property (verse_id INTEGER, property_id INTEGER)',
            'CREATE TABLE tags (id INTEGER, name TEXT, key TEXT)',
            'CREATE TABLE verse_tags (verse_id INTEGER, tags_id INTEGER)',
            'CREATE TABLE group_verse (group_id INTEGER, verse_id INTEGER)',
            'CREATE TABLE group_user (group_id INTEGER, user_id INTEGER)',
            'INSERT INTO snapshot VALUES (1,1),(2,2),(3,3),(4,4),(5,5)',
            'INSERT INTO verse VALUES (1,10),(2,10),(3,20),(4,10),(5,10)',
            "INSERT INTO property VALUES (1,'public'),(2,'checkin')",
            'INSERT INTO verse_property VALUES (1,1),(3,1),(4,1),(1,2),(2,2),(3,2),(4,2)',
            "INSERT INTO tags VALUES (1,'Old public','public'),(2,'A','a'),(3,'B','b'),(4,'No key',NULL),(5,'C','c')",
            'INSERT INTO verse_tags VALUES (1,2),(1,3),(2,1),(2,2),(3,2),(4,3),(5,4)',
            'INSERT INTO group_verse VALUES (7,1),(7,2),(7,4),(8,3)',
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

    #[DataProvider('scopedFilters')]
    public function testPublicPropertyFiltersWithinExistingScope(string $scope, array $params, array $expected): void
    {
        $search = new SnapshotSearch();
        $query = match ($scope) {
            'public' => $search->searchPublic($params),
            'checkin' => $search->searchCheckin($params),
            'private' => $search->searchPrivate(10, $params),
            'group' => $search->searchGroup(10, $params),
        };
        $this->assertSame($expected, $this->ids($query, 'snapshot.id'));
    }

    public static function scopedFilters(): iterable
    {
        yield 'property public without old tag' => ['private', ['public' => '1'], [1, 4]];
        yield 'old public tag does not make scene public' => ['private', ['public' => '0'], [2, 5]];
        yield 'public scope remains public' => ['public', ['public' => '0'], []];
        yield 'public scope default unchanged' => ['public', [], [1, 3, 4]];
        yield 'checkin public' => ['checkin', ['public' => '1'], [1, 3, 4]];
        yield 'checkin not public' => ['checkin', ['public' => '0'], [2]];
        yield 'group public stays within membership' => ['group', ['public' => '1'], [1, 4]];
        yield 'group not public stays within membership' => ['group', ['public' => '0'], [2]];
        yield 'old public tag alone does not match' => ['private', ['tags' => '1'], []];
        yield 'old public tag with classification matches classification only' => ['private', ['tags' => '1,2'], [1, 2]];
        yield 'public AND classification' => ['private', ['public' => '1', 'tags' => '2'], [1]];
        yield 'not public AND classification' => ['private', ['public' => '0', 'tags' => '2'], [2]];
        yield 'classification OR has no duplicate rows' => ['private', ['tags' => '2,3'], [1, 2, 4]];
        yield 'nullable tag key preserved' => ['private', ['tags' => '4'], [5]];
    }

    public function testAllTagLookupPathsWorkWithoutTypeColumnAndExcludePublic(): void
    {
        $search = new TagsSearch();
        $this->assertSame([2, 3, 4, 5], $this->ids($search->findAll(), 'tags.id'));
        $this->assertSame([2, 3, 4, 5], $this->ids($search->search(['type' => 'Status']), 'tags.id'));
        $this->assertSame([], $this->ids($search->findById(1), 'tags.id'));
        $this->assertSame([2, 3, 4, 5], $this->ids($search->search(), 'tags.id'));
        $this->assertSame([3], $this->ids($search->search(['type' => 'Classify', 'name' => 'B']), 'tags.id'));
    }

    #[DataProvider('tagColumnStates')]
    public function testFullTagRecordsLoadAndSerializeThroughBothApiVersions(bool $hasLegacyTypeColumn): void
    {
        if ($hasLegacyTypeColumn) {
            $this->fixture->exec('ALTER TABLE tags ADD COLUMN type TEXT');
            $this->fixture->exec("UPDATE tags SET type = CASE id WHEN 1 THEN 'Status' WHEN 2 THEN 'Classify' WHEN 3 THEN 'Custom' WHEN 5 THEN 'Status' END");
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ($severity === E_DEPRECATED) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
        try {
            $this->assertFullTagRecordsLoadAndSerialize($hasLegacyTypeColumn);
        } finally {
            restore_error_handler();
        }
    }

    public static function tagColumnStates(): iterable
    {
        yield 'legacy type column remains' => [true];
        yield 'type column removed' => [false];
    }

    private function assertFullTagRecordsLoadAndSerialize(bool $hasLegacyTypeColumn): void
    {
        $this->useFixtureConnectionForActiveRecord();
        $expected = [
            ['id' => 2, 'name' => 'A', 'key' => 'a'],
            ['id' => 3, 'name' => 'B', 'key' => 'b'],
            ['id' => 4, 'name' => 'No key', 'key' => null],
            ['id' => 5, 'name' => 'C', 'key' => 'c'],
        ];
        $records = (new TagsSearch())->findAll()->all();
        $this->assertCount(4, $records);
        foreach ($records as $record) {
            $this->assertInstanceOf(Tags::class, $record);
            $this->assertFalse($record->isNew());
            $this->assertFalse(property_exists($record, 'type'));
            $this->assertSame($hasLegacyTypeColumn, $record->tableSchema()->getColumn('type') !== null);
        }
        $this->assertSame($expected, json_decode(json_encode($records, JSON_THROW_ON_ERROR), true));
        $verse = new Verse();
        $verse->id = 1;
        $this->assertSame(
            array_slice($expected, 0, 2),
            json_decode(json_encode($verse->getTags()->all(), JSON_THROW_ON_ERROR), true),
        );

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('getOrSet')->willReturnCallback(fn(string $key, callable $callback) => $callback());
        $pagination = new PaginationService();
        $service = new SnapshotQueryService(new SnapshotSearch(), new TagsSearch(), $pagination, $cache);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $restFactory = new Yii2RestResponseFactory($responseFactory, $streamFactory);
        $v1 = new ServerController($service, $pagination, $responseFactory, $streamFactory, $restFactory);
        $v2 = new TagController($service, $responseFactory, $streamFactory, $restFactory);

        foreach (['application/json', 'application/xml'] as $accept) {
            $request = (new ServerRequest())->withHeader('Accept', $accept)
                ->withQueryParams(['type' => 'Classify']);
            foreach ([$v1->tags($request), $v2->index($request)] as $response) {
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame($accept . '; charset=UTF-8', $response->getHeaderLine('Content-Type'));
                $body = (string) $response->getBody();
                if ($accept === 'application/json') {
                    $this->assertSame($expected, json_decode($body, true, 512, JSON_THROW_ON_ERROR));
                } else {
                    $document = new DOMDocument();
                    $this->assertTrue($document->loadXML($body));
                    $xpath = new DOMXPath($document);
                    $this->assertSame(0.0, $xpath->evaluate('count(//type)'));
                    $this->assertSame(4.0, $xpath->evaluate('count(/response/item)'));
                    foreach ($expected as $index => $tag) {
                        $item = '/response/item[' . ($index + 1) . ']';
                        $this->assertSame((string) $tag['id'], $xpath->evaluate("string({$item}/id)"));
                        $this->assertSame($tag['name'], $xpath->evaluate("string({$item}/name)"));
                        $this->assertSame((string) $tag['key'], $xpath->evaluate("string({$item}/key)"));
                    }
                }
            }
        }
    }

    public function testDeletingLegacyPublicTagDoesNotChangePublicOrClassificationResults(): void
    {
        $search = new SnapshotSearch();
        $queries = [
            $search->searchPublic(),
            $search->searchPrivate(10, ['public' => '0']),
            $search->searchPrivate(10, ['tags' => '2,3']),
            $search->searchGroup(10, ['public' => '1']),
            $search->searchCheckin(['public' => '0']),
        ];
        $before = array_map(fn(ActiveQuery $query): array => $this->ids($query, 'snapshot.id'), $queries);
        $this->fixture->exec('DELETE FROM verse_tags WHERE tags_id = 1');
        $this->fixture->exec('DELETE FROM tags WHERE id = 1');
        $after = array_map(fn(ActiveQuery $query): array => $this->ids($query, 'snapshot.id'), $queries);

        $this->assertSame($before, $after);
        $this->assertSame(3, (int) $this->fixture->query('SELECT COUNT(*) FROM verse_property WHERE property_id = 1')->fetchColumn());
    }

    public function testVerseTagRelationExcludesLegacyPublicTag(): void
    {
        $query = (new Verse())->getTags();
        $this->assertSame(['or', ['<>', 'tags.key', 'public'], ['tags.key' => null]], $query->getWhere());
    }

    #[DataProvider('validPublicValues')]
    public function testPublicFilterNormalizesExplicitValues(mixed $input, int $expected): void
    {
        $this->assertSame($expected, SnapshotSearch::normalizePublicFilter(['public' => $input]));
        $this->assertNull(SnapshotSearch::normalizePublicFilter([]));
    }

    public static function validPublicValues(): iterable
    {
        foreach ([1, '1', true, 'true'] as $value) {
            yield [$value, 1];
        }
        foreach ([0, '0', false, 'false'] as $value) {
            yield [$value, 0];
        }
    }

    #[DataProvider('invalidPublicValues')]
    public function testInvalidPublicFilterIsRejected(mixed $input): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(400);
        (new SnapshotSearch())->searchPrivate(10, ['public' => $input]);
    }

    public static function invalidPublicValues(): iterable
    {
        foreach ([null, '', 'foo', '2', 2, [], ['1']] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidEndpointFilters')]
    public function testBothApiVersionsReturnHttp400BeforeCacheLookup(string $scope, mixed $input): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('getOrSet');
        $pagination = new PaginationService();
        $service = new SnapshotQueryService(new SnapshotSearch(), new TagsSearch(), $pagination, $cache);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $restFactory = new Yii2RestResponseFactory($responseFactory, $streamFactory);
        $v1 = new ServerController($service, $pagination, $responseFactory, $streamFactory, $restFactory);
        $v2 = new SnapshotController($service, $pagination, $responseFactory, $streamFactory, new CurrentRoute(), $restFactory);
        $request = (new ServerRequest())
            ->withQueryParams(['scope' => $scope, 'public' => $input])
            ->withAttribute('user', ['user_id' => 10]);
        $v1Method = match ($scope) {
            'public' => 'listPublic',
            'private' => 'listPrivate',
            default => $scope,
        };
        foreach ([$v1->$v1Method($request), $v2->index($request)] as $response) {
            $this->assertSame(400, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(400, $body['status']);
            $this->assertSame('Bad Request', $body['name']);
        }
    }

    public static function invalidEndpointFilters(): iterable
    {
        foreach (['public', 'checkin', 'private', 'group'] as $scope) {
            foreach (self::invalidPublicValues() as [$input]) {
                yield [$scope, $input];
            }
        }
    }

    private function ids(ActiveQuery $query, string $column): array
    {
        $command = $query->select([$column])->createCommand();
        $statement = $this->fixture->prepare($command->getSql());
        $statement->execute($command->getParams());
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        return $ids;
    }

    /**
     * Adapt only DB transport and schema discovery to the isolated SQLite table.
     * Query construction, ActiveQuery::all(), Tags hydration and API serialization stay real.
     */
    private function useFixtureConnectionForActiveRecord(): void
    {
        $mysql = ConnectionProvider::get();
        $factory = $mysql->getColumnFactory();
        $tables = [];
        foreach (['tags', 'verse', 'verse_tags'] as $name) {
            $table = new TableSchema($name);
            foreach ($this->fixture->query("PRAGMA table_info({$name})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $type = $column['type'] === 'INTEGER' ? ColumnType::INTEGER : ColumnType::STRING;
                $table->column($column['name'], $factory->fromType($type, ['name' => $column['name']]));
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
            $command->method('queryAll')->willReturnCallback(function () use ($sql, $params): array {
                $statement = $this->fixture->prepare($sql);
                foreach ($params as $name => $value) {
                    if ($value instanceof Param) {
                        $statement->bindValue($name, $value->value, $value->type);
                    } else {
                        $statement->bindValue($name, $value);
                    }
                }
                $statement->execute();
                return $statement->fetchAll(PDO::FETCH_ASSOC);
            });
            return $command;
        });
        ConnectionProvider::set($connection);
    }
}
