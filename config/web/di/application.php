<?php

declare(strict_types=1);

use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Yii\Http\Application;

/**
 * Application DI configuration.
 * Configures the middleware pipeline for the HTTP application.
 */
return [
    Application::class => static function (
        MiddlewareDispatcher $dispatcher,
        EventDispatcherInterface $eventDispatcher,
    ) use ($params): Application {
        return new Application(
            $dispatcher->withMiddlewares($params['middlewares']),
            $eventDispatcher,
        );
    },
];
