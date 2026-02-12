<?php

declare(strict_types=1);

use App\ErrorHandler\ApiErrorRenderer;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;

/**
 * Error handling DI configuration.
 *
 * Registers the custom ApiErrorRenderer as the default error renderer,
 * ensuring all unhandled exceptions are rendered as JSON {status, message}.
 *
 * The ErrorCatcher middleware (first in the pipeline) catches all unhandled
 * exceptions and delegates to ErrorHandler to produce a PSR-7 response.
 * We configure the ErrorHandler with our ApiErrorRenderer and force
 * application/json content type so all errors are rendered consistently.
 *
 * @see Requirements 10.1, 10.3
 */
return [
    // Register our custom renderer as the ThrowableRendererInterface implementation
    ThrowableRendererInterface::class => ApiErrorRenderer::class,
    ApiErrorRenderer::class => ApiErrorRenderer::class,

    // Configure ErrorHandler to use our custom renderer and set debug mode
    ErrorHandler::class => [
        'class' => ErrorHandler::class,
        '__construct()' => [
            'defaultRenderer' => \Yiisoft\Definitions\Reference::to(ApiErrorRenderer::class),
        ],
        'debug()' => [(bool) ($_ENV['YII_DEBUG'] ?? false)],
    ],

    // Configure ErrorCatcher to use our renderer for JSON and force JSON content type
    ErrorCatcher::class => [
        'class' => ErrorCatcher::class,
        'withRenderer()' => ['application/json', ApiErrorRenderer::class],
        'forceContentType()' => ['application/json'],
    ],
];
