<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V2;

use App\Controller\V2\SnapshotController;
use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
use App\Service\PaginatedResult;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\ActiveRecord\ActiveQuery;
use Yiisoft\Cache\CacheInterface as YiiCacheInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Unit tests for V2 SnapshotController.
 *
 * Validates Requirements 5.1, 5.2, 5.3, 5.4, 5.5:
 * - index() with scope=public returns paginated public snapshots
 * - index() with scope=checkin returns paginated checkin snapshots
 * - index() with scope=group requires auth, returns paginated group snapshots
 * - index() with scope=private requires auth, returns paginated private snapshots
 * - index() defaults to scope=public when no scope specified
 * - index() returns 400 for invalid scope
 * - view() returns single snapshot by route parameter {id}
 * - view() returns 404 when snapshot not found
 * - All responses have Content-Type: application/json
 * - Error responses use Yii2-compatible {status, message} format
 */
final class SnapshotControllerTest extends TestCase
{
    private SnapshotSearch&MockObject $snapshotSearch;
    private TagsSearch&MockObject $tagsSearch;
    private YiiCacheInterface&MockObject $cache;
    private PaginationService $paginationService;
    private SnapshotQueryService $snapshotQueryService;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private CurrentRoute $currentRoute;
    private SnapshotController $controller;

    protected function setUp(): void
    {
        $this->snapshotSearch = $this->createMock(SnapshotSearch::class);
        $this->tagsSearch = $this->createMock(TagsSearch::class);
        $this->cache = $this->createMock(YiiCacheInterface::class);
        $this->paginationService = new PaginationService();

        $this->snapshotQueryService = new SnapshotQueryService(
            $this->snapshotSearch,
            $this->tagsSearch,
            $this->paginationService,
            $this->cache,
        );

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->currentRoute = new CurrentRoute();

        $this->controller = new SnapshotController(
            $this->snapshotQueryService,
            $this->paginationService,
            $this->responseFactory,
            $this->streamFactory,
            $this->currentRoute,
            new Yii2RestResponseFactory($this->responseFactory, $this->streamFactory),
        );
    }

    /**
     * Helper to set arguments on CurrentRoute via reflection (since it's final).
     */
    private function setCurrentRouteArguments(array $arguments): void
    {
        // Create a fresh CurrentRoute for each test that needs arguments
        $this->currentRoute = new CurrentRoute();
        $ref = new \ReflectionProperty(CurrentRoute::class, 'arguments');
        $ref->setValue($this->currentRoute, $arguments);

        // Recreate controller with new currentRoute
        $this->controller = new SnapshotController(
            $this->snapshotQueryService,
            $this->paginationService,
            $this->responseFactory,
            $this->streamFactory,
            $this->currentRoute,
            new Yii2RestResponseFactory($this->responseFactory, $this->streamFactory),
        );
    }

    // ========================================================================
    // index() — scope=public (default)
    // ========================================================================

    /**
     * Test that index() defaults to scope=public when no scope is specified.
     * Validates: Requirement 5.1
     */
    public function testIndexDefaultsToPublicScope(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 10);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPublic')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: []);

