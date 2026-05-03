<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V2;

use App\Controller\V2\TagController;
use App\Search\SnapshotSearch;
use App\Search\TagsSearch;
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
 * Unit tests for V2 TagController.
 *
 * Validates Requirement 5.6:
 * - GET /v2/tags returns the list of tags as a JSON array
 * - Response has Content-Type: application/json
 * - Delegates to SnapshotQueryService::findTags()
 */
final class TagControllerTest extends TestCase
{
    private SnapshotSearch&MockObject $snapshotSearch;
    private TagsSearch&MockObject $tagsSearch;
    private YiiCacheInterface&MockObject $cache;
    private SnapshotQueryService $snapshotQueryService;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private TagController $controller;

    protected function setUp(): void
    {
        $this->snapshotSearch = $this->createMock(SnapshotSearch::class);
        $this->tagsSearch = $this->createMock(TagsSearch::class);
        $this->cache = $this->createMock(YiiCacheInterface::class);

        $this->snapshotQueryService = new SnapshotQueryService(
            $this->snapshotSearch,
            $this->tagsSearch,
            new PaginationService(),
            $this->cache,
        );

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $this->controller = new TagController(
            $this->snapshotQueryService,
            $this->responseFactory,
            $this->streamFactory,
            new Yii2RestResponseFactory($this->responseFactory, $this->streamFactory),
        );
    }

    /**
     * Test that index() returns the tag list as a JSON array.
     * Validates: Requirement 5.6
     */
    public function testIndexReturnsTagList(): void
    {
        $tagData = [
            ['id' => 1, 'name' => 'Nature', 'type' => 'Classify'],
            ['id' => 2, 'name' => 'City', 'type' => 'Classify'],
        ];

        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn($tagData);

        $this->tagsSearch->expects($this->once())
            ->method('findByType')
            ->with('Classify')
            ->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequest();

        $this->controller->index($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('Nature', $decoded[0]['name']);
        $this->assertSame('City', $decoded[1]['name']);
    }

    /**
     * Test that index() returns an empty array when no tags exist.
     * Validates: Requirement 5.6
     */
    public function testIndexReturnsEmptyArrayWhenNoTags(): void
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn([]);

        $this->tagsSearch->method('findByType')
            ->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $capturedBody = null;
        $this->setupSuccessResponse($capturedBody);

        $request = $this->createRequest();

        $this->controller->index($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertIsArray($decoded);
        $this->assertCount(0, $decoded);
    }

    /**
     * Test that index() response has Content-Type: application/json.
     * Validates: Requirement 5.6
     */
    public function testIndexResponseHasJsonContentType(): void
    {
        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn([]);

        $this->tagsSearch->method('findByType')->willReturn($query);

        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $headerCalls = [];
        $this->setupResponseWithHeaderCapture($headerCalls);

        $request = $this->createRequest();

        $this->controller->index($request);

        $this->assertArrayHasKey('Content-Type', $headerCalls);
        $this->assertSame('application/json; charset=UTF-8', $headerCalls['Content-Type']);
    }

    public function testIndexHonorsApplicationXmlAccept(): void
    {
        $tagData = [
            ['id' => 1, 'name' => 'Nature', 'key' => 'nature', 'type' => 'Classify'],
        ];

        $query = $this->createMock(ActiveQuery::class);
        $query->method('all')->willReturn($tagData);

        $this->tagsSearch->method('findByType')->willReturn($query);
        $this->cache->method('getOrSet')
            ->willReturnCallback(fn (string $key, callable $cb, int $ttl) => $cb());

        $headerCalls = [];
        $capturedBody = null;
        $this->setupResponseWithHeaderAndBodyCapture($headerCalls, $capturedBody);

        $request = $this->createRequest('application/xml');

        $this->controller->index($request);

        $this->assertSame('application/xml; charset=UTF-8', $headerCalls['Content-Type']);
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><item><id>1</id><name>Nature</name><key>nature</key><type>Classify</type></item></response>\n",
            $capturedBody,
        );
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createRequest(string $accept = '*/*'): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn($accept);

        return $request;
    }

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
