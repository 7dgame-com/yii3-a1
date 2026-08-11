<?php

declare(strict_types=1);

/**
 * Parse environment values before they are handed to typed DI definitions.
 * This deliberately rejects PHP's lossy `(int) "60abc"` coercion so an
 * invalid login-code protocol setting stops startup rather than silently
 * changing its effective configuration.
 */
$integerEnvironment = static function (string $name, int $default): int {
    $value = $_ENV[$name] ?? $default;

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
        return (int) trim($value);
    }

    throw new \InvalidArgumentException($name . ' must be an integer.');
};

$booleanEnvironment = static function (string $name, bool $default): bool {
    $value = $_ENV[$name] ?? $default;

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) && ($value === 0 || $value === 1)) {
        return (bool) $value;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }

    throw new \InvalidArgumentException($name . ' must be a boolean.');
};

$redisPort = $integerEnvironment('REDIS_PORT', 6379);
$redisDatabase = $integerEnvironment('REDIS_DB', 0);
if ($redisDatabase < 0) {
    throw new \InvalidArgumentException('REDIS_DB must be a non-negative integer.');
}

/**
 * Common parameters shared between web and console applications.
 */
return [
    'app' => [
        'name' => 'MrPP API',
        'version' => '3.0.0',
        'timezone' => 'Asia/Shanghai',
        'charset' => 'UTF-8',
    ],

    // Aliases for Yii3 path resolution
    'yiisoft/aliases' => [
        'aliases' => [
            '@root' => dirname(__DIR__, 2),
            '@runtime' => dirname(__DIR__, 2) . '/runtime',
        ],
    ],

    // Database configuration (from environment variables)
    'db' => [
        'dsn' => sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $_ENV['MYSQL_HOST'] ?? 'localhost',
            $_ENV['MYSQL_DB'] ?? 'mrpp',
        ),
        'username' => $_ENV['MYSQL_USER'] ?? 'root',
        'password' => $_ENV['MYSQL_PASS'] ?? '',
    ],

    // Redis configuration (from environment variables)
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => $redisPort,
        'database' => $redisDatabase,
    ],

    // Shared short-lived QR login-code protocol. Defaults intentionally keep
    // the legacy DB-only read/write behaviour until develop explicitly opts
    // into a Redis migration phase.
    'loginCode' => [
        'readMode' => $_ENV['LOGIN_CODE_READ_MODE'] ?? 'database',
        'writeMode' => $_ENV['LOGIN_CODE_WRITE_MODE'] ?? 'database',
        'prefix' => $_ENV['LOGIN_CODE_REDIS_PREFIX'] ?? 'auth:login-code:v1',
        'protocolFingerprint' => $_ENV['LOGIN_CODE_PROTOCOL_FINGERPRINT']
            ?? \App\Service\LoginCodeSettings::defaultProtocolFingerprint(),
        'activeWindowSeconds' => $integerEnvironment('LOGIN_CODE_ACTIVE_WINDOW_SECONDS', 60),
        'recordRetentionSeconds' => $integerEnvironment('LOGIN_CODE_RECORD_TTL_SECONDS', 300),
        'issueLimit' => $integerEnvironment('LOGIN_CODE_ISSUE_LIMIT', 5),
        'issueWindowSeconds' => $integerEnvironment('LOGIN_CODE_ISSUE_WINDOW_SECONDS', 60),
        'legacyDbAvailable' => $booleanEnvironment('LOGIN_CODE_LEGACY_DB_AVAILABLE', true),
    ],

    // JWT configuration
    'jwt' => [
        'keyFile' => $_ENV['JWT_KEY'] ?? '',
        'ttl' => 10800, // 3 hours in seconds
    ],

    // Cache configuration
    'cache' => [
        'defaultTtl' => 30, // 30 seconds for snapshot queries
    ],
];
