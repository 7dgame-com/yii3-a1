<?php

declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\JwtAuthMiddleware;
use App\Service\JwtService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for JwtAuthMiddleware.
 *
 * Uses a real JwtService instance (since it's final and cannot be mocked).
 *
 * Validates Requirements 9.1, 9.2:
 * - Protected endpoints require valid JWT Bearer Token
 * - Missing or invalid tokens return 401 with JSON error body
 * - Valid tokens inject user identity into request attributes
 */
final class JwtAuthMiddlewareTest extends TestCase
{
    private JwtService $jwtService;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private JwtAuthMiddleware $middleware;
    private string $keyFilePath;

    protected function setUp(): void
    {
        $this->keyFilePath = tempnam(sys_get_temp_dir(), 'jwt_mw_test_');
        file_put_contents($this->keyFilePath, 'test-secret-key-for-middleware-testing-minimum-len');

        $this->jwtService = new JwtService($this->keyFilePath);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $this->middleware = new JwtAuthMiddleware(
            $this->jwtService,
            $this->responseFactory,
            $this->streamFactory,
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }
    }

    /**
     * Test that a request with a valid Bearer token passes through to the handler.
     * Validates: Requirement 9.1
     */
    public function testValidTokenPassesThroughToHandler(): void
    {
        $token = $this->jwtService->generateToken(42);

        $enrichedRequest = $this->createMock(ServerRequestInterface::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer ' . $token);
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, $value) use ($enrichedRequest) {
                $this->assertSame('user', $name);
                $this->assertSame(42, $value['user_id']);
                return $enrichedRequest;
            });

        $expectedResponse = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($enrichedRequest)
            ->willReturn($expectedResponse);

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $result);
    }

    /**
     * Test that a request without Authorization header returns 401.
     * Validates: Requirement 9.2
     */
    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->setupUnauthorizedResponse();

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that a request with non-Bearer Authorization header returns 401.
     * Validates: Requirement 9.2
     */
    public function testNonBearerAuthorizationReturns401(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Basic dXNlcjpwYXNz');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->setupUnauthorizedResponse();

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that a request with an invalid JWT token returns 401.
     * Validates: Requirement 9.2
     */
    public function testInvalidTokenReturns401(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer invalid.token.here');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->setupUnauthorizedResponse();

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that a request with "Bearer " but empty token returns 401.
     * Validates: Requirement 9.2
     */
    public function testBearerWithEmptyTokenReturns401(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer ');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->setupUnauthorizedResponse();

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that the 401 response body contains the correct JSON error format.
     * Validates: Requirement 9.2, 10.3
     */
    public function testUnauthorizedResponseBodyFormat(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $handler = $this->createMock(RequestHandlerInterface::class);

        $capturedBody = null;
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
            ->with(401)
            ->willReturn($response);

        $this->middleware->process($request, $handler);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('Your request was made with invalid credentials.', $decoded['message']);
    }

    /**
     * Test that the 401 response has Content-Type: application/json header.
     * Validates: Requirement 10.1
     */
    public function testUnauthorizedResponseHasJsonContentType(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $handler = $this->createMock(RequestHandlerInterface::class);

        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->with(401)
            ->willReturn($response);

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('Content-Type', $headerCalls);
        $this->assertSame('application/json', $headerCalls['Content-Type']);
    }

    /**
     * Test that user data is correctly injected as request attribute.
     * Validates: Requirement 9.1
     */
    public function testUserDataInjectedAsRequestAttribute(): void
    {
        $token = $this->jwtService->generateToken(99);

        $enrichedRequest = $this->createMock(ServerRequestInterface::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer ' . $token);
        $request->expects($this->once())
            ->method('withAttribute')
            ->with('user', ['user_id' => 99])
            ->willReturn($enrichedRequest);

        $expectedResponse = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')
            ->with($enrichedRequest)
            ->willReturn($expectedResponse);

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $result);
    }

    /**
     * Test that handler is not called when Authorization header is missing.
     * Validates: Requirement 9.2
     */
    public function testHandlerNotCalledWhenNoAuthHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->setupUnauthorizedResponse();

        $this->middleware->process($request, $handler);
    }

    /**
     * Helper: Set up mocks for a 401 unauthorized response.
     */
    private function setupUnauthorizedResponse(): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->with(401)
            ->willReturn($response);

        return $response;
    }
}
