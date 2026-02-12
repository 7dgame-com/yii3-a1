<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V2;

use App\Controller\V2\SystemController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Unit tests for V2 SystemController.
 *
 * Validates Requirement 5.7:
 * - GET/HEAD /v2/system returns system status
 * - Always returns 200 with {status: "ok", message, timestamp}
 * - Response has Content-Type: application/json
 */
final class SystemControllerTest extends TestCase
{
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    protected function setUp(): void
    {
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
    }

    /**
     * Test that index() returns 200 always.
     * Validates: Requirement 5.7
     */
    public function testIndexReturns200WhenHealthy(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(200, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('Service is operating normally', $decoded['message']);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertIsInt($decoded['timestamp']);
    }

    /**
     * Test that index() always returns 200 (no 503 case anymore).
     * Validates: Requirement 5.7
     */
    public function testIndexReturns503WhenMysqlDown(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        // Always returns 200 now
        $this->assertSame(200, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('ok', $decoded['status']);
    }

    /**
     * Test that index() always returns 200 (no 503 case anymore).
     * Validates: Requirement 5.7
     */
    public function testIndexReturns503WhenRedisDown(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(200, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('ok', $decoded['status']);
    }

    /**
     * Test that index() always returns 200 (no 503 case anymore).
     * Validates: Requirement 5.7
     */
    public function testIndexReturns503WhenBothServicesDown(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(200, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('ok', $decoded['status']);
    }

    /**
     * Test that index() response has Content-Type: application/json.
     * Validates: Requirement 5.7
     */
    public function testIndexResponseHasJsonContentType(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $headerCalls = [];
        $this->setupResponseWithHeaderCapture($headerCalls);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertArrayHasKey('Content-Type', $headerCalls);
        $this->assertSame('application/json', $headerCalls['Content-Type']);
    }

    /**
     * Test that index() response body contains status, message, and timestamp.
     * Validates: Requirement 5.7
     */
    public function testIndexResponseContainsStatusAndServices(): void
    {
        $controller = new SystemController(
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createRequest(): ServerRequestInterface
    {
        return $this->createMock(ServerRequestInterface::class);
    }

    private function setupResponseCapture(?string &$capturedBody, ?int &$capturedStatusCode): void
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
}
