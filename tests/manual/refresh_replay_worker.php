<?php

declare(strict_types=1);

// Disposable test worker. Never use application/production data or credentials.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || getenv('Y1_REFRESH_FIXTURE') !== '1'
        || getenv('MYSQL_HOST') !== 'codex-y1-refresh-mysql' || getenv('MYSQL_DB') !== 'y1_refresh_test'
        || getenv('REDIS_HOST') !== 'codex-y1-refresh-redis' || getenv('REDIS_DB') !== '0') throw new RuntimeException();
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $path = $argv[1] ?? '';
    $stat = lstat($path);
    if (!$stat || is_link($path) || ($stat['mode'] & 0077) !== 0) throw new RuntimeException();
    $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $dsn = 'mysql:host=codex-y1-refresh-mysql;dbname=y1_refresh_test;charset=utf8mb4';
    $redis = new Predis\Client(['host' => 'codex-y1-refresh-redis', 'port' => 6379, 'database' => 0]);
    $db = new Yiisoft\Db\Mysql\Connection(new Yiisoft\Db\Mysql\Driver($dsn, 'root', ''),
        new Yiisoft\Db\Cache\SchemaCache(new Yiisoft\Cache\ArrayCache()));
    $accounts = new App\Service\LegacyRefreshAccountRepository($db);
    $store = new App\Service\LegacyRefreshReplayStore($redis, new App\Service\LegacyRefreshReplaySettings(true, $state['key']));
    $service = new App\Service\LegacyRefreshReplayService($store, $accounts);
    if (isset($state['startAt'])) while (microtime(true) < $state['startAt']) usleep(1000);
    if (isset($state['startedMarker'])) touch($state['startedMarker']);
    $started = microtime(true);
    if ($state['operation'] === 'rotate') {
        $response = $service->rotate($state['raw'], ['idempotencyKey' => 'fixture-stable-request'], fn () => null,
            function (array $user, array $context, string $next) use ($state): array {
                if (isset($state['preparedMarker'])) {
                    touch($state['preparedMarker']);
                    $deadline = microtime(true) + 5;
                    while (!is_file($state['releaseMarker'])) {
                        if (microtime(true) > $deadline) throw new RuntimeException('Fixture barrier timed out.', 503);
                        usleep(1000);
                    }
                }
                return ['token' => ['accessToken' => 'fixture.' . bin2hex(random_bytes(8)), 'expires' => '2026-10-13 12:00:00', 'refreshToken' => $next],
                    'nickname' => $user['nickname'], 'user' => ['id' => (int) $user['id'], 'username' => $user['username'], 'nickname' => $user['nickname'], 'fixture' => false]];
            });
        echo json_encode(['status' => 200, 'response' => $response], JSON_THROW_ON_ERROR), PHP_EOL;
    } elseif (in_array($state['operation'], ['password', 'disable'], true)) {
        $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=4');
        $pdo->exec($state['operation'] === 'password' ? "UPDATE user SET password_hash='fixture-changed' WHERE id=42" : 'UPDATE user SET status=0 WHERE id=42');
        echo json_encode(['status' => 200, 'elapsedMs' => (int) ((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), PHP_EOL;
    } elseif ($state['operation'] === 'revoke-user') {
        (new App\Service\RefreshTokenService($redis, null, $service))->deleteByUserId(42);
        echo json_encode(['status' => 200, 'elapsedMs' => (int) ((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), PHP_EOL;
    } else throw new RuntimeException();
} catch (Throwable $error) {
    echo json_encode(['status' => in_array($error->getCode(), [400, 401, 409, 503], true) ? $error->getCode() : 500]), PHP_EOL;
    exit(0);
}
