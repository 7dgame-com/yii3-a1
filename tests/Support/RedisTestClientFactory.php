<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Predis\Client as RedisClient;

/**
 * Creates test Redis clients from the same parameter source as Yii3 runtime.
 *
 * Tests do not own a second host/port/database default. Docker/CI environment
 * variables are copied into $_ENV before the normal params file is evaluated,
 * which also keeps direct local phpunit invocations aligned with the app.
 */
final class RedisTestClientFactory
{
    public static function create(): RedisClient
    {
        foreach (['REDIS_HOST', 'REDIS_PORT', 'REDIS_DB', 'LOGIN_CODE_LEGACY_DB_AVAILABLE'] as $name) {
            if (!array_key_exists($name, $_ENV)) {
                $value = getenv($name);
                if ($value !== false) {
                    $_ENV[$name] = $value;
                }
            }
        }

        /** @var array{redis: array{host: string, port: int, database: int}} $params */
        $params = require dirname(__DIR__, 2) . '/config/common/params.php';
        $redis = $params['redis'];

        return new RedisClient([
            'scheme' => 'tcp',
            'host' => $redis['host'],
            'port' => $redis['port'],
            'database' => $redis['database'],
        ]);
    }
}
