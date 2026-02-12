<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Health check service that verifies the status of external dependencies.
 *
 * Checks MySQL database and Redis connections, measuring response times
 * for each service. Returns a HealthResult indicating overall system health.
 *
 * - "healthy" when ALL services are up
 * - "unhealthy" when ANY service is down
 *
 * @see Requirements 6.1, 6.2, 6.3, 6.4
 */
final class HealthCheckService
{
    public function __construct(
        private ConnectionInterface $db,
        private RedisClient $redis,
    ) {
    }

    /**
     * Perform health checks on all external dependencies.
     *
     * Checks MySQL and Redis connections, records response times,
     * and returns a composite health status.
     *
     * @return HealthResult The overall health status with per-service details.
     */
    public function check(): HealthResult
    {
        $mysqlResult = $this->checkMysql();
        $redisResult = $this->checkRedis();

        $allHealthy = $mysqlResult['status'] === 'up' && $redisResult['status'] === 'up';

        return new HealthResult(
            status: $allHealthy ? 'healthy' : 'unhealthy',
            services: [
                'database' => $mysqlResult,
                'redis' => $redisResult,
            ],
            timestamp: date('c'),
        );
    }

    /**
     * Check MySQL connection status and response time.
     *
     * Executes a simple "SELECT 1" query to verify the database is reachable
     * and measures the round-trip time in milliseconds.
     *
     * @return array{status: string, responseTime: int, error: string|null}
     */
    private function checkMysql(): array
    {
        $start = hrtime(true);

        try {
            $this->db->open();
            $this->db->createCommand('SELECT 1')->queryScalar();
            $elapsed = (hrtime(true) - $start) / 1_000_000; // nanoseconds to milliseconds

            return [
                'status' => 'up',
                'responseTime' => (int) round($elapsed),
            ];
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            return [
                'status' => 'down',
                'responseTime' => (int) round($elapsed),
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            return [
                'status' => 'down',
                'responseTime' => (int) round($elapsed),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connection status and response time.
     *
     * Sends a PING command to verify Redis is reachable
     * and measures the round-trip time in milliseconds.
     *
     * @return array{status: string, responseTime: int, error: string|null}
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            $response = $this->redis->ping();
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            // Predis returns a Status object for PING; its payload is "PONG"
            $pong = ($response instanceof \Predis\Response\Status)
                ? $response->getPayload()
                : (string) $response;

            if (strtoupper($pong) !== 'PONG') {
                return [
                    'status' => 'down',
                    'responseTime' => (int) round($elapsed),
                    'error' => 'Unexpected PING response: ' . $pong,
                ];
            }

            return [
                'status' => 'up',
                'responseTime' => (int) round($elapsed),
            ];
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            return [
                'status' => 'down',
                'responseTime' => (int) round($elapsed),
                'error' => $e->getMessage(),
            ];
        }
    }
}
