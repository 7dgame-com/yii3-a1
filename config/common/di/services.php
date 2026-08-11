<?php

declare(strict_types=1);

use App\Search\SnapshotSearch;
use App\Service\AuthService;
use App\Search\TagsSearch;
use App\Service\HealthCheckService;
use App\Service\JwtService;
use App\Service\LoginCodeSettings;
use App\Service\LoginCodeReadinessGate;
use App\Service\LoginCodeStore;
use App\Service\LoginCodeTelemetry;
use App\Service\PredisLoginCodeRedisClient;
use App\Service\PaginationService;
use App\Service\PhototypeQueryService;
use App\Service\RefreshTokenService;
use App\Service\SnapshotDiagnosticsService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Business services DI configuration.
 */
return [
    LoginCodeSettings::class => [
        'class' => LoginCodeSettings::class,
        '__construct()' => [
            'readMode' => $params['loginCode']['readMode'],
            'writeMode' => $params['loginCode']['writeMode'],
            'prefix' => $params['loginCode']['prefix'],
            'expectedProtocolFingerprint' => $params['loginCode']['protocolFingerprint'],
            'activeWindowSeconds' => $params['loginCode']['activeWindowSeconds'],
            'recordRetentionSeconds' => $params['loginCode']['recordRetentionSeconds'],
            'issueLimit' => $params['loginCode']['issueLimit'],
            'issueWindowSeconds' => $params['loginCode']['issueWindowSeconds'],
            'legacyDbAvailable' => $params['loginCode']['legacyDbAvailable'],
        ],
    ],
    LoginCodeStore::class => [
        'class' => LoginCodeStore::class,
        '__construct()' => [
            'redis' => \Yiisoft\Definitions\Reference::to(PredisLoginCodeRedisClient::class),
            'settings' => \Yiisoft\Definitions\Reference::to(LoginCodeSettings::class),
            'readiness' => \Yiisoft\Definitions\Reference::to(LoginCodeReadinessGate::class),
            'telemetry' => \Yiisoft\Definitions\Reference::to(LoginCodeTelemetry::class),
        ],
    ],
    LoginCodeTelemetry::class => [
        'class' => LoginCodeTelemetry::class,
        '__construct()' => [
            'logger' => \Yiisoft\Definitions\Reference::to(LoggerInterface::class),
        ],
    ],
    LoginCodeReadinessGate::class => [
        'class' => LoginCodeReadinessGate::class,
        '__construct()' => [
            'redis' => \Yiisoft\Definitions\Reference::to(PredisLoginCodeRedisClient::class),
            'db' => \Yiisoft\Definitions\Reference::to(ConnectionInterface::class),
            'settings' => \Yiisoft\Definitions\Reference::to(LoginCodeSettings::class),
            'redisDatabase' => $params['redis']['database'],
        ],
    ],
    PredisLoginCodeRedisClient::class => [
        'class' => PredisLoginCodeRedisClient::class,
        '__construct()' => [
            'redis' => \Yiisoft\Definitions\Reference::to(RedisClient::class),
        ],
    ],
    RefreshTokenService::class => [
        'class' => RefreshTokenService::class,
        '__construct()' => [
            'redis' => \Yiisoft\Definitions\Reference::to(RedisClient::class),
        ],
    ],
    AuthService::class => [
        'class' => AuthService::class,
        '__construct()' => [
            'jwtService' => \Yiisoft\Definitions\Reference::to(JwtService::class),
            'refreshTokenService' => \Yiisoft\Definitions\Reference::to(RefreshTokenService::class),
            'loginCodeStore' => \Yiisoft\Definitions\Reference::to(LoginCodeStore::class),
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
            'loginCodeReadiness' => \Yiisoft\Definitions\Reference::to(LoginCodeReadinessGate::class),
        ],
    ],
    SnapshotDiagnosticsService::class => [
        'class' => SnapshotDiagnosticsService::class,
        '__construct()' => [
            'db' => \Yiisoft\Definitions\Reference::to(ConnectionInterface::class),
        ],
    ],
    PhototypeQueryService::class => [
        'class' => PhototypeQueryService::class,
    ],
    Yii2RestResponseFactory::class => [
        'class' => Yii2RestResponseFactory::class,
        '__construct()' => [
            'responseFactory' => \Yiisoft\Definitions\Reference::to(ResponseFactoryInterface::class),
            'streamFactory' => \Yiisoft\Definitions\Reference::to(StreamFactoryInterface::class),
        ],
    ],
];
