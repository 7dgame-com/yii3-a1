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

        // Keep this authentication-facing connection free of query logging
        // and profiling. LoginCodeStore's redis-first compatibility lookup
        // binds bearer-equivalent legacy code material; any future debug or
        // observability integration must redact those parameters before it
        // decorates this connection.
        return new Connection($driver, $schemaCache);
    },
];
