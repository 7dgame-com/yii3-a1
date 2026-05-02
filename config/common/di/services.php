<?php

declare(strict_types=1);

use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\HealthCheckService;
use App\Service\PaginationService;
use App\Service\RefreshTokenService;
use App\Service\SnapshotDiagnosticsService;
use App\Service\SnapshotQueryService;
use Predis\Client as RedisClient;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Business services DI configuration.
 */
return [
    RefreshTokenService::class => [
        'class' => RefreshTokenService::class,
        '__construct()' => [
            'redis' => \Yiisoft\Definitions\Reference::to(RedisClient::class),
        ],
    ],
    SnapshotQueryService::class => [
        'class' => SnapshotQueryService::class,
        '__construct()' => [
            'snapshotSearch' => \Yiisoft\Definitions\Reference::to(SnapshotSearch::class),
            'tagsSearch' => \Yiisoft\Definitions\Reference::to(TagsSearch::class),
            'paginationService' => \Yiisoft\Definitions\Reference::to(PaginationService::class),
            'cache' => \Yiisoft\Definitions\Reference::to(YiiCacheInterface::class),
        ],
    ],
    HealthCheckService::class => [
        'class' => HealthCheckService::class,
        '__construct()' => [
            'db' => \Yiisoft\Definitions\Reference::to(ConnectionInterface::class),
            'redis' => \Yiisoft\Definitions\Reference::to(RedisClient::class),
        ],
    ],
    SnapshotDiagnosticsService::class => [
        'class' => SnapshotDiagnosticsService::class,
        '__construct()' => [
            'db' => \Yiisoft\Definitions\Reference::to(ConnectionInterface::class),
        ],
    ],
];
