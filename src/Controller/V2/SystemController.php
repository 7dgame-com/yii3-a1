<?php

declare(strict_types=1);

namespace App\Controller\V2;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * V2 System Controller.
 *
 * Provides a system health status endpoint:
 * - GET /v2/system  — returns system health status (200 if healthy, 503 if unhealthy)
 * - HEAD /v2/system — same as GET but without response body
 *
 * @see Requirement 5.7
 */
final class SystemController
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * GET/HEAD /v2/system
     *
     * Returns a simple system status response matching Yii2 format.
     * Always returns 200 with {status: "ok", message, timestamp}.
     *
     * @see Requirement 5.7
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->createJsonResponse([
            'status' => 'ok',
            'message' => 'Service is operating normally',
            'timestamp' => time(),
        ]);
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
