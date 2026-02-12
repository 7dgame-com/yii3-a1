<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HealthCheckService;
use App\Service\HealthResult;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Unit tests for HealthCheckService and HealthResult.
 *
 * Validates Requirements 6.1, 6.2, 6.3, 6.4:
 * - MySQL connection check with response time
 * - Redis connection check with response time
 * - Returns "healthy" when all services are up
 * - Returns "unhealthy" when any service is down
 */
final class HealthCheckServiceTest extends TestCase
{
    // ---------------------------------------------------------------
    // HealthResult value object tests
    // ---------------------------------------------------------------

    /**
     * Test HealthResult stores all properties correctly.
     */
    public function testHealthResultProperties(): void
    {
        $services = [
            'database' => ['status' => 'up', 'responseTime' => 2],
            'redis' => ['status' => 'up', 'responseTime' => 1],
        ];

        $result = new HealthResult(status: 'healthy', services: $services, timestamp: '2024-01-01T00:00:00+00:00');

        $this->assertSame('healthy', $result->status);
        $this->assertSame($services, $result->services);
        $this->assertSame('2024-01-01T00:00:00+00:00', $result->timestamp);
    }

    /**
     * Test HealthResult isHealthy returns true for healthy status.
     */
    public function testHealthResultIsHealthyTrue(): void
    {
        $result = new HealthResult(status: 'healthy', services: [], timestamp: '2024-01-01T00:00:00+00:00');

        $this->assertTrue($result->isHealthy());
    }

    /**
     * Test HealthResult isHealthy returns false for unhealthy status.
     */
    public function testHealthResultIsHealthyFalse(): void
    {
        $result = new HealthResult(status: 'unhealthy', services: [], timestamp: '2024-01-01T00:00:00+00:00');

        $this->assertFalse($result->isHealthy());
    }

    /**
     * Test HealthResult toArray returns correct structure.
     */
    public function testHealthResultToArray(): void
    {
        $services = [
            'database' => ['status' => 'up', 'responseTime' => 2],
            'redis' => ['status' => 'down', 'responseTime' => 5, 'error' => 'Connection refused'],
        ];

        $result = new HealthResult(status: 'unhealthy', services: $services, timestamp: '2024-01-01T00:00:00+00:00');
        $array = $result->toArray();

        $this->assertSame('unhealthy', $array['status']);
        $this->assertSame('2024-01-01T00:00:00+00:00', $array['timestamp']);
        $this->assertSame($services, $array['checks']);
        $this->assertCount(3, $array);
    }

    // ---------------------------------------------------------------
    // HealthCheckService — all services healthy
    // ---------------------------------------------------------------

    /**
     * Test check() returns healthy when both MySQL and Redis are up.
     * Validates: Requirements 6.1, 6.2, 6.3
     */
    public function testCheckReturnsHealthyWhenAllServicesUp(): void
    {
        $db = $this->createHealthyDbMock();
        $redis = $this->createHealthyRedisMock();

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        $this->assertInstanceOf(HealthResult::class, $result);
        $this->assertSame('healthy', $result->status);
        $this->assertTrue($result->isHealthy());

        // Both services should be "up"
        $this->assertSame('up', $result->services['database']['status']);
        $this->assertSame('up', $result->services['redis']['status']);

        // Response times should be non-negative integers
        $this->assertIsInt($result->services['database']['responseTime']);
        $this->assertGreaterThanOrEqual(0, $result->services['database']['responseTime']);
        $this->assertIsInt($result->services['redis']['responseTime']);
        $this->assertGreaterThanOrEqual(0, $result->services['redis']['responseTime']);

        // No error key for healthy services
        $this->assertArrayNotHasKey('error', $result->services['database']);
        $this->assertArrayNotHasKey('error', $result->services['redis']);
    }

    // ---------------------------------------------------------------
    // HealthCheckService — MySQL down
    // ---------------------------------------------------------------

    /**
     * Test check() returns unhealthy when MySQL is down.
     * Validates: Requirements 6.1, 6.4
     */
    public function testCheckReturnsUnhealthyWhenMysqlDown(): void
    {
        $db = $this->createUnhealthyDbMock('Connection refused');
        $redis = $this->createHealthyRedisMock();

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        $this->assertSame('unhealthy', $result->status);
        $this->assertFalse($result->isHealthy());

        $this->assertSame('down', $result->services['database']['status']);
        $this->assertSame('Connection refused', $result->services['database']['error']);
        $this->assertIsInt($result->services['database']['responseTime']);

        // Redis should still be up
        $this->assertSame('up', $result->services['redis']['status']);
    }

    // ---------------------------------------------------------------
    // HealthCheckService — Redis down
    // ---------------------------------------------------------------

    /**
     * Test check() returns unhealthy when Redis is down.
     * Validates: Requirements 6.2, 6.4
     */
    public function testCheckReturnsUnhealthyWhenRedisDown(): void
    {
        $db = $this->createHealthyDbMock();
        $redis = $this->createUnhealthyRedisMock('Redis timeout');

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        $this->assertSame('unhealthy', $result->status);
        $this->assertFalse($result->isHealthy());

        // MySQL should still be up
        $this->assertSame('up', $result->services['database']['status']);

        $this->assertSame('down', $result->services['redis']['status']);
        $this->assertSame('Redis timeout', $result->services['redis']['error']);
    }

    // ---------------------------------------------------------------
    // HealthCheckService — both services down
    // ---------------------------------------------------------------

    /**
     * Test check() returns unhealthy when both services are down.
     * Validates: Requirements 6.4
     */
    public function testCheckReturnsUnhealthyWhenBothServicesDown(): void
    {
        $db = $this->createUnhealthyDbMock('MySQL gone away');
        $redis = $this->createUnhealthyRedisMock('Redis connection lost');

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        $this->assertSame('unhealthy', $result->status);
        $this->assertFalse($result->isHealthy());

        $this->assertSame('down', $result->services['database']['status']);
        $this->assertSame('MySQL gone away', $result->services['database']['error']);

        $this->assertSame('down', $result->services['redis']['status']);
        $this->assertSame('Redis connection lost', $result->services['redis']['error']);
    }

    // ---------------------------------------------------------------
    // HealthCheckService — response structure
    // ---------------------------------------------------------------

    /**
     * Test check() result always contains database and redis service keys.
     */
    public function testCheckResultContainsBothServiceKeys(): void
    {
        $db = $this->createHealthyDbMock();
        $redis = $this->createHealthyRedisMock();

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        $this->assertArrayHasKey('database', $result->services);
        $this->assertArrayHasKey('redis', $result->services);
    }

    /**
     * Test each service entry contains required keys: status, responseTime.
     */
    public function testServiceEntriesContainRequiredKeys(): void
    {
        $db = $this->createHealthyDbMock();
        $redis = $this->createHealthyRedisMock();

        $service = new HealthCheckService($db, $redis);
        $result = $service->check();

        foreach (['database', 'redis'] as $serviceName) {
            $entry = $result->services[$serviceName];
            $this->assertArrayHasKey('status', $entry, "$serviceName should have 'status' key");
            $this->assertArrayHasKey('responseTime', $entry, "$serviceName should have 'responseTime' key");
        }
    }

    // ---------------------------------------------------------------
    // Helper methods to create mocks
    // ---------------------------------------------------------------

    private function createHealthyDbMock(): ConnectionInterface
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryScalar')->willReturn(1);

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')
            ->with('SELECT 1')
            ->willReturn($command);

        return $db;
    }

    private function createUnhealthyDbMock(string $errorMessage): ConnectionInterface
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('open')
            ->willThrowException(new \RuntimeException($errorMessage));

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
}
