<?php

declare(strict_types=1);

/**
 * Test-only companion for tools/identity/test-qr-login-code-cross-service.mjs.
 *
 * It runs the actual Yii3-A1 LoginCodeStore against the isolated Redis service.
 * Bearer codes arrive on STDIN and are never included in output, exceptions, or
 * command-line arguments.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

/** @param array<string, mixed> $payload */
function qrLoginCodeCrossServiceResolveEmit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

/**
 * @return list<array{id: string, code: string, user_id: int}>
 */
function qrLoginCodeCrossServiceResolveInput(): array
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['action'] ?? null) !== 'resolve' || !isset($decoded['records']) || !is_array($decoded['records'])) {
        throw new RuntimeException('Invalid probe input.');
    }

    $records = [];
    foreach ($decoded['records'] as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Invalid probe record.');
        }

        $id = $record['id'] ?? null;
        $code = $record['code'] ?? null;
        $userId = $record['user_id'] ?? null;
        if (
            !is_string($id)
            || !in_array($id, ['a', 'b'], true)
            || !is_string($code)
            || preg_match('/^[A-Za-z0-9_-]{64}$/D', $code) !== 1
            || str_starts_with($code, 'web_')
            || !is_int($userId)
            || $userId <= 0
        ) {
            throw new RuntimeException('Invalid probe record.');
        }

        $records[] = [
            'id' => $id,
            'code' => $code,
            'user_id' => $userId,
        ];
    }

    // The exact a/b/a sequence proves that a second read of the same code is
    // non-consuming, while a separate code remains independently readable.
    if (
        count($records) !== 3
        || array_column($records, 'id') !== ['a', 'b', 'a']
        || !hash_equals($records[0]['code'], $records[2]['code'])
        || hash_equals($records[0]['code'], $records[1]['code'])
    ) {
        throw new RuntimeException('Invalid probe sequence.');
    }

    return $records;
}

function qrLoginCodeCrossServiceResolveAssertIsolatedTestEnvironment(): void
{
    // The Dockerfile copies tests into an image, so keep a second, in-probe
    // guard in addition to the runner's exact-container verification.
    if (getenv('DEPLOYMENT_MODE') !== 'test' || getenv('REDIS_DB') !== '15') {
        throw new RuntimeException('This probe only runs in the isolated test environment.');
    }
}

try {
    $records = qrLoginCodeCrossServiceResolveInput();
    qrLoginCodeCrossServiceResolveAssertIsolatedTestEnvironment();
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

    // Redis-only mode never asks this connection for a query. Supplying the
    // production Connection implementation still exercises the real A1
    // LoginCodeReadinessGate instead of a test double.
    $db = new Yiisoft\Db\Mysql\Connection(
        new Yiisoft\Db\Mysql\Driver('mysql:host=unused;dbname=unused;charset=utf8mb4', 'unused', ''),
        new Yiisoft\Db\Cache\SchemaCache(new Yiisoft\Cache\ArrayCache()),
    );
    $readiness = new App\Service\LoginCodeReadinessGate($redisClient, $db, $settings, 15);
    $store = new App\Service\LoginCodeStore($redisClient, $settings, $readiness);

    $gateResult = $readiness->check();
    if (!$gateResult->required || !$gateResult->ready) {
        throw new RuntimeException('Readiness gate is not ready.');
    }

    $readCounts = ['a' => 0, 'b' => 0];
    foreach ($records as $record) {
        $result = $store->resolve($record['code']);
        if (
            $result->status !== App\Service\LoginCodeLookupStatus::HIT
            || $result->userId !== $record['user_id']
        ) {
            throw new RuntimeException('Unexpected resolve result.');
        }

        ++$readCounts[$record['id']];
    }

    if ($readCounts !== ['a' => 2, 'b' => 1]) {
        throw new RuntimeException('Unexpected read count.');
    }

    qrLoginCodeCrossServiceResolveEmit([
        'ok' => true,
        'operation' => 'resolve',
        'main_api_records_read' => true,
        'same_code_repeat_hit' => true,
        'independent_a_b_records_hit' => true,
        'read_counts' => $readCounts,
    ]);
} catch (Throwable) {
    // Keep failures redacted: inputs contain bearer credentials.
    qrLoginCodeCrossServiceResolveEmit([
        'ok' => false,
        'error' => 'probe_failed',
    ], 1);
}
