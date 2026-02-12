<?php

declare(strict_types=1);

use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollectorInterface;

/**
 * Router DI configuration.
 * Registers RouteCollectionInterface and adds routes from config.
 */
return [
    RouteCollectionInterface::class => static function (RouteCollectorInterface $collector) use ($params): RouteCollectionInterface {
        // Add routes from config
        $routesFile = dirname(__DIR__, 2) . '/web/routes.php';
        if (file_exists($routesFile)) {
            $routes = require $routesFile;
            $collector->addRoute(...$routes);
        }
        return new RouteCollection($collector);
    },
];
