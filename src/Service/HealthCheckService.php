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
    private const LOGIN_CODE_REDIS_MEMORY_ALERT_PERCENT = 80;

    public function __construct(
        private ConnectionInterface $db,
        private RedisClient $redis,
        private readonly ?LoginCodeReadiness $loginCodeReadiness = null,
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
        $loginCodeReadiness = $this->checkLoginCodeReadiness();

        $loginCodeCapacity = $loginCodeReadiness?->required && $loginCodeReadiness->ready
            ? $this->checkLoginCodeRedisCapacity()
            : null;

        $allHealthy = $mysqlResult['status'] === 'up'
            && $redisResult['status'] === 'up'
            && ($loginCodeReadiness === null || !$loginCodeReadiness->required || $loginCodeReadiness->ready)
            && ($loginCodeCapacity === null || $loginCodeCapacity['status'] === 'up');

        $services = [
            'database' => $mysqlResult,
            'redis' => $redisResult,
        ];

        // Database-mode rollout must preserve the existing health contract and
        // never call Redis TIME through this path. Redis modes expose only a
        // fixed, redacted status/reason vocabulary.
        if ($loginCodeReadiness?->required) {
            $services['login_code'] = $loginCodeCapacity !== null && $loginCodeCapacity['status'] !== 'up'
                ? $loginCodeCapacity
                : array_merge($loginCodeReadiness->toHealthDetail(), $loginCodeCapacity ?? []);
        }

        return new HealthResult(
            status: $allHealthy ? 'healthy' : 'unhealthy',
            services: $services,
            timestamp: date('c'),
        );
    }

    private function checkLoginCodeReadiness(): ?LoginCodeReadinessResult
    {
        if ($this->loginCodeReadiness === null) {
            return null;
        }

        try {
            return $this->loginCodeReadiness->check();
        } catch (\Throwable) {
            // A readiness implementation must never turn /health into a 500
            // or disclose a Redis/DB exception. The fixed reason is safe for
            // the public health response and makes the endpoint return 503.
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::READINESS_UNAVAILABLE);
        }
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

    /** @return array<string, int|string|bool> */
    private function checkLoginCodeRedisCapacity(): array
    {
        try {
            $memory = $this->parseRedisInfo($this->redis->info('memory'));
            $stats = $this->parseRedisInfo($this->redis->info('stats'));
            $usedMemory = $this->parseNonNegativeRedisInteger($memory['used_memory'] ?? null);
            $maxMemory = $this->parseNonNegativeRedisInteger($memory['maxmemory'] ?? null);
            $evictedKeys = $this->parseNonNegativeRedisInteger($stats['evicted_keys'] ?? null);
            $policy = strtolower(trim((string) ($memory['maxmemory_policy'] ?? '')));

            if ($usedMemory === null || $maxMemory === null || $maxMemory === 0 || $evictedKeys === null) {
                return $this->loginCodeCapacityFailure('redis_memory_configuration');
            }
            if ($policy !== 'noeviction') {
                return $this->loginCodeCapacityFailure('redis_eviction_policy');
            }
            if (($usedMemory / $maxMemory) * 100 >= self::LOGIN_CODE_REDIS_MEMORY_ALERT_PERCENT) {
                return $this->loginCodeCapacityFailure('redis_memory_threshold');
            }
            if ($evictedKeys !== 0) {
                return $this->loginCodeCapacityFailure('redis_evictions_detected');
            }

            return [
                'status' => 'up',
                'required' => true,
                'memory_alert_threshold_percent' => self::LOGIN_CODE_REDIS_MEMORY_ALERT_PERCENT,
                'memory_usage' => 'below_threshold',
                'maxmemory_policy' => 'noeviction',
                'eviction_alert' => 'configured_zero',
            ];
        } catch (\Throwable) {
            return $this->loginCodeCapacityFailure('readiness_unavailable');
        }
    }

    /** @return array<string, string> */
    private function parseRedisInfo(mixed $value): array
    {
        $result = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && (is_scalar($item) || $item === null)) {
                    $result[strtolower($key)] = trim((string) $item);
                } elseif (is_array($item)) {
                    $result = array_merge($result, $this->parseRedisInfo($item));
                }
            }
            return $result;
        }

        foreach (preg_split('/\r?\n/', (string) $value) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }
            [$key, $item] = explode(':', $line, 2);
            $result[strtolower(trim($key))] = trim($item);
        }
        return $result;
    }

    private function parseNonNegativeRedisInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }

    /** @return array{status: string, required: bool, reason: string} */
    private function loginCodeCapacityFailure(string $reason): array
    {
        return [
            'status' => 'down',
            'required' => true,
            'reason' => $reason,
        ];
    }
}
