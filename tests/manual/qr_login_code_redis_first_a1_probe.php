<?php

declare(strict_types=1);

/**
 * Test-only Yii3-A1 companion for the isolated redis-first fallback harness.
 *
 * It creates `user_linked` only if that table is absent in the isolated test
 * database and tags the table so cleanup can drop only the table this probe
 * created. Resolver input is accepted only over STDIN and all output is
 * categorical; no code, digest, payload, token, DB setting, or exception
 * detail is emitted.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

const QR_LOGIN_CODE_REDIS_FIRST_FIXTURE_TABLE_COMMENT = 'qr_login_code_redis_first_fallback_harness_v1';

/** @param array<string, mixed> $payload */
function qrLoginCodeRedisFirstA1Emit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

function qrLoginCodeRedisFirstA1ValidCode(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[A-Za-z0-9_-]{64}$/D', $value) === 1
        && !str_starts_with($value, 'web_');
}

/**
 * @return array{action: string, transport?: string, code?: string, records?: list<array{code: string, user_id: int, age_seconds: int}>}
 */
function qrLoginCodeRedisFirstA1Input(): array
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid probe input.');
    }

    $action = $decoded['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['setup_fixture', 'cleanup_fixture', 'resolve'], true)) {
        throw new RuntimeException('Invalid probe action.');
    }

    if ($action === 'cleanup_fixture') {
        return ['action' => $action];
    }

    if ($action === 'resolve') {
        $code = $decoded['code'] ?? null;
        $transport = $decoded['transport'] ?? 'live';
        if (!qrLoginCodeRedisFirstA1ValidCode($code) || !is_string($transport) || !in_array($transport, ['live', 'unavailable'], true)) {
            throw new RuntimeException('Invalid probe input.');
        }
        return [
            'action' => $action,
            'code' => $code,
            'transport' => $transport,
        ];
    }

    $records = $decoded['records'] ?? null;
    if (!is_array($records) || count($records) < 3 || count($records) > 8) {
        throw new RuntimeException('Invalid fixture records.');
    }

    $normalized = [];
    $userIds = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Invalid fixture record.');
        }
        $code = $record['code'] ?? null;
        $userId = $record['user_id'] ?? null;
        $ageSeconds = $record['age_seconds'] ?? null;
        if (
            !qrLoginCodeRedisFirstA1ValidCode($code)
            || !is_int($userId)
            || $userId < 100000000
            || $userId > 2000000000
            || !is_int($ageSeconds)
            || $ageSeconds < 1
            || $ageSeconds > 301
            || isset($userIds[$userId])
        ) {
            throw new RuntimeException('Invalid fixture record.');
        }
        $userIds[$userId] = true;
        $normalized[] = [
            'code' => $code,
            'user_id' => $userId,
            'age_seconds' => $ageSeconds,
        ];
    }

    return [
        'action' => $action,
        'records' => $normalized,
    ];
}

function qrLoginCodeRedisFirstA1AssertIsolatedEnvironment(): void
{
    // The Node runner separately validates test-compose labels and the shared
    // network. This second guard prevents direct execution on a normal image.
    $expected = [
        'DEPLOYMENT_MODE' => 'test',
        'YII_ENV' => 'test',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => '15',
        'LOGIN_CODE_READ_MODE' => 'redis-first',
        'LOGIN_CODE_WRITE_MODE' => 'redis',
        'LOGIN_CODE_LEGACY_DB_AVAILABLE' => 'true',
        'MYSQL_HOST' => 'db',
    ];
    foreach ($expected as $name => $value) {
        if (getenv($name) !== $value) {
            throw new RuntimeException('This probe only runs in the isolated redis-first test environment.');
        }
    }

    $fingerprint = getenv('LOGIN_CODE_PROTOCOL_FINGERPRINT');
    if (!is_string($fingerprint) || !hash_equals(
        App\Service\LoginCodeSettings::defaultProtocolFingerprint(),
        $fingerprint,
    )) {
        throw new RuntimeException('The isolated test protocol fingerprint is invalid.');
    }
}

