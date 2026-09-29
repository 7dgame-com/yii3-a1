<?php

declare(strict_types=1);

// Run against disposable MySQL 8 with TEST_MYSQL_DSN/USER/PASSWORD.
// Temporary tables shadow real tables and disappear when this connection closes.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Cache\Cache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

$db = new Connection(new Driver(
    getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=repro',
    getenv('TEST_MYSQL_USER') ?: 'root',
    getenv('TEST_MYSQL_PASSWORD') ?: '',
), new SchemaCache(new ArrayCache()));
ConnectionProvider::set($db);
foreach ([
    'CREATE TEMPORARY TABLE verse (id INT PRIMARY KEY, author_id INT, name VARCHAR(255), INDEX(author_id))',
    'CREATE TEMPORARY TABLE snapshot (id INT PRIMARY KEY, verse_id INT, resources JSON, INDEX(verse_id))',
    'CREATE TEMPORARY TABLE group_verse (group_id INT, verse_id INT)',
    'CREATE TEMPORARY TABLE group_user (group_id INT, user_id INT)',
    'CREATE TEMPORARY TABLE verse_tags (verse_id INT, tags_id INT)',
    "INSERT INTO verse VALUES (1,42,'Owned'),(2,99,'Other account')",
    'INSERT INTO group_verse VALUES (1,1),(1,2)',
    'INSERT INTO group_user VALUES (1,42)',
    'INSERT INTO verse_tags VALUES (1,7),(2,8)',
    'SET SESSION sort_buffer_size=262144',
] as $sql) {
    $db->createCommand($sql)->execute();
}
$payload = json_encode([['data' => str_repeat('x', 1000000)]], JSON_THROW_ON_ERROR);
foreach ([[1,1],[2,2],[3,1],[4,2]] as [$id,$verseId]) {
    $db->createCommand()->insert('snapshot', [
        'id' => $id, 'verse_id' => $verseId, 'resources' => $payload,
    ])->execute();
}
$service = new SnapshotQueryService(new SnapshotSearch(), new TagsSearch(), new PaginationService(), new Cache(new ArrayCache()));
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$ids = static fn ($page): array => array_map(static fn ($item) => $item->get('id'), $page->items);
$private = $service->findPrivate(42, ['pageSize' => 1, 'tags' => '0']);
$check($ids($private) === [3], 'Private page must contain latest owned snapshot only');
$check($private->totalCount === 2 && $private->pageCount === 2 && $private->perPage === 1, 'Pagination metadata');
$check($private->items[0]->toExpandedArray(['resources'])['resources'][0]['data'] === str_repeat('x', 1000000), 'Full payload preserved');
$check($ids($service->findPrivate(42, ['pageSize' => 1, 'page' => 2])) === [1], 'Second page ordering');
$check($ids($service->findPrivate(99, ['pageSize' => 1, 'tags' => '0'])) === [4], 'Account cache isolation');
$check($ids($service->findPrivate(123, [])) === [], 'Empty account must not receive public/other snapshots');
$check($ids($service->findPrivate(42, ['tags' => '7'])) === [3,1], 'Tag filter preserved');
$check($ids($service->findPrivate(42, ['tags' => '8'])) === [], 'Tag cannot bypass ownership');
$check($ids($service->findGroup(42, [])) === [4,3,2,1], 'Group ordering with large JSON');
$check($ids($service->findPrivate(42, ['pageSize' => 1, 'tags' => '0'])) === [3], 'Cache hit');
echo "PASS: large JSON private/group pagination, ownership, tags, payload, cache isolation\n";
