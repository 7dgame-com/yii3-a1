<?php

declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for CorsMiddleware.
 *
 * Validates Requirements 2.1, 2.2, 2.3:
 * - All responses include CORS headers (Origin, Methods, Headers)
 * - OPTIONS preflight requests return 200 with CORS headers
 */
final class CorsMiddlewareTest extends TestCase
{
    private CorsMiddleware $middleware;
    private ResponseFactoryInterface $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->middleware = new CorsMiddleware($this->responseFactory);
    }

    /**
     * Test that OPTIONS preflight request returns 200 with CORS headers.
     * Validates: Requirement 2.3
     */
    public function testOptionsPreflightReturns200WithCorsHeaders(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('OPTIONS');

        $response = $this->createResponseMockWithHeaders();

        $this->responseFactory
            ->expects($this->once())
            ->method('createResponse')
            ->with(200)
            ->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that GET request passes through handler and gets CORS headers.
     * Validates: Requirements 2.1, 2.2
     */
    public function testGetRequestPassesThroughHandlerWithCorsHeaders(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');

        $response = $this->createResponseMockWithHeaders();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that POST request passes through handler and gets CORS headers.
     * Validates: Requirements 2.1, 2.2
     */
    public function testPostRequestPassesThroughHandlerWithCorsHeaders(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        $response = $this->createResponseMockWithHeaders();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $result = $this->middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    /**
     * Test that OPTIONS request does NOT call the handler (short-circuits).
     * Validates: Requirement 2.3
     */
    public function testOptionsRequestDoesNotCallHandler(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('OPTIONS');

        $response = $this->createResponseMockWithHeaders();

        $this->responseFactory
            ->method('createResponse')
            ->with(200)
            ->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->middleware->process($request, $handler);
    }

    /**
     * Test CORS headers contain correct values using a real-like response chain.
     * Validates: Requirements 2.1, 2.2
     */
    public function testCorsHeadersHaveCorrectValues(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');

        // Use a tracking approach to verify the correct header values
        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headerCalls);
        $this->assertSame('*', $headerCalls['Access-Control-Allow-Origin']);

        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headerCalls);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS', $headerCalls['Access-Control-Allow-Methods']);

        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headerCalls);
        $this->assertSame('*', $headerCalls['Access-Control-Allow-Headers']);
    }

    /**
     * Test CORS headers on OPTIONS preflight contain correct values.
     * Validates: Requirements 2.1, 2.2, 2.3
     */
    public function testOptionsPreflightCorsHeaderValues(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('OPTIONS');

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        $this->responseFactory
            ->method('createResponse')
            ->with(200)
            ->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headerCalls);
        $this->assertSame('*', $headerCalls['Access-Control-Allow-Origin']);

        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headerCalls);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS', $headerCalls['Access-Control-Allow-Methods']);

        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headerCalls);
        $this->assertSame('*', $headerCalls['Access-Control-Allow-Headers']);
    }

    /**
     * Test various HTTP methods all get CORS headers.
     * Validates: Requirements 2.1, 2.2
     *
     * @dataProvider httpMethodProvider
     */
    public function testAllHttpMethodsGetCorsHeaders(string $method): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);

        $headerCalls = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headerCalls) {
                $headerCalls[$name] = $value;
                return $response;
            });

        if ($method === 'OPTIONS') {
            $this->responseFactory
                ->method('createResponse')
                ->with(200)
                ->willReturn($response);
        }

        $handler = $this->createMock(RequestHandlerInterface::class);
        if ($method !== 'OPTIONS') {
            $handler->method('handle')->willReturn($response);
        }

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headerCalls);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headerCalls);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headerCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function httpMethodProvider(): array
    {
        return [
            'GET' => ['GET'],
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
            'PATCH' => ['PATCH'],
            'OPTIONS' => ['OPTIONS'],
        ];
    }

    /**
     * Creates a mock ResponseInterface that returns itself on withHeader calls.
     */
    private function createResponseMockWithHeaders(): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        return $response;
    }
}
