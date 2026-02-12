<?php

declare(strict_types=1);

use Predis\Client as RedisClient;

/**
 * Redis client DI configuration.
 * Predis client configured via environment variables.
 */
return [
    RedisClient::class => [
        'class' => RedisClient::class,
        '__construct()' => [
            'parameters' => [
                'scheme' => 'tcp',
                'host' => $params['redis']['host'],
                'port' => $params['redis']['port'],
                'database' => $params['redis']['database'],
            ],
        ],
    ],
];
