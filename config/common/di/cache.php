<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\Cache;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;
use Yiisoft\Cache\Redis\RedisCache;
use Predis\Client as RedisClient;

/**
 * Cache DI configuration.
 * Uses Redis backend via yiisoft/cache-redis.
 */
return [
    CacheInterface::class => static function (RedisClient $client): CacheInterface {
        return new RedisCache($client);
    },
    YiiCacheInterface::class => static function (CacheInterface $handler): YiiCacheInterface {
        return new Cache($handler);
    },
];
