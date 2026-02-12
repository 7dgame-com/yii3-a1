<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\JwtService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JWT Authentication Middleware - Replaces Yii2's HttpBearerAuth behavior.
 *
 * Extracts and validates JWT tokens from the Authorization: Bearer header.
 * Applied at route level for protected endpoints.
 *
 * On success, injects the parsed user identity into the request as a 'user' attribute.
 * On failure (missing/invalid token), returns a 401 JSON response.
 *
 * @see Requirements 9.1, 9.2
 */
final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->createUnauthorizedResponse();
        }

        $userData = $this->jwtService->parseToken($token);

        if ($userData === null) {
            return $this->createUnauthorizedResponse();
        }

        // Inject user identity into request attributes
        $request = $request->withAttribute('user', $userData);

        return $handler->handle($request);
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return string|null The token string, or null if not found/malformed.
     */
    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        if ($token === '' || $token === false) {
            return null;
        }

        return $token;
    }

    /**
     * Create a 401 Unauthorized JSON response.
     *
     * Response format matches Yii2 error format:
     * {"status": 401, "message": "Your request was made with invalid credentials."}
     */
    private function createUnauthorizedResponse(): ResponseInterface
    {
        $body = json_encode([
            'name' => 'Unauthorized',
            'message' => 'Your request was made with invalid credentials.',
            'code' => 0,
            'status' => 401,
            'type' => 'yii\\web\\UnauthorizedHttpException',
        ], JSON_THROW_ON_ERROR);

        $stream = $this->streamFactory->createStream($body);

        return $this->responseFactory->createResponse(401)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
