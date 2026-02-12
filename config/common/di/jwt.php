<?php

declare(strict_types=1);

use App\Service\JwtService;

/**
 * JWT Service DI configuration.
 * Registers JwtService with the key file path from params.
 */
return [
    JwtService::class => [
        'class' => JwtService::class,
        '__construct()' => [
            'keyFilePath' => $params['jwt']['keyFile'],
        ],
    ],
];
