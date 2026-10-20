<?php

declare(strict_types=1);

// Requires a disposable MySQL server and a user allowed to create databases.
// Only a uniquely named fixture database is written; it is dropped in finally.
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

$dsn = getenv('TEST_MYSQL_DSN') ?: throw new RuntimeException('TEST_MYSQL_DSN is required.');
$user = getenv('TEST_MYSQL_USER') ?: 'root';
$password = getenv('TEST_MYSQL_PASSWORD') ?: '';
$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'y1_properties_probe_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");

try {
    $fixtureDsn = preg_replace('/;dbname=[^;]*/i', '', $dsn) . ';dbname=' . $database;
    $db = new Connection(new Driver($fixtureDsn, $user, $password), new SchemaCache(new ArrayCache()));
    ConnectionProvider::set($db);
    foreach ([
        'CREATE TABLE snapshot (id INT PRIMARY KEY, verse_id INT, resources JSON, INDEX(verse_id))',
        'CREATE TABLE verse (id INT PRIMARY KEY, author_id INT, INDEX(author_id))',
        'CREATE TABLE property (id INT PRIMARY KEY, `key` VARCHAR(255), INDEX(`key`))',
        'CREATE TABLE verse_property (verse_id INT, property_id INT, INDEX(verse_id,property_id))',
        'CREATE TABLE tags (id INT PRIMARY KEY, `key` VARCHAR(255))',
        'CREATE TABLE verse_tags (verse_id INT, tags_id INT)',
        'CREATE TABLE group_verse (group_id INT, verse_id INT)',
        'CREATE TABLE group_user (group_id INT, user_id INT)',
        'INSERT INTO snapshot (id,verse_id) VALUES (1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8)',
        'INSERT INTO verse VALUES (1,10),(2,10),(3,20),(4,10),(5,10),(6,20),(7,20),(8,10)',
        "INSERT INTO property VALUES (1,'public'),(2,'checkin'),(3,'education'),(4,'games'),(5,'public,checkin'),(6,'教育'),(9,'education')",
        'INSERT INTO verse_property VALUES (1,1),(1,1),(1,2),(1,2),(1,3),(1,3),(1,9),(2,2),(2,3),(3,1),(3,2),(3,3),(4,1),(4,4),(6,3),(7,2),(7,4),(8,5),(8,6)',
        "INSERT INTO tags VALUES (1,'public'),(2,'a'),(3,'b'),(4,NULL)",
        'INSERT INTO verse_tags VALUES (1,2),(1,3),(2,1),(2,2),(3,2),(4,3),(5,4),(6,2)',
        'INSERT INTO group_verse VALUES (7,1),(7,2),(7,4),(7,6),(8,3),(8,7)',
        'INSERT INTO group_user VALUES (7,10),(8,20)',
    ] as $sql) {
        $db->createCommand($sql)->execute();
    }

    $service = new SnapshotQueryService(new SnapshotSearch(), new TagsSearch(), new PaginationService(), new Cache(new ArrayCache()));
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        ++$checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    $ids = static fn ($page): array => array_map(static fn ($item): int => $item->get('id'), $page->items);
    $expect = static function ($page, array $expected, string $message) use ($ids, $check): void {
        $actual = $ids($page);
        sort($actual);
        sort($expected);
        $check($actual === $expected, $message . ': ' . json_encode($actual));
        $check($page->totalCount === count($expected), $message . ' total count');
    };

    $expect($service->findPublic([]), [1, 3, 4], 'Existing public scope, duplicate pivots');
    $expect($service->findCheckin([]), [1, 2, 3, 7], 'Existing checkin scope');
    $expect($service->findPublic(['properties' => 'public']), [1, 3, 4], 'Single public property');
    $expect($service->findPublic(['properties' => 'public,checkin']), [1, 3], 'Properties use AND');
    $expect($service->findPublic(['properties' => 'checkin']), [1, 3], 'Properties cannot widen public scope');
    $expect($service->findCheckin(['properties' => 'education']), [1, 2, 3], 'Arbitrary key in checkin scope');
    $expect($service->findCheckin(['properties' => 'education', 'public' => '0']), [2], 'Properties AND non-public');
    $expect($service->findPrivate(10, ['properties' => 'education']), [1, 2], 'Property cannot bypass ownership');
    $expect($service->findPrivate(20, ['properties' => 'education']), [3, 6], 'Cache separates users');
    $expect($service->findGroup(10, ['properties' => 'education']), [1, 2, 6], 'Property cannot bypass membership');
    $expect($service->findGroup(20, ['properties' => 'education']), [3], 'Group cache separates users');
    $expect($service->findPrivate(10, ['properties' => 'public,education', 'tags' => '2,3']), [1], 'Properties AND classification OR');
    $expect($service->findPublic(['properties' => 'education,games']), [], 'No OR fallback');
    $expect($service->findPublic(['properties' => 'unknown']), [], 'Unknown key does not remove filtering');
    $expect($service->findPrivate(10, ['properties' => ['public,checkin']]), [8], 'Array preserves comma in key');
    $expect($service->findPrivate(10, ['properties' => '教育']), [8], 'Unicode property key');
    $expect($service->findPrivate(10, ['properties' => "x') OR 1=1 --"]), [], 'Keys are bound as values');

    $page = $service->findPrivate(10, ['properties' => 'education', 'page' => 2, 'pageSize' => 1]);
    $check($ids($page) === [1] && $page->totalCount === 2 && $page->pageCount === 2, 'Filtered pagination, count and duplicate pivots');
    $check($service->findPublic(['properties' => 'public,checkin']) === $service->findPublic(['properties' => ['checkin', ' public ', 'public']]), 'Canonical cache key reuse');
    try {
        $service->findPublic(['properties' => 'public,']);
        throw new RuntimeException('Invalid filter was accepted.');
    } catch (RuntimeException $exception) {
        $check($exception->getCode() === 400, 'Invalid property filter fails before warm cache');
    }

    echo "PASS: {$checks} MySQL checks for properties AND, scopes, tags, pagination, duplicate associations, Unicode and cache isolation\n";
} finally {
    ConnectionProvider::remove();
    $admin->exec("DROP DATABASE `{$database}`");
}
