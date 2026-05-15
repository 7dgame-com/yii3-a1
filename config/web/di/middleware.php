<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SnapshotScopeAuthMiddleware;
use Psr\Container\ContainerInterface;

/**
 * Middleware DI configuration.
 * Registers middleware classes into the DI container.
 */
return [
    CorsMiddleware::class => CorsMiddleware::class,
    JwtAuthMiddleware::class => JwtAuthMiddleware::class,
    SnapshotScopeAuthMiddleware::class => static function (ContainerInterface $container): SnapshotScopeAuthMiddleware {
        return new SnapshotScopeAuthMiddleware($container);
    },
];
