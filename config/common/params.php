<?php

declare(strict_types=1);

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
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
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
