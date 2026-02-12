<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Bootstrap configuration.
 * Ensures database connection is registered with ConnectionProvider
 * before any ActiveRecord usage.
 */
return [
    static function (ContainerInterface $container): void {
        $db = $container->get(ConnectionInterface::class);
        ConnectionProvider::set($db);
    },
];