        $this->controller->index($request);

        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);
        $this->assertSame('10', $headerCalls['X-Pagination-Total-Count']);
    }

    /**
     * Test that index() with scope=public returns paginated public snapshots.
     * Validates: Requirement 5.1
     */
    public function testIndexWithPublicScopeReturnsPaginatedResults(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 25);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPublic')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: ['scope' => 'public', 'pageSize' => '10', 'page' => '2']);

        $this->controller->index($request);

        $this->assertSame('25', $headerCalls['X-Pagination-Total-Count']);
        $this->assertSame('10', $headerCalls['X-Pagination-Per-Page']);
        $this->assertSame('2', $headerCalls['X-Pagination-Current-Page']);
        $this->assertSame('3', $headerCalls['X-Pagination-Page-Count']);
    }

    /**
     * Test that index() with scope=public passes query params including tags.
     * Validates: Requirements 5.1
     */
    public function testIndexPublicPassesQueryParams(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPublic')
            ->with($this->callback(function (array $params) {
                return ($params['tags'] ?? null) === '1,2,3'
                    && ($params['pageSize'] ?? null) === '15';
            }))
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: [
            'scope' => 'public',
            'pageSize' => '15',
            'tags' => '1,2,3',
        ]);

        $this->controller->index($request);
    }

    // ========================================================================
    // index() — scope=checkin
    // ========================================================================

    /**
     * Test that index() with scope=checkin returns paginated checkin snapshots.
     * Validates: Requirement 5.2
     */
    public function testIndexWithCheckinScopeReturnsPaginatedResults(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 15);

        $this->snapshotSearch->expects($this->once())
            ->method('searchCheckin')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: ['scope' => 'checkin', 'pageSize' => '5']);

        $this->controller->index($request);

        $this->assertSame('15', $headerCalls['X-Pagination-Total-Count']);
        $this->assertSame('5', $headerCalls['X-Pagination-Per-Page']);
    }

    // ========================================================================
    // index() — scope=group (requires auth)
    // ========================================================================

    /**
     * Test that index() with scope=group returns 401 when no user attribute is set.
     * Validates: Requirement 5.3, 9.1
     */
    public function testIndexGroupReturns401WithoutUser(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(queryParams: ['scope' => 'group']);

        $this->controller->index($request);

        $this->assertSame(403, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Login required.', $decoded['message']);
    }

    /**
     * Test that index() with scope=group returns 403 when user attribute has no user_id.
     * Validates: Requirement 5.3, 9.1
     */
    public function testIndexGroupReturns401WithInvalidUserAttribute(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(
            queryParams: ['scope' => 'group'],
            attributes: ['user' => ['invalid' => 'data']],
        );

        $this->controller->index($request);

        $this->assertSame(403, $capturedStatusCode);
    }

    /**
     * Test that index() with scope=group returns paginated results for authenticated user.
     * Validates: Requirement 5.3
     */
    public function testIndexGroupReturnsPaginatedResultsForAuthenticatedUser(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 8);

        $this->snapshotSearch->expects($this->once())
            ->method('searchGroup')
            ->with(99, $this->anything())
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(
            queryParams: ['scope' => 'group', 'pageSize' => '5'],
            attributes: ['user' => ['user_id' => 99]],
        );

        $this->controller->index($request);

        $this->assertSame('8', $headerCalls['X-Pagination-Total-Count']);
        $this->assertSame('5', $headerCalls['X-Pagination-Per-Page']);
    }

    // ========================================================================
    // index() — scope=private (requires auth)
    // ========================================================================

    /**
     * Test that index() with scope=private returns 401 when no user attribute is set.
     * Validates: Requirement 5.4, 9.1
     */
    public function testIndexPrivateReturns401WithoutUser(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(queryParams: ['scope' => 'private']);

        $this->controller->index($request);

        $this->assertSame(403, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Login required.', $decoded['message']);
    }

    /**
     * Test that index() with scope=private returns 403 when user attribute has no user_id.
     * Validates: Requirement 5.4, 9.1
     */
    public function testIndexPrivateReturns401WithInvalidUserAttribute(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(
            queryParams: ['scope' => 'private'],
            attributes: ['user' => ['missing_id' => 42]],
        );

        $this->controller->index($request);

        $this->assertSame(403, $capturedStatusCode);
    }

    /**
     * Test that index() with scope=private returns paginated results for authenticated user.
     * Validates: Requirement 5.4
     */
    public function testIndexPrivateReturnsPaginatedResultsForAuthenticatedUser(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 12);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPrivate')
            ->with(42, $this->anything())
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(
            queryParams: ['scope' => 'private', 'pageSize' => '10'],
            attributes: ['user' => ['user_id' => 42]],
        );

        $this->controller->index($request);

        $this->assertSame('12', $headerCalls['X-Pagination-Total-Count']);
        $this->assertSame('10', $headerCalls['X-Pagination-Per-Page']);
    }

    // ========================================================================
    // index() — invalid scope
    // ========================================================================

    /**
     * Test that index() returns 400 for an invalid scope value.
     */
    public function testIndexReturns400ForInvalidScope(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(queryParams: ['scope' => 'unknown']);

        $this->controller->index($request);

        $this->assertSame(400, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(400, $decoded['status']);
        $this->assertStringContainsString('Invalid scope', $decoded['message']);
    }

    // ========================================================================
    // view() — GET /v2/snapshots/{id}
    // ========================================================================

    /**
     * Test that view() returns snapshot data when found by route parameter id.
     * Validates: Requirement 5.5
     */
    public function testViewReturnsSnapshotById(): void
    {
        $snapshot = $this->createMock(\App\Model\Snapshot::class);
        $snapshot->method('jsonSerialize')->willReturn([]);

        $this->cache->method('getOrSet')
            ->willReturn($snapshot);

        $this->setCurrentRouteArguments(['id' => '123']);

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequest();

        $this->controller->view($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame([], $decoded);
    }

    /**
     * Test that view() returns 404 when snapshot is not found.
     * Validates: Requirement 5.5
     */
    public function testViewReturns404WhenNotFound(): void
    {
        $this->cache->method('getOrSet')
            ->willReturn(null);

        $this->setCurrentRouteArguments(['id' => '999']);

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $this->controller->view($request);

        $this->assertSame(404, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(404, $decoded['status']);
        $this->assertSame('Object not found: 999', $decoded['message']);
    }

    /**
     * Test that view() returns 400 when id attribute is missing.
     */
    public function testViewReturns400WhenIdMissing(): void
    {
        // No arguments set on currentRoute

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $this->controller->view($request);

        $this->assertSame(400, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(400, $decoded['status']);
        $this->assertStringContainsString('id', $decoded['message']);
    }

    // ========================================================================
    // Response format tests
    // ========================================================================

    /**
     * Test that index() response body is a JSON array.
     * Validates: Requirement 5.1
     */
    public function testIndexResponseBodyIsJsonArray(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);
        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $capturedBody = null;
        $headerCalls = [];
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: ['scope' => 'public']);

        $this->controller->index($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test that error responses match Yii2 format: {status, message}.
     * Validates: Requirement 10.3
     */
    public function testErrorResponseFormat(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequest(queryParams: ['scope' => 'private']);

        $this->controller->index($request);

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('name', $decoded);
        $this->assertArrayHasKey('code', $decoded);
        $this->assertCount(4, $decoded, 'Error response should match Yii2 format: name, message, code, status');
    }

    /**
     * Test that index() response has Content-Type: application/json.
     */
    public function testIndexResponseHasJsonContentType(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);
        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $headerCalls = [];
        $this->setupResponseWithHeaderCapture($headerCalls);

        $request = $this->createRequest(queryParams: []);

        $this->controller->index($request);

        $this->assertArrayHasKey('Content-Type', $headerCalls);
        $this->assertSame('application/json; charset=UTF-8', $headerCalls['Content-Type']);
    }

    public function testIndexHonorsApplicationXmlAccept(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 2, items: [[], []]);
        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest(queryParams: [], accept: 'application/xml');

        $this->controller->index($request);

        $this->assertSame('application/xml; charset=UTF-8', $headerCalls['Content-Type']);
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><item/><item/></response>\n",
            $capturedBody,
        );
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Create a mock ActiveQuery that returns the specified total count and empty items.
     */
    private function createActiveQueryMock(int $totalCount, array $items = []): ActiveQuery&MockObject
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('count')->willReturn((string) $totalCount);
        $query->method('offset')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('all')->willReturn($items);

        return $query;
    }

    /**
     * Set up cache mock to pass through (execute callback immediately).
     */
    private function setupCachePassthrough(): void
    {
        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());
    }

    /**
     * Create a mock ServerRequestInterface with query params and optional attributes.
     */
    private function createRequest(
        array $queryParams = [],
        array $attributes = [],
        string $accept = '*/*',
    ): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn($accept);
        $request->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($attributes) {
                return $attributes[$name] ?? null;
            });

        return $request;
    }

    /**
     * Set up mocks for a successful (200) JSON response, capturing the body.
     */
    private function setupSuccessResponse(?string &$capturedBody): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->with(200)
            ->willReturn($response);
    }

    /**
     * Set up mocks for an error response, capturing the body and status code.
     */
    private function setupErrorResponse(?string &$capturedBody, ?int &$capturedStatusCode): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturnCallback(function (int $statusCode) use ($response, &$capturedStatusCode) {
                $capturedStatusCode = $statusCode;
                return $response;
            });
    }

    /**
     * Set up mocks to capture header calls on the response.
     */
    private function setupResponseWithHeaderCapture(array &$headerCalls): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturn($response);
    }

    /**
     * Set up mocks to capture both headers and body.
     */
    private function setupResponseWithHeaderAndBodyCapture(array &$headerCalls, ?string &$capturedBody): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturn($response);
    }
}
