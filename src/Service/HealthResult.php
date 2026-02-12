<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Value object representing the result of a health check.
 *
 * Contains the overall system status and detailed per-service health information
 * including connection status and response time in milliseconds.
 *
 * @see Requirements 6.1, 6.2, 6.3, 6.4
 */
final class HealthResult
{
    /**
     * @param string $status   Overall status: 'healthy' when all services are up, 'unhealthy' when any service is down.
     * @param array  $services Per-service details, keyed by service name (e.g. 'mysql', 'redis').
     *                         Each entry contains:
     *                         - 'status' (string): 'up' or 'down'
     *                         - 'response_time_ms' (float): Response time in milliseconds
     *                         - 'error' (string|null): Error message if the service is down
     */
    public function __construct(
        public readonly string $status,
        public readonly array $services,
        public readonly string $timestamp,
    ) {
    }

    /**
     * Check if the overall status is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{status: string, services: array}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'timestamp' => $this->timestamp,
            'checks' => $this->services,
        ];
    }
}
