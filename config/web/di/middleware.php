<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\JwtAuthMiddleware;

/**
 * Middleware DI configuration.
 * Registers middleware classes into the DI container.
 */
return [
    CorsMiddleware::class => CorsMiddleware::class,
    JwtAuthMiddleware::class => JwtAuthMiddleware::class,
];
