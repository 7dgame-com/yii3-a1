<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS Middleware - Replaces Yii2's \yii\filters\Cors behavior.
 *
 * Adds Cross-Origin Resource Sharing headers to all responses.
 * For OPTIONS preflight requests, returns an immediate 200 response with CORS headers.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_ORIGIN = '*';
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS';
    private const ALLOWED_HEADERS = '*';
    private const EXPOSE_HEADERS = 'X-Pagination-Total-Count, X-Pagination-Page-Count, X-Pagination-Current-Page, X-Pagination-Per-Page';
    private const MAX_AGE = '86400';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        // For OPTIONS preflight requests, return 200 immediately with CORS headers
        if ($request->getMethod() === 'OPTIONS') {
            $response = $this->responseFactory->createResponse(200);
            return $this->addCorsHeaders($response);
        }

        // For all other requests, pass to the next handler and add CORS headers
        $response = $handler->handle($request);

        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSE_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::MAX_AGE);
    }
}
