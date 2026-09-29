<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Service\AuthService;
use App\Service\DeviceSnCredential;
use App\Service\DeviceSnRateLimiter;
use App\Service\DeviceSnService;
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
 * - POST /v1/auth/sn-activate: Bind SN + UUID and issue the native token pair
 * - POST /v1/auth/sn-login: Authenticate an existing SN + UUID binding
 * - POST /v1/auth/logout: Revoke the supplied refresh token
 * - POST /v1/auth/key-to-token: Authenticate via a linked key
 * - POST /v1/auth/key-to-token-with-url: Authenticate and return the originating frontend URL
 * - POST /v1/auth/login-code-context: Read optional white-label metadata
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
        private readonly ?DeviceSnRateLimiter $deviceSnRateLimiter = null,
    ) {
    }

    public function snActivate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->deviceLogin($request, true);
    }

    public function snLogin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->deviceLogin($request, false);
    }

    private function deviceLogin(ServerRequestInterface $request, bool $activate): ResponseInterface
    {
        try {
            if ($this->deviceSnRateLimiter === null) {
                throw new RuntimeException('Device SN authentication is unavailable.', 503);
            }
            // Forwarded headers are not trustworthy without an explicit proxy policy.
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $this->deviceSnRateLimiter->consumeIp($ip);
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_string($body['sn'] ?? null) || !is_string($body['uuid'] ?? null)
                || strlen($body['uuid']) > 255) {
                return $this->createErrorResponse(400, 'sn and uuid must be bounded strings.');
            }
            $sn = DeviceSnCredential::normalize($body['sn']);
            $uuid = DeviceSnService::normalizeUuid($body['uuid']);
            $this->deviceSnRateLimiter->consumeCredentials($sn, $uuid);
            return $this->createJsonResponse($this->authService->loginDeviceSn($sn, $uuid, $activate));
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [400, 401, 409, 429, 503], true) ? $e->getCode() : 503;
            return $this->createErrorResponse($status, $status === 503 ? 'Device SN authentication is unavailable.' : $e->getMessage());
        } catch (\Throwable) {
            return $this->createErrorResponse(503, 'Device SN authentication is unavailable.');
        }
    }

    /** Revoke only the supplied y1 refresh credential; SN binding is retained. */
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $token = is_array($body) ? ($body['refreshToken'] ?? null) : null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > 256) {
            return $this->createErrorResponse(400, 'refreshToken is required');
        }
        try {
            return $this->createJsonResponse($this->authService->logout($token));
        } catch (\Throwable) {
            return $this->createErrorResponse(503, 'Token storage is unavailable.');
        }
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

        if (!is_string($refreshToken) || trim($refreshToken) === '' || strlen($refreshToken) > 2048) {
            return $this->createErrorResponse(400, 'refreshToken is required');
        }

        try {
            $result = $this->authService->refresh((string) $refreshToken);

            return $this->createJsonResponse($result);
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [400, 401, 503], true) ? $e->getCode() : 503;
            return $this->createErrorResponse($status, $status === 503 ? 'Token storage is unavailable.' : $e->getMessage());
        } catch (\Throwable) {
            return $this->createErrorResponse(503, 'Token storage is unavailable.');
        }
    }

    /**
     * POST /v1/auth/key-to-token
     *
     * Authenticate via a server-managed short-lived login key.
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
     * POST /v1/auth/key-to-token-with-url
     *
     * Authenticate via a login code and return both the token payload and the
     * trusted frontend URL captured when the code was issued.
     */
    public function keyToTokenWithUrl(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $key = $body['key'] ?? '';

        if (empty($key)) {
            return $this->createErrorResponse(400, 'key is required');
        }

        try {
            return $this->createJsonResponse(
                $this->authService->keyToTokenWithUrl((string) $key),
            );
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($e->getCode() ?: 400, $e->getMessage());
        }
    }

    /**
     * POST /v1/auth/login-code-context
     *
     * Read the originating frontend domain for an active login code. This is
     * metadata only and does not issue tokens or authorize a frontend domain.
     */
    public function loginCodeContext(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $key = $body['key'] ?? '';

        if (empty($key)) {
            return $this->createErrorResponse(400, 'key is required');
        }

        try {
            return $this->createJsonResponse(
                $this->authService->loginCodeContext((string) $key),
            );
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
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
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

        $response = $this->createJsonResponse([
            'name' => $nameMap[$statusCode] ?? 'Error',
            'message' => $message,
            'code' => 0,
            'status' => $statusCode,
            'type' => $typeMap[$statusCode] ?? 'yii\\web\\HttpException',
        ], $statusCode);
        return $statusCode === 429 ? $response->withHeader('Retry-After', '60') : $response;
    }
}
