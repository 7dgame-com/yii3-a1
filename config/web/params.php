<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Request\Body\RequestBodyParser;
use Yiisoft\Router\Middleware\Router;

/**
 * Web application parameters.
 * Defines the middleware pipeline order.
 */
return [
    'middlewares' => [
        ErrorCatcher::class,
        CorsMiddleware::class,
        RequestBodyParser::class,
        Router::class,
    ],
];
