<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use App\Service\HealthCheckService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Unit tests for HealthController.
 *
 * Validates Requirements 6.1, 6.2, 6.3, 6.4:
 * - GET /health checks MySQL database connection status and response time
 * - GET /health checks Redis connection status and response time
 * - Returns 200 and "healthy" when all services are up
 * - Returns 503 and "unhealthy" when any service is down, with detailed status
 */
final class HealthControllerTest extends TestCase
{
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    protected function setUp(): void
    {
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
    }

    /**
     * Test that index() returns 200 when all services are healthy.
     * Validates: Requirements 6.3
     */
    public function testIndexReturns200WhenHealthy(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: true,
            redisHealthy: true,
        );

        $controller = new HealthController(
            $healthCheckService,
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
        $this->assertSame('healthy', $decoded['status']);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertSame('up', $decoded['checks']['database']['status']);
        $this->assertSame('up', $decoded['checks']['redis']['status']);
    }

    /**
     * Test that index() returns 503 when MySQL is down.
     * Validates: Requirements 6.1, 6.4
     */
    public function testIndexReturns503WhenMysqlDown(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: false,
            redisHealthy: true,
            dbError: 'Connection refused',
        );

        $controller = new HealthController(
            $healthCheckService,
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(503, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('unhealthy', $decoded['status']);
        $this->assertSame('down', $decoded['checks']['database']['status']);
        $this->assertSame('Connection refused', $decoded['checks']['database']['error']);
    }

    /**
     * Test that index() returns 503 when Redis is down.
     * Validates: Requirements 6.2, 6.4
     */
    public function testIndexReturns503WhenRedisDown(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: true,
            redisHealthy: false,
            redisError: 'Connection timed out',
        );

        $controller = new HealthController(
            $healthCheckService,
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(503, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('unhealthy', $decoded['status']);
        $this->assertSame('down', $decoded['checks']['redis']['status']);
        $this->assertSame('Connection timed out', $decoded['checks']['redis']['error']);
    }

    /**
     * Test that index() returns 503 when both services are down.
     * Validates: Requirements 6.4
     */
    public function testIndexReturns503WhenBothServicesDown(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: false,
            redisHealthy: false,
            dbError: 'Connection refused',
            redisError: 'Connection refused',
        );

        $controller = new HealthController(
            $healthCheckService,
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $this->assertSame(503, $capturedStatusCode);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('unhealthy', $decoded['status']);
        $this->assertSame('down', $decoded['checks']['database']['status']);
        $this->assertSame('down', $decoded['checks']['redis']['status']);
    }

    /**
     * Test that index() response has Content-Type: application/json.
     * Validates: Requirements 6.3, 6.4
     */
    public function testIndexResponseHasJsonContentType(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: true,
            redisHealthy: true,
        );

        $controller = new HealthController(
            $healthCheckService,
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
     * Test that index() response body contains status, timestamp, and checks fields.
     * Validates: Requirements 6.1, 6.2, 6.3
     */
    public function testIndexResponseContainsStatusAndServices(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: true,
            redisHealthy: true,
        );

        $controller = new HealthController(
            $healthCheckService,
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
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertArrayHasKey('database', $decoded['checks']);
        $this->assertArrayHasKey('redis', $decoded['checks']);
    }

    /**
     * Test that response includes responseTime for each service.
     * Validates: Requirements 6.1, 6.2
     */
    public function testIndexResponseContainsResponseTimes(): void
    {
        $healthCheckService = $this->createHealthCheckService(
            dbHealthy: true,
            redisHealthy: true,
        );

        $controller = new HealthController(
            $healthCheckService,
            $this->responseFactory,
            $this->streamFactory,
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $this->setupResponseCapture($capturedBody, $capturedStatusCode);

        $request = $this->createRequest();

        $controller->index($request);

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('responseTime', $decoded['checks']['database']);
        $this->assertArrayHasKey('responseTime', $decoded['checks']['redis']);
        $this->assertIsNumeric($decoded['checks']['database']['responseTime']);
        $this->assertIsNumeric($decoded['checks']['redis']['responseTime']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createRequest(): ServerRequestInterface
    {
        return $this->createMock(ServerRequestInterface::class);
    }

    private function createHealthCheckService(
        bool $dbHealthy,
        bool $redisHealthy,
        string $dbError = 'DB error',
        string $redisError = 'Redis error',
    ): HealthCheckService {
        $db = $this->createDbMock($dbHealthy, $dbError);
        $redis = $redisHealthy
            ? $this->createHealthyRedisMock()
            : $this->createUnhealthyRedisMock($redisError);

        return new HealthCheckService($db, $redis);
    }

    private function createDbMock(bool $healthy, string $errorMessage): ConnectionInterface
    {
        $db = $this->createMock(ConnectionInterface::class);

        if ($healthy) {
            $command = $this->createMock(CommandInterface::class);
            $command->method('queryScalar')->willReturn(1);
            $db->method('createCommand')->with('SELECT 1')->willReturn($command);
        } else {
            $db->method('open')
                ->willThrowException(new \RuntimeException($errorMessage));
        }

        return $db;
    }

    private function createHealthyRedisMock(): RedisClient
    {
        return new class extends RedisClient {
            public function __construct()
            {
            }

            public function __call($commandID, $arguments)
            {
                if (strtoupper($commandID) === 'PING') {
                    return new \Predis\Response\Status('PONG');
                }
                return null;
            }
        };
    }

    private function createUnhealthyRedisMock(string $errorMessage): RedisClient
    {
        return new class($errorMessage) extends RedisClient {
            private string $errorMsg;

            public function __construct(string $errorMessage)
            {
                $this->errorMsg = $errorMessage;
            }

            public function __call($commandID, $arguments)
            {
                if (strtoupper($commandID) === 'PING') {
                    throw new \RuntimeException($this->errorMsg);
                }
                return null;
            }
        };
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
