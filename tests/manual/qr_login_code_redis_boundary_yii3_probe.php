<?php

declare(strict_types=1);

/**
 * Test-only Yii3-A1 resolver companion for the isolated real-Redis
 * login-code time/TTL boundary harness.
 *
 * Bearer input is accepted only on STDIN and the output has only the redacted
 * Store outcome. The probe has no HTTP route and refuses non-test Redis
 * environments before instantiating the real Store.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

/** @param array<string, mixed> $payload */
function qrLoginCodeRedisBoundaryYii3Emit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

function qrLoginCodeRedisBoundaryYii3Input(): string
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;
    if (
        !is_string($code)
        || preg_match('/^[A-Za-z0-9_-]{64}$/D', $code) !== 1
        || str_starts_with($code, 'web_')
    ) {
        throw new RuntimeException('Invalid probe input.');
    }

    return $code;
}

function qrLoginCodeRedisBoundaryYii3AssertIsolatedTestEnvironment(): void
{
    // A Docker image can be promoted or reused. Require the explicit test
    // mode and the local compose service coordinates before touching Redis.
    $expected = [
        'DEPLOYMENT_MODE' => 'test',
        'YII_ENV' => 'test',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => '15',
    ];
    foreach ($expected as $name => $value) {
        if (getenv($name) !== $value) {
            throw new RuntimeException('This probe only runs in the isolated test environment.');
        }
    }
}

try {
    $code = qrLoginCodeRedisBoundaryYii3Input();
    qrLoginCodeRedisBoundaryYii3AssertIsolatedTestEnvironment();
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

    // Redis-only mode never queries this connection. Providing the production
    // connection type still executes the real readiness gate rather than a
    // test double.
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

    $result = $store->resolve($code);
    $outcome = $result->status->value;
    if (!in_array($outcome, ['hit', 'expired', 'miss'], true)) {
        throw new RuntimeException('Unexpected resolve result.');
    }

    // No user id, code, digest, payload, Redis configuration, or exception
    // detail leaves this process.
    qrLoginCodeRedisBoundaryYii3Emit([
        'ok' => true,
        'service' => 'yii3-a1-store',
        'outcome' => $outcome,
    ]);
} catch (Throwable) {
    qrLoginCodeRedisBoundaryYii3Emit([
        'ok' => false,
        'error' => 'probe_failed',
    ], 1);
}
