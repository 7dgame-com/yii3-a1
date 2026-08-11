<?php

declare(strict_types=1);

/**
 * Test-only deterministic readiness-gate probe for the isolated QR login-code
 * Docker stack. It consumes the same sampled Redis TIME vector as the main
 * API probe and never reads a credential, Redis key, or database row.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

/** @param array<string, mixed> $payload */
function qrLoginCodeReadinessSkewYii3Emit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

/** @return array{redis_time_milliseconds: int, app_offset_milliseconds: int} */
function qrLoginCodeReadinessSkewYii3Input(): array
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $redisTimeMilliseconds = is_array($decoded) ? ($decoded['redis_time_milliseconds'] ?? null) : null;
    $appOffsetMilliseconds = is_array($decoded) ? ($decoded['app_offset_milliseconds'] ?? null) : null;

    if (
        !is_int($redisTimeMilliseconds)
        || $redisTimeMilliseconds < 1_000_000_000_000
        || $redisTimeMilliseconds > 4_102_444_800_000
        || !is_int($appOffsetMilliseconds)
        || $appOffsetMilliseconds < -2_000
        || $appOffsetMilliseconds > 2_000
    ) {
        throw new RuntimeException('Invalid probe input.');
    }

    return [
        'redis_time_milliseconds' => $redisTimeMilliseconds,
        'app_offset_milliseconds' => $appOffsetMilliseconds,
    ];
}

function qrLoginCodeReadinessSkewYii3AssertIsolatedTestEnvironment(): void
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
    $input = qrLoginCodeReadinessSkewYii3Input();
    qrLoginCodeReadinessSkewYii3AssertIsolatedTestEnvironment();
    $basePath = dirname(__DIR__, 2);
    require_once $basePath . '/vendor/autoload.php';

    $redis = new class($input['redis_time_milliseconds']) implements App\Service\LoginCodeRedisClient {
        public function __construct(private readonly int $timeMilliseconds)
        {
        }

        public function get(string $key): mixed
        {
            return null;
        }

        public function pttl(string $key): mixed
        {
            return -2;
        }

        public function time(): mixed
        {
            return [
                intdiv($this->timeMilliseconds, 1000),
                ($this->timeMilliseconds % 1000) * 1000,
            ];
        }
    };
    $settings = new App\Service\LoginCodeSettings(
        readMode: App\Service\LoginCodeSettings::READ_REDIS,
        writeMode: App\Service\LoginCodeSettings::WRITE_REDIS,
        prefix: 'auth:login-code:v1',
        legacyDbAvailable: false,
        expectedProtocolFingerprint: App\Service\LoginCodeSettings::defaultProtocolFingerprint(),
    );
    // Redis-only mode never queries this connection. Its production type keeps
    // the readiness gate construction identical to the normal consumer path.
    $db = new Yiisoft\Db\Mysql\Connection(
        new Yiisoft\Db\Mysql\Driver('mysql:host=unused;dbname=unused;charset=utf8mb4', 'unused', ''),
        new Yiisoft\Db\Cache\SchemaCache(new Yiisoft\Cache\ArrayCache()),
    );
    $readiness = new App\Service\LoginCodeReadinessGate(
        $redis,
        $db,
        $settings,
        15,
        static fn (): int => $input['redis_time_milliseconds'] + $input['app_offset_milliseconds'],
    );
    $gate = $readiness->check();
    $ready = $gate->ready;
    $reason = $ready ? 'ready' : $gate->reason;
    if (!in_array($reason, ['ready', App\Service\LoginCodeReadinessResult::APP_CLOCK_SKEW], true)) {
        throw new RuntimeException('Unexpected readiness result.');
    }

    $store = new App\Service\LoginCodeStore($redis, $settings, $readiness);
    $consumerOutcome = $store->resolve(str_repeat('A', 64))->status->value;
    $expectedConsumerOutcome = $ready ? 'miss' : 'unavailable';
    if ($consumerOutcome !== $expectedConsumerOutcome) {
        throw new RuntimeException('Readiness gate did not control the consumer path.');
    }

    qrLoginCodeReadinessSkewYii3Emit([
        'ok' => true,
        'service' => 'yii3-a1',
        'readiness' => $reason,
        'consumer_outcome' => $consumerOutcome,
    ]);
} catch (Throwable) {
    qrLoginCodeReadinessSkewYii3Emit([
        'ok' => false,
        'error' => 'probe_failed',
    ], 1);
}
