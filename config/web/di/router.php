<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollectorInterface;
use Yiisoft\Router\UrlMatcherInterface;

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
    UrlMatcherInterface::class => static function (
        RouteCollectionInterface $routeCollection,
        CacheInterface $cache,
    ): UrlMatcherInterface {
        $routesFile = dirname(__DIR__, 2) . '/web/routes.php';
        $routesHash = hash_file('sha256', $routesFile);
        if ($routesHash === false) {
            throw new \RuntimeException('Unable to fingerprint the route configuration.');
        }

        return new UrlMatcher($routeCollection, $cache, [
            // PSR-16 cache keys reject reserved characters such as ":".
            UrlMatcher::CONFIG_CACHE_KEY => 'yii3-a1-routes-' . $routesHash,
        ]);
    },
];
