<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

/**
 * Database connection DI configuration.
 * MySQL connection configured via environment variables.
 */
return [
    ConnectionInterface::class => static function (CacheInterface $psrCache) use ($params): ConnectionInterface {
        $driver = new Driver(
            $params['db']['dsn'],
            $params['db']['username'],
            $params['db']['password'],
        );
        $schemaCache = new SchemaCache($psrCache);
        return new Connection($driver, $schemaCache);
    },
];
