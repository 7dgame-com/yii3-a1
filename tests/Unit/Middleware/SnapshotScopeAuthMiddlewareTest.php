<?php

declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SnapshotScopeAuthMiddleware;
use App\Service\JwtService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for conditional auth on /v2/snapshots scopes.
 */
final class SnapshotScopeAuthMiddlewareTest extends TestCase
{
    private JwtService $jwtService;
    private ContainerInterface $container;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private SnapshotScopeAuthMiddleware $middleware;
    private string $keyFilePath;

    protected function setUp(): void
    {
        $this->keyFilePath = tempnam(sys_get_temp_dir(), 'snapshot_scope_auth_');
        file_put_contents($this->keyFilePath, 'test-secret-key-for-snapshot-scope-middleware');

        $this->jwtService = new JwtService($this->keyFilePath);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->middleware = new SnapshotScopeAuthMiddleware($this->container);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }
    }

    public function testPublicScopePassesWithoutAuthorization(): void
    {
        $request = $this->createRequest(['scope' => 'public']);
        $expectedResponse = $this->createMock(ResponseInterface::class);

        $this->container->expects($this->never())->method('get');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->assertSame($expectedResponse, $this->middleware->process($request, $handler));
    }

    public function testDefaultScopePassesWithoutAuthorization(): void
    {
        $request = $this->createRequest([]);
        $expectedResponse = $this->createMock(ResponseInterface::class);

        $this->container->expects($this->never())->method('get');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $this->assertSame($expectedResponse, $this->middleware->process($request, $handler));
    }

    public function testPrivateScopeRequiresAuthorization(): void
    {
        $request = $this->createRequest(['scope' => 'private'], '');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectJwtMiddlewareResolved();

        $response = $this->setupUnauthorizedResponse();

        $this->assertSame($response, $this->middleware->process($request, $handler));
    }

    public function testGroupScopeWithValidTokenInjectsUser(): void
    {
        $token = $this->jwtService->generateToken(42);
        $enrichedRequest = $this->createMock(ServerRequestInterface::class);

        $request = $this->createRequest(['scope' => 'group'], 'Bearer ' . $token);
        $request->expects($this->once())
            ->method('withAttribute')
            ->with('user', ['user_id' => 42])
            ->willReturn($enrichedRequest);

        $this->expectJwtMiddlewareResolved();

        $expectedResponse = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($enrichedRequest)
            ->willReturn($expectedResponse);

        $this->assertSame($expectedResponse, $this->middleware->process($request, $handler));
    }

    private function createRequest(array $queryParams, string $authorization = ''): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn($authorization);

        return $request;
    }

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

    private function expectJwtMiddlewareResolved(): void
    {
        $jwtAuthMiddleware = new JwtAuthMiddleware(
            $this->jwtService,
            $this->responseFactory,
            $this->streamFactory,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with(JwtAuthMiddleware::class)
            ->willReturn($jwtAuthMiddleware);
    }
}
