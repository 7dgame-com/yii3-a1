<?php

declare(strict_types=1);

/**
 * Test-only Yii3-A1 reader companion for the concurrent main-API issuer.
 *
 * Codes arrive only through STDIN and this probe returns aggregate booleans
 * and counts. It never prints a code, digest, Redis key/value, exception, or
 * configuration. Redis-only settings prevent all Legacy_DB/user_linked reads.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

const QR_LOGIN_CODE_CONCURRENCY = 4;

/** @param array<string, mixed> $payload */
function qrLoginCodeConcurrentResolveEmit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

/**
 * @return array{user_id: int, codes: list<string>}
 */
function qrLoginCodeConcurrentResolveInput(): array
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (
        !is_array($decoded)
        || ($decoded['action'] ?? null) !== 'resolve_many'
        || !isset($decoded['user_id'], $decoded['codes'])
        || !is_int($decoded['user_id'])
        || $decoded['user_id'] <= 0
        || !is_array($decoded['codes'])
        || count($decoded['codes']) !== QR_LOGIN_CODE_CONCURRENCY
    ) {
        throw new RuntimeException('Invalid probe input.');
    }

    $codes = [];
    foreach ($decoded['codes'] as $code) {
        if (
            !is_string($code)
            || preg_match('/^[A-Za-z0-9_-]{64}$/D', $code) !== 1
            || str_starts_with($code, 'web_')
        ) {
            throw new RuntimeException('Invalid probe code.');
        }
        $codes[] = $code;
    }

    if (count(array_unique($codes, SORT_STRING)) !== QR_LOGIN_CODE_CONCURRENCY) {
        throw new RuntimeException('Probe codes must be distinct.');
    }

    return [
        'user_id' => $decoded['user_id'],
        'codes' => $codes,
    ];
}

function qrLoginCodeConcurrentResolveAssertIsolatedTestEnvironment(): void
{
    $expected = [
        'DEPLOYMENT_MODE' => 'test',
        'YII_ENV' => 'test',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => '15',
        'LOGIN_CODE_READ_MODE' => 'redis',
        'LOGIN_CODE_WRITE_MODE' => 'redis',
        'LOGIN_CODE_LEGACY_DB_AVAILABLE' => 'false',
    ];
    foreach ($expected as $name => $value) {
        if (getenv($name) !== $value) {
            throw new RuntimeException('This probe only runs in the isolated Redis-only test environment.');
        }
    }
}

try {
    qrLoginCodeConcurrentResolveAssertIsolatedTestEnvironment();
    $input = qrLoginCodeConcurrentResolveInput();
    $basePath = dirname(__DIR__, 2);
    require_once $basePath . '/vendor/autoload.php';

    $settings = new App\Service\LoginCodeSettings(
        readMode: App\Service\LoginCodeSettings::READ_REDIS,
        writeMode: App\Service\LoginCodeSettings::WRITE_REDIS,
        prefix: 'auth:login-code:v1',
        legacyDbAvailable: false,
        expectedProtocolFingerprint: App\Service\LoginCodeSettings::defaultProtocolFingerprint(),
    );
    $redis = new Predis\Client([
        'scheme' => 'tcp',
        'host' => 'redis',
        'port' => 6379,
        'database' => 15,
    ]);
    $redisClient = new App\Service\PredisLoginCodeRedisClient($redis);

    // Redis-only resolve never queries this connection. Keeping the real
    // readiness gate exercises the A1 production boundary without ever
    // constructing or querying a Legacy_DB/user_linked model.
    $db = new Yiisoft\Db\Mysql\Connection(
        new Yiisoft\Db\Mysql\Driver('mysql:host=unused;dbname=unused;charset=utf8mb4', 'unused', ''),
        new Yiisoft\Db\Cache\SchemaCache(new Yiisoft\Cache\ArrayCache()),
    );
    $readiness = new App\Service\LoginCodeReadinessGate($redisClient, $db, $settings, 15);
    $store = new App\Service\LoginCodeStore($redisClient, $settings, $readiness);
    $gate = $readiness->check();
    if (!$gate->required || !$gate->ready) {
        throw new RuntimeException('Readiness gate is not ready.');
    }

    $keys = [];
    // Two passes prove every concurrent Code_Record is independently readable
    // and that the first read of any record did not consume or overwrite it.
    for ($pass = 0; $pass < 2; ++$pass) {
        foreach ($input['codes'] as $code) {
            $result = $store->resolve($code);
            if (
                $result->status !== App\Service\LoginCodeLookupStatus::HIT
                || $result->userId !== $input['user_id']
            ) {
                throw new RuntimeException('Unexpected concurrent resolve result.');
            }

            $keys[] = $store->keyFor($code);
        }
    }

    // Distinct raw codes must map to distinct digest keys. The comparison is
    // internal only; no key or digest leaves this process.
    if (count(array_unique(array_slice($keys, 0, QR_LOGIN_CODE_CONCURRENCY), SORT_STRING)) !== QR_LOGIN_CODE_CONCURRENCY) {
        throw new RuntimeException('Concurrent records did not use distinct keys.');
    }

    qrLoginCodeConcurrentResolveEmit([
        'ok' => true,
        'operation' => 'resolve_many',
        'resolved_count' => QR_LOGIN_CODE_CONCURRENCY,
        'all_records_independent' => true,
        'second_pass_all_hit' => true,
    ]);
} catch (Throwable) {
    qrLoginCodeConcurrentResolveEmit([
        'ok' => false,
        'error' => 'probe_failed',
    ], 1);
}