function qrLoginCodeRedisFirstA1Database(): PDO
{
    $host = getenv('MYSQL_HOST');
    $database = getenv('MYSQL_DB');
    $username = getenv('MYSQL_USER');
    $password = getenv('MYSQL_PASS');
    if (!is_string($host) || !is_string($database) || !is_string($username) || !is_string($password) || $database === '') {
        throw new RuntimeException('Test database configuration is unavailable.');
    }

    return new PDO(
        'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

function qrLoginCodeRedisFirstA1FixtureTableComment(PDO $database): ?string
{
    $statement = $database->prepare(
        'SELECT TABLE_COMMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName LIMIT 1',
    );
    $statement->execute([':tableName' => 'user_linked']);
    $comment = $statement->fetchColumn();

    return is_string($comment) ? $comment : null;
}

function qrLoginCodeRedisFirstA1RedisNowSeconds(): int
{
    $redis = new Predis\Client([
        'scheme' => 'tcp',
        'host' => 'redis',
        'port' => 6379,
        'database' => 15,
    ]);
    $time = $redis->time();
    if (!is_array($time) || count($time) < 2 || !is_numeric($time[0] ?? null)) {
        throw new RuntimeException('Isolated Redis time is unavailable.');
    }

    return (int) $time[0];
}

/** @param list<array{code: string, user_id: int, age_seconds: int}> $records */
function qrLoginCodeRedisFirstA1SetupFixture(PDO $database, array $records): void
{
    // Do not read, update, or remove an existing `user_linked` table. The
    // isolated test DB is expected to be empty for this harness; any existing
    // table is another process's state and is a hard stop.
    if (qrLoginCodeRedisFirstA1FixtureTableComment($database) !== null) {
        throw new RuntimeException('An existing user_linked table cannot be used by this harness.');
    }

    $database->exec(
        'CREATE TABLE `user_linked` ('
        . '`id` INT NOT NULL AUTO_INCREMENT, '
        . '`user_id` INT NOT NULL, '
        . '`key` VARCHAR(255) NOT NULL, '
        . '`created_at` DATETIME NULL, '
        . 'PRIMARY KEY (`id`), '
        . 'KEY `idx_qr_login_code_redis_first_user_id` (`user_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
        . "COMMENT='" . QR_LOGIN_CODE_REDIS_FIRST_FIXTURE_TABLE_COMMENT . "'",
    );

    try {
        $redisNow = qrLoginCodeRedisFirstA1RedisNowSeconds();
        $insert = $database->prepare(
            'INSERT INTO `user_linked` (`user_id`, `key`, `created_at`) '
            . 'VALUES (:userId, :keyDigest, :createdAt)',
        );
        foreach ($records as $record) {
            $createdAt = $redisNow - $record['age_seconds'];
            $createdAtShanghai = (new DateTimeImmutable('@' . $createdAt))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d H:i:s');
            $insert->execute([
                ':userId' => $record['user_id'],
                ':keyDigest' => hash('sha256', $record['code']),
                ':createdAt' => $createdAtShanghai,
            ]);
        }
    } catch (Throwable $exception) {
        // Best-effort removal is limited to the just-created tagged table.
        if (hash_equals(
            QR_LOGIN_CODE_REDIS_FIRST_FIXTURE_TABLE_COMMENT,
            (string) qrLoginCodeRedisFirstA1FixtureTableComment($database),
        )) {
            $database->exec('DROP TABLE `user_linked`');
        }
        throw $exception;
    }
}

function qrLoginCodeRedisFirstA1CleanupFixture(PDO $database): void
{
    $comment = qrLoginCodeRedisFirstA1FixtureTableComment($database);
    if ($comment === null) {
        return;
    }
    if (!hash_equals(QR_LOGIN_CODE_REDIS_FIRST_FIXTURE_TABLE_COMMENT, $comment)) {
        throw new RuntimeException('The user_linked table was not created by this harness.');
    }

    // The whole table was created by this probe only after confirming it was
    // absent. Dropping this exact tagged table cannot affect an existing
    // develop user_linked table or another test fixture.
    $database->exec('DROP TABLE `user_linked`');
}

function qrLoginCodeRedisFirstA1Store(string $transport): App\Service\LoginCodeStore
{
    $settings = new App\Service\LoginCodeSettings(
        readMode: (string) getenv('LOGIN_CODE_READ_MODE'),
        writeMode: (string) getenv('LOGIN_CODE_WRITE_MODE'),
        prefix: (string) getenv('LOGIN_CODE_REDIS_PREFIX'),
        activeWindowSeconds: (string) getenv('LOGIN_CODE_ACTIVE_WINDOW_SECONDS'),
        recordRetentionSeconds: (string) getenv('LOGIN_CODE_RECORD_TTL_SECONDS'),
        issueLimit: (string) getenv('LOGIN_CODE_ISSUE_LIMIT'),
        issueWindowSeconds: (string) getenv('LOGIN_CODE_ISSUE_WINDOW_SECONDS'),
        legacyDbAvailable: (string) getenv('LOGIN_CODE_LEGACY_DB_AVAILABLE'),
        expectedProtocolFingerprint: (string) getenv('LOGIN_CODE_PROTOCOL_FINGERPRINT'),
    );

    $redis = new Predis\Client([
        'scheme' => 'tcp',
        'host' => $transport === 'unavailable' ? '127.0.0.1' : 'redis',
        'port' => $transport === 'unavailable' ? 1 : 6379,
        'database' => 15,
        'timeout' => 0.2,
        'read_write_timeout' => 0.2,
    ]);
    $redisClient = new App\Service\PredisLoginCodeRedisClient($redis);
    $pdo = qrLoginCodeRedisFirstA1Database();
    $db = new Yiisoft\Db\Mysql\Connection(
        new Yiisoft\Db\Mysql\Driver(
            'mysql:host=' . (string) getenv('MYSQL_HOST') . ';dbname=' . (string) getenv('MYSQL_DB') . ';charset=utf8mb4',
            (string) getenv('MYSQL_USER'),
            (string) getenv('MYSQL_PASS'),
        ),
        new Yiisoft\Db\Cache\SchemaCache(new Yiisoft\Cache\ArrayCache()),
    );
    // PDO construction above verifies the test DB configuration. The Yii3 DB
    // connection below is the real production connection type used by the
    // Store and readiness gate for fallback queries.
    unset($pdo);
    Yiisoft\Db\Connection\ConnectionProvider::set($db);
    $readiness = new App\Service\LoginCodeReadinessGate($redisClient, $db, $settings, 15);

    return new App\Service\LoginCodeStore($redisClient, $settings, $readiness);
}

try {
    $input = qrLoginCodeRedisFirstA1Input();
    $basePath = dirname(__DIR__, 2);
    require_once $basePath . '/vendor/autoload.php';
    qrLoginCodeRedisFirstA1AssertIsolatedEnvironment();

    if ($input['action'] === 'setup_fixture') {
        qrLoginCodeRedisFirstA1SetupFixture(qrLoginCodeRedisFirstA1Database(), $input['records']);
        qrLoginCodeRedisFirstA1Emit(['ok' => true, 'operation' => 'setup_fixture']);
    }

    if ($input['action'] === 'cleanup_fixture') {
        qrLoginCodeRedisFirstA1CleanupFixture(qrLoginCodeRedisFirstA1Database());
        qrLoginCodeRedisFirstA1Emit(['ok' => true, 'operation' => 'cleanup_fixture']);
    }

    $store = qrLoginCodeRedisFirstA1Store($input['transport']);
    $result = $store->resolve($input['code']);
    qrLoginCodeRedisFirstA1Emit([
        'ok' => true,
        'service' => 'yii3-a1-store',
        'outcome' => $result->status->value,
    ]);
} catch (Throwable) {
    qrLoginCodeRedisFirstA1Emit([
        'ok' => false,
        'error' => 'probe_failed',
    ], 1);
}
