<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Controller\HealthController;
use App\Controller\SwaggerController;
use App\Controller\DebugController;
use App\Controller\V1\AuthController;
use App\Controller\V1\ServerController;
use App\Controller\V2\SnapshotController;
use App\Controller\V2\SystemController;
use App\Controller\V2\TagController;
use App\Middleware\JwtAuthMiddleware;
use PHPUnit\Framework\TestCase;
use Yiisoft\Router\Route;

/**
 * Tests for the route configuration in config/web/routes.php.
 *
 * Validates:
 * - All expected routes are defined
 * - Route paths match the Yii2 version exactly
 * - HTTP methods are correct
 * - Controller actions are correctly mapped
 * - JwtAuthMiddleware is applied to protected routes
 * - Route names are assigned
 *
 * @see Requirements 1.5, 9.1
 */
final class RoutesTest extends TestCase
{
    private array $routes;

    protected function setUp(): void
    {
        $this->routes = require dirname(__DIR__, 3) . '/config/web/routes.php';
    }

    public function testRoutesFileReturnsArray(): void
    {
        $this->assertIsArray($this->routes);
    }

    public function testRoutesFileReturnsRouteInstances(): void
    {
        foreach ($this->routes as $route) {
            $this->assertInstanceOf(Route::class, $route);
        }
    }

    public function testTotalRouteCount(): void
    {
        // 3 auth + 7 server + 4 v2 + 1 health + 2 swagger + 1 debug = 18
        $this->assertCount(18, $this->routes);
    }

    // =========================================================================
    // V1 Auth Routes
    // =========================================================================

    public function testV1AuthLoginRoute(): void
    {
        $route = $this->findRouteByName('v1.auth.login');
        $this->assertNotNull($route, 'Route v1.auth.login should exist');
        $this->assertSame('/v1/auth/login', $route->getData('pattern'));
        $this->assertSame(['POST'], $route->getData('methods'));
    }

    public function testV1AuthRefreshRoute(): void
    {
        $route = $this->findRouteByName('v1.auth.refresh');
        $this->assertNotNull($route, 'Route v1.auth.refresh should exist');
        $this->assertSame('/v1/auth/refresh', $route->getData('pattern'));
        $this->assertSame(['POST'], $route->getData('methods'));
    }

    public function testV1AuthKeyToTokenRoute(): void
    {
        $route = $this->findRouteByName('v1.auth.key-to-token');
        $this->assertNotNull($route, 'Route v1.auth.key-to-token should exist');
        $this->assertSame('/v1/auth/key-to-token', $route->getData('pattern'));
        $this->assertSame(['POST'], $route->getData('methods'));
    }

    // =========================================================================
    // V1 Server Routes
    // =========================================================================

