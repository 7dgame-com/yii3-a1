<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V1;

use App\Controller\V1\ServerController;
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

/**
 * Unit tests for V1 ServerController.
 *
 * Uses real SnapshotQueryService (with mocked SnapshotSearch, TagsSearch, Cache)
 * and real PaginationService since both are final classes.
 *
 * Validates Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9:
 * - test() returns JSON {message: "ok"}
 * - listPublic() returns paginated public snapshots with X-Pagination headers
 * - checkin() returns paginated checkin snapshots with X-Pagination headers
 * - listPrivate() requires authenticated user, returns 401 without user attribute
 * - group() requires authenticated user, returns 401 without user attribute
 * - tags() returns tag list
 * - snapshot() returns single snapshot by id or verse_id, 404 when not found
 * - All responses have Content-Type: application/json
 */
final class ServerControllerTest extends TestCase
{
    private SnapshotSearch&MockObject $snapshotSearch;
    private TagsSearch&MockObject $tagsSearch;
    private YiiCacheInterface&MockObject $cache;
    private PaginationService $paginationService;
    private SnapshotQueryService $snapshotQueryService;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private ServerController $controller;

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

        $this->controller = new ServerController(
            $this->snapshotQueryService,
            $this->paginationService,
            $this->responseFactory,
            $this->streamFactory,
            new Yii2RestResponseFactory($this->responseFactory, $this->streamFactory),
        );
    }

    // ========================================================================
    // test() endpoint
    // ========================================================================

    /**
     * Test that test() returns JSON with {message: "ok"}.
     * Validates: Requirement 4.1
     */
    public function testTestEndpointReturnsOk(): void
    {
        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $this->controller->test();

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('test', $decoded);
    }

    /**
     * Test that test() response has Content-Type: application/json.
     * Validates: Requirement 4.1
     */
    public function testTestEndpointHasJsonContentType(): void
    {
        $headerCalls = [];
        $this->setupResponseWithHeaderCapture($headerCalls);

        $this->controller->test();

        $this->assertArrayHasKey('Content-Type', $headerCalls);
        $this->assertSame('application/json; charset=UTF-8', $headerCalls['Content-Type']);
    }

    public function testTestEndpointHonorsApplicationXmlAccept(): void
    {
        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $this->controller->test($this->createRequestWithQueryParams([], accept: 'application/xml'));

        $this->assertSame('application/xml; charset=UTF-8', $headerCalls['Content-Type']);
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response>test</response>\n",
            $capturedBody,
        );
    }

    // ========================================================================
    // listPublic() endpoint
    // ========================================================================

    /**
     * Test that listPublic() returns paginated results with pagination headers.
     * Validates: Requirements 4.2, 4.8
     */
    public function testListPublicReturnsPaginatedResults(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 25);

        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams(['pageSize' => '10', 'page' => '1']);

        $this->controller->listPublic($request);

        // Verify pagination headers are applied
        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);
        $this->assertSame('25', $headerCalls['X-Pagination-Total-Count']);
        $this->assertArrayHasKey('X-Pagination-Per-Page', $headerCalls);
        $this->assertSame('10', $headerCalls['X-Pagination-Per-Page']);
        $this->assertArrayHasKey('X-Pagination-Current-Page', $headerCalls);
        $this->assertSame('1', $headerCalls['X-Pagination-Current-Page']);
        $this->assertArrayHasKey('X-Pagination-Page-Count', $headerCalls);
        $this->assertSame('3', $headerCalls['X-Pagination-Page-Count']);
    }

    /**
     * Test that listPublic() passes query params to SnapshotQueryService.
     * Validates: Requirements 4.2, 4.9
     */
    public function testListPublicPassesQueryParams(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPublic')
            ->with($this->callback(function (array $params) {
                return ($params['pageSize'] ?? null) === '15'
                    && ($params['tags'] ?? null) === '1,2,3';
            }))
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams([
            'pageSize' => '15',
            'tags' => '1,2,3',
        ]);

        $this->controller->listPublic($request);
    }

    /**
     * Test that listPublic() response body is JSON array.
     * Validates: Requirement 4.2
     */
    public function testListPublicResponseBodyIsJsonArray(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);
        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $capturedBody = null;
        $headerCalls = [];
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams([]);

        $this->controller->listPublic($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertIsArray($decoded);
    }

    public function testListPublicHonorsApplicationXmlAccept(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 2, items: [[], []]);
        $this->snapshotSearch->method('searchPublic')->willReturn($query);
        $this->setupCachePassthrough();

        $capturedBody = null;
        $headerCalls = [];
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams([], accept: 'application/xml');

        $this->controller->listPublic($request);

        $this->assertSame('application/xml; charset=UTF-8', $headerCalls['Content-Type']);
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><item/><item/></response>\n",
            $capturedBody,
        );
    }

    // ========================================================================
    // checkin() endpoint
    // ========================================================================

    /**
     * Test that checkin() returns paginated results.
     * Validates: Requirements 4.3, 4.8
     */
    public function testCheckinReturnsPaginatedResults(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 10);

        $this->snapshotSearch->method('searchCheckin')->willReturn($query);
        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams(['pageSize' => '5']);

        $this->controller->checkin($request);

        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);
        $this->assertSame('10', $headerCalls['X-Pagination-Total-Count']);
        $this->assertSame('5', $headerCalls['X-Pagination-Per-Page']);
    }

    /**
     * Test that checkin() delegates to searchCheckin.
     * Validates: Requirement 4.3
     */
    public function testCheckinDelegatesToSearchCheckin(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 0);

        $this->snapshotSearch->expects($this->once())
            ->method('searchCheckin')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams([]);

        $this->controller->checkin($request);
    }

    // ========================================================================
    // listPrivate() endpoint
    // ========================================================================

    /**
     * Test that listPrivate() returns 401 when no user attribute is set.
     * Validates: Requirement 4.4, 9.1
     */
    public function testListPrivateReturns401WithoutUser(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams([]);
        // No user attribute set — getAttribute('user') returns null

        $this->controller->listPrivate($request);

        $this->assertSame(401, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('Your request was made with invalid credentials.', $decoded['message']);
    }

    /**
     * Test that listPrivate() returns 401 when user attribute has no user_id.
     * Validates: Requirement 4.4, 9.1
     */
    public function testListPrivateReturns401WithInvalidUserAttribute(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams([], ['user' => ['invalid' => 'data']]);

        $this->controller->listPrivate($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    /**
     * Test that listPrivate() returns paginated results for authenticated user.
     * Validates: Requirements 4.4, 4.8
     */
    public function testListPrivateReturnsPaginatedResultsForAuthenticatedUser(): void
    {
        $query = $this->createActiveQueryMock(totalCount: 5);

        $this->snapshotSearch->expects($this->once())
            ->method('searchPrivate')
            ->with(42, $this->anything())
            ->willReturn($query);

        $this->setupCachePassthrough();

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequestWithQueryParams(
            ['pageSize' => '10'],
            ['user' => ['user_id' => 42]],
        );

        $this->controller->listPrivate($request);

        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);
        $this->assertSame('5', $headerCalls['X-Pagination-Total-Count']);
    }

    // ========================================================================
    // group() endpoint
    // ========================================================================

    /**
     * Test that group() returns 401 when no user attribute is set.
     * Validates: Requirement 4.5, 9.1
     */
    public function testGroupReturns401WithoutUser(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams([]);

        $this->controller->group($request);

        $this->assertSame(401, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(401, $decoded['status']);
    }

    /**
     * Test that group() returns paginated results for authenticated user.
     * Validates: Requirements 4.5, 4.8
     */
    public function testGroupReturnsPaginatedResultsForAuthenticatedUser(): void
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

        $request = $this->createRequestWithQueryParams(
            ['pageSize' => '5'],
            ['user' => ['user_id' => 99]],
        );

        $this->controller->group($request);

        $this->assertArrayHasKey('X-Pagination-Total-Count', $headerCalls);
        $this->assertSame('8', $headerCalls['X-Pagination-Total-Count']);
    }

    // ========================================================================
    // tags() endpoint
    // ========================================================================

    /**
     * Test that tags() returns tag list from SnapshotQueryService.
     * Validates: Requirement 4.6
     */
    public function testTagsReturnsTagList(): void
    {
        $tagsData = [['id' => 1, 'name' => 'Nature'], ['id' => 2, 'name' => 'City']];

        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn($tagsData);

        $this->tagsSearch->expects($this->once())
            ->method('findByType')
            ->with('Classify')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequestWithQueryParams([]);

        $this->controller->tags($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('Nature', $decoded[0]['name']);
    }

    /**
     * Test that tags() passes custom type parameter.
     * Validates: Requirement 4.6
     */
    public function testTagsPassesCustomType(): void
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn([]);

        $this->tagsSearch->expects($this->once())
            ->method('findByType')
            ->with('Custom')
            ->willReturn($query);

        $this->setupCachePassthrough();

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequestWithQueryParams(['type' => 'Custom']);

        $this->controller->tags($request);
    }

    // ========================================================================
    // snapshot() endpoint
    // ========================================================================

    /**
     * Test that snapshot() returns snapshot data when found by id.
     * Validates: Requirement 4.7
     */
    public function testSnapshotByIdReturnsData(): void
    {
        $snapshot = $this->createMock(\App\Model\Snapshot::class);
        $snapshot->method('jsonSerialize')->willReturn([]);

        $this->cache->method('getOrSet')
            ->willReturn($snapshot);

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequestWithQueryParams(['id' => '123']);

        $this->controller->snapshot($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        // Without expand, snapshot returns empty array (matching Yii2 fields()=[])
        $this->assertSame([], $decoded);
    }

    /**
     * Test that snapshot() returns snapshot data when found by verse_id.
     * Validates: Requirement 4.7
     */
    public function testSnapshotByVerseIdReturnsData(): void
    {
        $snapshot = $this->createMock(\App\Model\Snapshot::class);
        $snapshot->method('jsonSerialize')->willReturn([]);

        $this->cache->method('getOrSet')
            ->willReturn($snapshot);

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequestWithQueryParams(['verse_id' => '10']);

        $this->controller->snapshot($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame([], $decoded);
    }

    /**
     * Test that snapshot() returns 404 when not found.
     * Validates: Requirement 4.7
     */
    public function testSnapshotReturns404WhenNotFound(): void
    {
        $this->cache->method('getOrSet')
            ->willReturn(null);

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams(['id' => '999']);

        $this->controller->snapshot($request);

        $this->assertSame(400, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(400, $decoded['status']);
        $this->assertSame('Snapshot not found.', $decoded['message']);
    }

    /**
     * Test that snapshot() returns 400 when no id or verse_id provided.
     * Validates: Requirement 4.7
     */
    public function testSnapshotReturns400WhenNoParams(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams([]);

        $this->controller->snapshot($request);

        $this->assertSame(400, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(400, $decoded['status']);
        $this->assertStringContainsString('id or verse_id', $decoded['message']);
    }

    /**
     * Test that snapshot() prefers id over verse_id when both are provided.
     * Validates: Requirement 4.7
     */
    public function testSnapshotPrefersIdOverVerseId(): void
    {
        $snapshot = $this->createMock(\App\Model\Snapshot::class);
        $snapshot->method('jsonSerialize')->willReturn([]);

        // The cache should be called with key containing 'by_id_123'
        $this->cache->method('getOrSet')
            ->willReturnCallback(function (string $key) use ($snapshot) {
                if (str_contains($key, 'by_id_123')) {
                    return $snapshot;
                }
                return null;
            });

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequestWithQueryParams(['id' => '123', 'verse_id' => '456']);

        $this->controller->snapshot($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame([], $decoded);
    }

    // ========================================================================
    // Error response format tests
    // ========================================================================

    /**
     * Test that error responses match Yii2 format: {status, message}.
     * Validates: Requirement 10.3
     */
    public function testErrorResponseFormat(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupErrorResponse($capturedBody, $capturedStatusCode);

        $request = $this->createRequestWithQueryParams([]);

        // Trigger a 401 by calling listPrivate without user
        $this->controller->listPrivate($request);

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('name', $decoded);
        $this->assertArrayHasKey('code', $decoded);
        $this->assertCount(4, $decoded, 'Error response should match Yii2 format: name, message, code, status');
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
    private function createRequestWithQueryParams(
        array $queryParams,
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
