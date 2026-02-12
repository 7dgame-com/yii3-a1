<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Service\AuthService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * V1 Authentication Controller.
 *
 * Handles user authentication endpoints:
 * - POST /v1/auth/login: Authenticate with username/password
 * - POST /v1/auth/refresh: Refresh token pair using a refresh token
 * - POST /v1/auth/key-to-token: Authenticate via a linked key
 *
 * All endpoints return JSON responses with {accessToken, refreshToken} on success,
 * or {status, message} on error (matching Yii2 error format).
 *
 * @see Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 10.3
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * POST /v1/auth/login
     *
     * Authenticate a user with username and password.
     * Returns {success, message, nickname, token, user} on success — matching Yii2 format.
     *
     * @see Requirement 3.1, 3.3
     */
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';

        if (empty($username)) {
            return $this->createErrorResponse(400, 'username is required');
        }
        if (empty($password)) {
            return $this->createErrorResponse(400, 'password is required');
        }

        try {
            $result = $this->authService->login((string) $username, (string) $password);

            return $this->createJsonResponse($result);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * POST /v1/auth/refresh
     *
     * Refresh the token pair using a valid refresh token.
     * Returns {success, message, nickname, token} on success — matching Yii2 format.
     *
     * @see Requirement 3.2, 3.4
     */
    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $refreshToken = $body['refreshToken'] ?? '';

        if (empty($refreshToken)) {
            return $this->createErrorResponse(400, 'refreshToken is required');
        }

        try {
            $result = $this->authService->refresh((string) $refreshToken);

            return $this->createJsonResponse($result);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * POST /v1/auth/key-to-token
     *
     * Authenticate via a UserLinked key.
     * Returns {success, message, nickname, token, user} on success — matching Yii2 format.
     *
     * @see Requirement 3.5
     */
    public function keyToToken(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $key = $body['key'] ?? '';

        if (empty($key)) {
            return $this->createErrorResponse(400, 'key is required');
        }

        try {
            $result = $this->authService->keyToToken((string) $key);

            return $this->createJsonResponse($result);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * Create a JSON success response with 200 status code.
     *
     * @param array $data The data to encode as JSON.
     */
    private function createJsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * Create a JSON error response matching Yii2 format: {status, message}.
     *
     * @param int $statusCode The HTTP status code.
     * @param string $message The error message.
     *
     * @see Requirement 10.3
     */
    private function createErrorResponse(int $statusCode, string $message): ResponseInterface
    {
        $nameMap = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found'];
        $typeMap = [400 => 'yii\\web\\BadRequestHttpException', 401 => 'yii\\web\\UnauthorizedHttpException', 403 => 'yii\\web\\ForbiddenHttpException', 404 => 'yii\\web\\NotFoundHttpException'];

        return $this->createJsonResponse([
            'name' => $nameMap[$statusCode] ?? 'Error',
            'message' => $message,
            'code' => 0,
            'status' => $statusCode,
            'type' => $typeMap[$statusCode] ?? 'yii\\web\\HttpException',
        ], $statusCode);
    }
}