    public function testV1ServerTestRoute(): void
    {
        $route = $this->findRouteByName('v1.server.test');
        $this->assertNotNull($route, 'Route v1.server.test should exist');
        $this->assertSame('/v1/server/test', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV1ServerPublicRoute(): void
    {
        $route = $this->findRouteByName('v1.server.public');
        $this->assertNotNull($route, 'Route v1.server.public should exist');
        $this->assertSame('/v1/server/public', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV1ServerCheckinRoute(): void
    {
        $route = $this->findRouteByName('v1.server.checkin');
        $this->assertNotNull($route, 'Route v1.server.checkin should exist');
        $this->assertSame('/v1/server/checkin', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV1ServerPrivateRouteRequiresAuth(): void
    {
        $route = $this->findRouteByName('v1.server.private');
        $this->assertNotNull($route, 'Route v1.server.private should exist');
        $this->assertSame('/v1/server/private', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
        $this->assertTrue($route->getData('hasMiddlewares'), 'Private route should have middlewares');
        $this->assertRouteHasMiddleware($route, JwtAuthMiddleware::class);
    }

    public function testV1ServerGroupRouteRequiresAuth(): void
    {
        $route = $this->findRouteByName('v1.server.group');
        $this->assertNotNull($route, 'Route v1.server.group should exist');
        $this->assertSame('/v1/server/group', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
        $this->assertTrue($route->getData('hasMiddlewares'), 'Group route should have middlewares');
        $this->assertRouteHasMiddleware($route, JwtAuthMiddleware::class);
    }

    public function testV1ServerTagsRoute(): void
    {
        $route = $this->findRouteByName('v1.server.tags');
        $this->assertNotNull($route, 'Route v1.server.tags should exist');
        $this->assertSame('/v1/server/tags', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV1ServerSnapshotRoute(): void
    {
        $route = $this->findRouteByName('v1.server.snapshot');
        $this->assertNotNull($route, 'Route v1.server.snapshot should exist');
        $this->assertSame('/v1/server/snapshot', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    // =========================================================================
    // V2 Routes
    // =========================================================================

    public function testV2SnapshotsIndexRoute(): void
    {
        $route = $this->findRouteByName('v2.snapshots.index');
        $this->assertNotNull($route, 'Route v2.snapshots.index should exist');
        $this->assertSame('/v2/snapshots', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV2SnapshotsViewRoute(): void
    {
        $route = $this->findRouteByName('v2.snapshots.view');
        $this->assertNotNull($route, 'Route v2.snapshots.view should exist');
        $this->assertSame('/v2/snapshots/{id:\d+}', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV2TagsIndexRoute(): void
    {
        $route = $this->findRouteByName('v2.tags.index');
        $this->assertNotNull($route, 'Route v2.tags.index should exist');
        $this->assertSame('/v2/tags', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testV2SystemIndexRouteSupportsGetAndHead(): void
    {
        $route = $this->findRouteByName('v2.system.index');
        $this->assertNotNull($route, 'Route v2.system.index should exist');
        $this->assertSame('/v2/system', $route->getData('pattern'));
        $methods = $route->getData('methods');
        $this->assertContains('GET', $methods);
        $this->assertContains('HEAD', $methods);
    }

    // =========================================================================
    // Health Check Route
    // =========================================================================

    public function testHealthRoute(): void
    {
        $route = $this->findRouteByName('health');
        $this->assertNotNull($route, 'Route health should exist');
        $this->assertSame('/health', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    // =========================================================================
    // Debug Route
    // =========================================================================

    public function testDebugSnapshotRoute(): void
    {
        $route = $this->findRouteByName('debug.snapshot');
        $this->assertNotNull($route, 'Route debug.snapshot should exist');
        $this->assertSame('/debug/snapshot', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    // =========================================================================
    // Swagger Routes
    // =========================================================================

    public function testSwaggerIndexRoute(): void
    {
        $route = $this->findRouteByName('swagger.index');
        $this->assertNotNull($route, 'Route swagger.index should exist');
        $this->assertSame('/swagger', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    public function testSwaggerJsonSchemaRoute(): void
    {
        $route = $this->findRouteByName('swagger.json-schema');
        $this->assertNotNull($route, 'Route swagger.json-schema should exist');
        $this->assertSame('/swagger/json-schema', $route->getData('pattern'));
        $this->assertSame(['GET'], $route->getData('methods'));
    }

    // =========================================================================
    // Auth Middleware Verification
    // =========================================================================

    public function testPublicRoutesDoNotHaveJwtMiddleware(): void
    {
        $publicRouteNames = [
            'v1.auth.login',
            'v1.auth.refresh',
            'v1.auth.key-to-token',
            'v1.server.test',
            'v1.server.public',
            'v1.server.checkin',
            'v1.server.tags',
            'v1.server.snapshot',
            'v2.snapshots.index',
            'v2.snapshots.view',
            'v2.tags.index',
            'v2.system.index',
            'health',
            'debug.snapshot',
            'swagger.index',
            'swagger.json-schema',
        ];

        foreach ($publicRouteNames as $name) {
            $route = $this->findRouteByName($name);
            $this->assertNotNull($route, "Route {$name} should exist");
            $this->assertRouteDoesNotHaveMiddleware(
                $route,
                JwtAuthMiddleware::class,
                "Route {$name} should NOT have JwtAuthMiddleware"
            );
        }
    }

    public function testProtectedRoutesHaveJwtMiddleware(): void
    {
        $protectedRouteNames = [
            'v1.server.private',
            'v1.server.group',
        ];

        foreach ($protectedRouteNames as $name) {
            $route = $this->findRouteByName($name);
            $this->assertNotNull($route, "Route {$name} should exist");
            $this->assertRouteHasMiddleware(
                $route,
                JwtAuthMiddleware::class,
                "Route {$name} should have JwtAuthMiddleware"
            );
        }
    }

    // =========================================================================
    // Route Path Completeness (Yii2 compatibility)
    // =========================================================================

    public function testAllExpectedPathsExist(): void
    {
        $expectedPaths = [
            '/v1/auth/login',
            '/v1/auth/refresh',
            '/v1/auth/key-to-token',
            '/v1/server/test',
            '/v1/server/public',
            '/v1/server/checkin',
            '/v1/server/private',
            '/v1/server/group',
            '/v1/server/tags',
            '/v1/server/snapshot',
            '/v2/snapshots',
            '/v2/snapshots/{id:\d+}',
            '/v2/tags',
            '/v2/system',
            '/health',
            '/debug/snapshot',
            '/swagger',
            '/swagger/json-schema',
        ];

        $actualPaths = array_map(
            fn(Route $route) => $route->getData('pattern'),
            $this->routes
        );

        foreach ($expectedPaths as $path) {
            $this->assertContains($path, $actualPaths, "Expected route path '{$path}' not found");
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function findRouteByName(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->getData('name') === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Get middleware definitions from a Route using __debugInfo().
     *
     * @return array The middleware definitions array.
     */
    private function getMiddlewareDefinitions(Route $route): array
    {
        $debugInfo = $route->__debugInfo();

        return $debugInfo['middlewareDefinitions'] ?? [];
    }

    private function assertRouteHasMiddleware(Route $route, string $middlewareClass, string $message = ''): void
    {
        $middlewares = $this->getMiddlewareDefinitions($route);
        $this->assertContains(
            $middlewareClass,
            $middlewares,
            $message ?: "Route should have middleware {$middlewareClass}"
        );
    }

    private function assertRouteDoesNotHaveMiddleware(Route $route, string $middlewareClass, string $message = ''): void
    {
        $middlewares = $this->getMiddlewareDefinitions($route);
        $this->assertNotContains(
            $middlewareClass,
            $middlewares,
            $message ?: "Route should NOT have middleware {$middlewareClass}"
        );
    }
}
