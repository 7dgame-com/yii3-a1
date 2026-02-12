<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthCheckService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Health Check Controller.
 *
 * Provides a top-level health check endpoint:
 * - GET /health — returns system health status (200 if healthy, 503 if unhealthy)
 *
 * Checks MySQL database and Redis connection status with response times.
 *
 * @see Requirements 6.1, 6.2, 6.3, 6.4
 */
final class HealthController
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * GET /health
     *
     * Calls HealthCheckService::check() and returns the health status
     * with per-service response times.
     * Returns 200 when all services are healthy, 503 when any service is unhealthy.
     *
     * @see Requirements 6.1, 6.2, 6.3, 6.4
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->healthCheckService->check();

        $statusCode = $result->isHealthy() ? 200 : 503;

        return $this->createJsonResponse($result->toArray(), $statusCode);
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data       The data to encode as JSON.
     * @param int   $statusCode HTTP status code.
     */
    private function createJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
