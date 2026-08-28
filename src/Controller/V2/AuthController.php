<?php

declare(strict_types=1);

namespace App\Controller\V2;

use App\Service\AuthService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * V2 authentication controller.
 *
 * Provides the credential-specific V2 authentication flows:
 * - POST /v2/auth/login
 * - POST /v2/auth/refresh-token
 * - POST /v2/auth/login-code
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
     * Authenticate with a username and password using the uniform V2 response.
     */
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $username = is_array($body) ? ($body['username'] ?? null) : null;
        $password = is_array($body) ? ($body['password'] ?? null) : null;

        if (!is_string($username) || trim($username) === '') {
            return $this->createErrorResponse(400, 'username is required');
        }
        if (!is_string($password) || trim($password) === '') {
            return $this->createErrorResponse(400, 'password is required');
        }

        try {
            return $this->createJsonResponse(
                $this->authService->loginV2($username, $password),
            );
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * Strictly rotate a genuine refresh token without login-code fallback.
     */
    public function refreshToken(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $refreshToken = is_array($body) ? ($body['refreshToken'] ?? null) : null;

        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            return $this->createErrorResponse(400, 'refreshToken is required');
        }

        try {
            return $this->createJsonResponse(
                $this->authService->refreshTokenOnly($refreshToken),
            );
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * Strictly exchange a login code for tokens and white-label URL metadata.
     */
    public function loginCode(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $loginCode = is_array($body) ? ($body['loginCode'] ?? null) : null;

        if (!is_string($loginCode) || trim($loginCode) === '') {
            return $this->createErrorResponse(400, 'loginCode is required');
        }

        try {
            return $this->createJsonResponse(
                $this->authService->loginCodeOnly($loginCode),
            );
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function createJsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

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
