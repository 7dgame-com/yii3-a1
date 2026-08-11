<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\UrlMatcherInterface;

final class RouterDiTest extends TestCase
{
    public function testMatcherCacheKeyIsNamespacedAndChangesWithRoutesFile(): void
    {
        $params = [];
        $definitions = require dirname(__DIR__, 3) . '/config/web/di/router.php';
        $factory = $definitions[UrlMatcherInterface::class];
        $routesFile = dirname(__DIR__, 3) . '/config/web/routes.php';
        $expectedKey = 'yii3-a1-routes-' . hash_file('sha256', $routesFile);

        $routeCollection = $this->createMock(RouteCollectionInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('has')
            ->with($expectedKey)
            ->willReturn(false);

        $matcher = $factory($routeCollection, $cache);

        $this->assertInstanceOf(UrlMatcher::class, $matcher);
        $this->assertNotSame('routes-cache', $expectedKey);
        $this->assertDoesNotMatchRegularExpression('~[{}()/\\\\@:]~', $expectedKey);
    }

    public function testMatcherCanUseAValidPsr16CacheKey(): void
    {
        $params = [];
        $definitions = require dirname(__DIR__, 3) . '/config/web/di/router.php';
        $factory = $definitions[UrlMatcherInterface::class];

        $matcher = $factory(
            $this->createMock(RouteCollectionInterface::class),
            new ArrayCache(),
        );

        $this->assertInstanceOf(UrlMatcher::class, $matcher);
    }
}
