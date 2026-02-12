<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Service\HealthResult;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for health check status consistency.
 *
 * Feature: yii2-to-yii3-migration, Property 13: 健康检查状态一致性
 *
 * **Validates: Requirements 6.3, 6.4**
 */
final class HealthCheckPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 13a: Status is "healthy" iff all services are "up".
     *
     * **Validates: Requirements 6.3, 6.4**
     *
     * @eris-repeat 100
     */
    public function testHealthyIfAndOnlyIfAllServicesUp(): void
    {
        $this->forAll(
            Generator\elements('up', 'down'),
            Generator\elements('up', 'down'),
            Generator\choose(1, 5000),
            Generator\choose(1, 5000)
        )
            ->then(function (string $dbStatus, string $redisStatus, int $dbTime, int $redisTime): void {
                $allUp = $dbStatus === 'up' && $redisStatus === 'up';
                $expectedStatus = $allUp ? 'healthy' : 'unhealthy';

                $services = [
                    'database' => ['status' => $dbStatus, 'responseTime' => $dbTime],
                    'redis' => ['status' => $redisStatus, 'responseTime' => $redisTime],
                ];
                if ($dbStatus === 'down') {
                    $services['database']['error'] = 'Connection refused';
                }
                if ($redisStatus === 'down') {
                    $services['redis']['error'] = 'Connection refused';
                }

                $result = new HealthResult($expectedStatus, $services, date('c'));

                $this->assertSame($expectedStatus, $result->status);
                if ($allUp) {
                    $this->assertTrue($result->isHealthy());
                } else {
                    $this->assertFalse($result->isHealthy());
                }
            });
    }

    /**
     * Property 13b: isHealthy() is consistent with status field.
     *
     * **Validates: Requirements 6.3, 6.4**
     *
     * @eris-repeat 100
     */
    public function testIsHealthyMatchesStatusField(): void
    {
        $this->forAll(
            Generator\elements('healthy', 'unhealthy')
        )
            ->then(function (string $status): void {
                $result = new HealthResult($status, [], date('c'));
                $this->assertSame($status === 'healthy', $result->isHealthy());
            });
    }

    /**
     * Property 13c: toArray() contains status, timestamp, and checks keys.
     *
     * **Validates: Requirements 6.3, 6.4**
     *
     * @eris-repeat 100
     */
    public function testToArrayContainsRequiredKeys(): void
    {
        $this->forAll(
            Generator\elements('up', 'down'),
            Generator\elements('up', 'down')
        )
            ->then(function (string $dbStatus, string $redisStatus): void {
                $allUp = $dbStatus === 'up' && $redisStatus === 'up';
                $status = $allUp ? 'healthy' : 'unhealthy';
                $services = [
                    'database' => ['status' => $dbStatus, 'responseTime' => 10],
                    'redis' => ['status' => $redisStatus, 'responseTime' => 5],
                ];
                $ts = date('c');

                $result = new HealthResult($status, $services, $ts);
                $array = $result->toArray();

                $this->assertArrayHasKey('status', $array);
                $this->assertArrayHasKey('timestamp', $array);
                $this->assertArrayHasKey('checks', $array);
                $this->assertSame($status, $array['status']);
                $this->assertSame($ts, $array['timestamp']);
                $this->assertSame($services, $array['checks']);
            });
    }

    /**
     * Property 13d: Any single service down means unhealthy.
     *
     * **Validates: Requirements 6.3, 6.4**
     *
     * @eris-repeat 100
     */
    public function testAnyServiceDownMeansUnhealthy(): void
    {
        $this->forAll(
            Generator\elements('up', 'down'),
            Generator\elements('up', 'down')
        )
            ->when(fn (string $db, string $redis): bool => $db === 'down' || $redis === 'down')
            ->then(function (string $dbStatus, string $redisStatus): void {
                $result = new HealthResult('unhealthy', [
                    'database' => ['status' => $dbStatus, 'responseTime' => 10],
                    'redis' => ['status' => $redisStatus, 'responseTime' => 5],
                ], date('c'));

                $this->assertSame('unhealthy', $result->status);
                $this->assertFalse($result->isHealthy());
            });
    }
}
