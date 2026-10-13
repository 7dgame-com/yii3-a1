<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use RuntimeException;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Authentication business logic orchestration service.
 *
 * Coordinates JwtService and RefreshTokenService to provide:
 * - login: validate credentials and generate token pair
 * - loginV2: validate credentials and return the strict V2 client envelope
 * - refresh: rotate refresh tokens and generate new token pair
 * - refreshTokenOnly: strictly rotate a refresh token without login-code fallback
 * - loginCodeOnly: strictly exchange a login code for tokens and white-label URL
 * - keyToToken: authenticate via a short-lived login code and generate token pair
 * - keyToTokenWithUrl: generate a token pair plus its trusted frontend URL
 *
 * @see Requirements 3.1, 3.2, 3.5
 */
final class AuthService
{
    /**
     * A valid bcrypt hash used when a V2 login username does not exist.
     *
     * Verifying against this hash keeps unknown-user failures on the same
     * expensive password-check path as wrong-password failures, reducing the
     * usefulness of response timing for username enumeration.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$wuWc8E1WPthIVMXyqSLpseY0XMvljoxGO7z56gLUCFHreiqjrS41q';

    public function __construct(
        private JwtService $jwtService,
        private RefreshTokenService $refreshTokenService,
        private LoginCodeStore $loginCodeStore,
        private ?UnityDevLoginFixture $unityDevLoginFixture = null,
        private ?DeviceSnService $deviceSnService = null,
        private ?IdentityAuthorityClient $identityAuthority = null,
        private ?LegacyRefreshReplayService $legacyRefreshReplay = null,
    ) {
    }

    /**
     * Authenticate a user by username and password.
     *
     * Finds the user by username, validates the password, and generates
     * an accessToken (JWT) and refreshToken pair.
     *
     * @param string $username The username to authenticate.
     * @param string $password The password to validate.
     * @return array{accessToken: string, refreshToken: string} The generated token pair.
     * @throws RuntimeException If the username is not found or the password is invalid (401).
     *
     * @see Requirement 3.1
     */
    public function login(string $username, string $password, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return $this->identityResponse($this->identityAuthority->login($username, $password, $requestContext), 'login');
        }
        $user = (new ActiveQuery(User::class))
            ->where(['username' => $username])
            ->one();

        if ($user === null) {
            throw new RuntimeException('no user', 400);
        }

        if (!$user->validatePassword($password)) {
            throw new RuntimeException('wrong password', 400);
        }

        $tokenData = $this->generateTokenPair((int) $user->get('id'), [], $user);

        return [
            'success' => true,
            'message' => 'login',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $tokenData,
            'user' => $user,
        ];
    }

    /**
     * Authenticate by username and password using the strict V2 response.
     *
     * Credential validation intentionally matches login(), while credential
     * failures use one generic response to avoid revealing whether a username
     * exists. The success envelope is identical to refreshTokenOnly().
     *
     * @return array{success: true, message: string, nickname: mixed, token: array, user: array}
     */
    public function loginV2(string $username, string $password, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return $this->identityResponse($this->identityAuthority->login($username, $password, $requestContext), 'keyToTokenWithUrl');
        }
        $user = (new ActiveQuery(User::class))
            ->where(['username' => $username])
            ->one();

        $passwordIsValid = $user === null
            ? password_verify($password, self::DUMMY_PASSWORD_HASH)
            : $user->validatePassword($password);

        if ($user === null || !$passwordIsValid) {
            throw new RuntimeException('Invalid username or password.', 401);
        }

        return $this->createStrictClientResponse($user);
    }

    /**
     * Refresh an authentication token pair.
     *
     * Validates the old refresh token, deletes it, and generates a new
     * accessToken + refreshToken pair for the same user.
     *
     * @param string $refreshToken The refresh token to validate and rotate.
     * @return array{accessToken: string, refreshToken: string} The new token pair.
     * @throws RuntimeException If the refresh token is invalid or expired (401).
     *
     * @see Requirement 3.2
     */
    public function refresh(string $refreshToken, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            $issued = $this->identityAuthority->refresh($refreshToken, true, $requestContext, '/v1/auth/refresh');
            if (isset($issued['legacyResponseBody'])) return $this->replayEnvelope($issued['legacyResponseBody'], false);
            $result = $this->identityResponse($issued, 'refresh');
            unset($result['user']);
            return $result;
        }
        $normalizedToken = $this->normalizeRefreshTokenInput($refreshToken);
        if ($this->legacyRefreshReplay?->enabled()) {
            $replayed = $this->rotateWithReplay($normalizedToken, $requestContext);
            if ($replayed !== null) return $this->replayEnvelope($replayed, false);
        }
        $session = $this->legacyRefreshReplay?->enabled() ? null : $this->refreshTokenService->consumeSession($normalizedToken);
        $userId = $session['user_id'] ?? null;

        if ($userId === null && !str_starts_with($normalizedToken, 'dsn_')) {
            $loginCode = $this->loginCodeStore->resolve($normalizedToken);
            if ($loginCode->isInfrastructureFailure()) {
                throw new RuntimeException('Login code storage is unavailable.', 503);
            }

            if ($loginCode->status === LoginCodeLookupStatus::HIT) {
                $userId = $loginCode->userId;
            }
        }

        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Refresh token is invalid.', 401);
        }

        $context = DeviceSnContext::fromArray($session ?? []);
        $this->authorizeDeviceSession($userId, $context);
        return $this->createRefreshResponse($userId, $context);
    }

    /**
     * Strictly rotate a real refresh token.
     *
     * Unlike the legacy refresh() entrypoint, this method deliberately does
     * not unwrap QR transport formats and never falls back to LoginCodeStore.
     */
    public function refreshTokenOnly(string $refreshToken, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            $issued = $this->identityAuthority->refresh($refreshToken, false, $requestContext, '/v2/auth/refresh-token');
            if (isset($issued['legacyResponseBody'])) return $this->replayEnvelope($issued['legacyResponseBody'], true);
            return $this->identityResponse($issued, 'keyToTokenWithUrl');
        }
        $normalizedToken = trim($refreshToken);
        if ($this->legacyRefreshReplay?->enabled()) {
            $replayed = $this->rotateWithReplay($normalizedToken, $requestContext);
            if ($replayed === null) throw new RuntimeException('Refresh token is invalid.', 401);
            return $this->replayEnvelope($replayed, true);
        }
        $session = $this->refreshTokenService->consumeSession($normalizedToken);
        $userId = $session['user_id'] ?? null;

        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Refresh token is invalid.', 401);
        }

        $context = DeviceSnContext::fromArray($session ?? []);
        $this->authorizeDeviceSession($userId, $context);
        return $this->createStrictRefreshResponse($userId, $context);
    }

    /** SN chooses identity; the existing y1 issuer still owns both tokens. */
    public function loginDeviceSn(string $sn, string $uuid, bool $activate, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            if ($this->deviceSnService === null) throw new RuntimeException('Device SN authentication is unavailable.', 503);
            $identity = $this->deviceSnService->authenticate($sn, $uuid, $activate);
            $result = $this->identityAuthority->issueDevice($identity['user_id'], $identity['device_sn_id'], $requestContext);
            try {
                $this->deviceSnService->recordLogin($identity['device_sn_id']);
            } catch (\Throwable $error) {
                $this->identityAuthority->logout($result['authTokens']['refreshToken']);
                throw $error;
            }
            return $this->identityResponse($result, 'login');
        }
        if ($this->deviceSnService === null) {
            throw new RuntimeException('Device SN authentication is unavailable.', 503);
        }
        $identity = $this->deviceSnService->authenticate($sn, $uuid, $activate);
        $context = ['auth_method' => 'device_sn', 'device_sn_id' => $identity['device_sn_id']];
        $this->authorizeDeviceSession($identity['user_id'], $context);
        $tokens = $this->generateTokenPair($identity['user_id'], $context);
        try {
            $this->deviceSnService->recordLogin($identity['device_sn_id']);
        } catch (\Throwable $exception) {
            $this->refreshTokenService->delete($tokens['refreshToken']);
            throw $exception;
        }
        return [
            'success' => true,
            'message' => 'login',
            'nickname' => $identity['nickname'],
            'token' => $tokens,
            'user' => [
                'id' => $identity['user_id'], 'username' => $identity['username'],
                'nickname' => $identity['nickname'], 'fixture' => false,
            ],
        ];
    }

    public function logout(string $refreshToken): array
    {
        if ($this->identityAuthority?->enabled()) return $this->identityAuthority->logout($refreshToken);
        $this->refreshTokenService->delete(trim($refreshToken));
        return ['success' => true, 'message' => 'logout'];
    }

    private function authorizeDeviceSession(int $userId, array $context): void
    {
        if ($context === []) {
            return;
        }
        if ($this->deviceSnService === null) {
            throw new RuntimeException('Device SN authentication is unavailable.', 503);
        }
        $this->deviceSnService->authorizeSession($context['device_sn_id'], $userId, true);
    }

    /**
     * Strictly exchange a login code for a token pair and white-label URL.
     *
     * QR transport wrappers such as web_<code> remain accepted because they
     * represent the same login-code credential type. Refresh tokens are never
     * inspected or consumed by this path.
     */
    public function loginCodeOnly(string $loginCode, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return $this->identityResponse($this->identityAuthority->loginCode($loginCode, false, $requestContext), 'keyToTokenWithUrl', true, true);
        }
        return $this->exchangeLoginCodeWithUrl(
            $loginCode,
            'keyToTokenWithUrl',
            'Login code is invalid or expired.',
            401,
            false,
            true,
        );
    }

    /**
     * Authenticate via a reusable QR login code.
     *
     * Resolves the configured database/Redis login-code source, retrieves the
     * associated user, and generates an accessToken + refreshToken pair.
     *
     * @param string $key The linked key to authenticate with.
     * @return array{accessToken: string, refreshToken: string} The generated token pair.
     * @throws RuntimeException If the key is not found or has no associated user (401).
     *
     * @see Requirement 3.5
     */
    public function keyToToken(string $key, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return $this->identityResponse($this->identityAuthority->loginCode($key, false, $requestContext), 'keyToToken');
        }
        $loginCode = $this->loginCodeStore->resolveForKeyToToken($this->normalizeRefreshTokenInput($key));

        if ($loginCode->isInfrastructureFailure()) {
            throw new RuntimeException('Login code storage is unavailable.', 503);
        }

        if ($loginCode->status !== LoginCodeLookupStatus::HIT || $loginCode->userId === null) {
            throw new RuntimeException('Linked key is invalid.', 400);
        }

        $userId = $loginCode->userId;

        if ($userId <= 0) {
            throw new RuntimeException('User is not found.', 400);
        }

        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        $tokenData = $this->generateTokenPair($userId, [], $user);

        return [
            'success' => true,
            'message' => 'keyToToken',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $tokenData,
            'user' => $user,
        ];
    }

    /**
     * Authenticate via a reusable QR login code and return the token payload
     * together with its trusted white-label frontend URL.
     *
     * URL metadata is optional: active compatible codes without a frontend
     * domain still receive tokens, while the response simply omits `url`.
     *
     * @return array{
     *     success: true,
     *     message: string,
     *     nickname: mixed,
     *     token: array{accessToken: string, expires: string, refreshToken: string},
     *     user: User|array{id: int, username: string, nickname: string, fixture: true},
     *     url?: string
     * }
     */
    public function keyToTokenWithUrl(string $key, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return $this->identityResponse($this->identityAuthority->loginCode($key, false, $requestContext), 'keyToTokenWithUrl', true);
        }
        return $this->exchangeLoginCodeWithUrl(
            $key,
            'keyToTokenWithUrl',
            'Linked key is invalid.',
            400,
            true,
        );
    }

    /**
     * Read white-label metadata for an active QR login code without changing
     * either existing token-exchange response contract.
     *
     * @return array{success: true, message: string, frontendDomain: ?string}
     */
    public function loginCodeContext(string $key, array $requestContext = []): array
    {
        if ($this->identityAuthority?->enabled()) {
            return ['success' => true, 'message' => 'loginCodeContext'] + $this->identityAuthority->loginCode($key, true, $requestContext);
        }
        $normalizedKey = $this->normalizeRefreshTokenInput($key);
        if ($this->unityDevLoginFixture?->matches($normalizedKey) === true) {
            return $this->unityDevLoginFixture->contextResponse();
        }

        $loginCode = $this->loginCodeStore->resolveForContext(
            $normalizedKey,
        );

        if ($loginCode->isInfrastructureFailure()) {
            throw new RuntimeException('Login code storage is unavailable.', 503);
        }

        if ($loginCode->status !== LoginCodeLookupStatus::HIT || $loginCode->userId === null) {
            throw new RuntimeException('Linked key is invalid.', 400);
        }

        return [
            'success' => true,
            'message' => 'loginCodeContext',
            'frontendDomain' => $loginCode->frontendDomain,
        ];
    }

    private function identityResponse(array $result, string $message, bool $withUrl = false, bool $includeNullUrl = false): array
    {
        $subject = $result['subject'];
        $response = ['success' => true, 'message' => $message, 'nickname' => $subject['nickname'],
            'token' => $result['authTokens'], 'user' => [
                'id' => $subject['id'], 'username' => $subject['username'], 'nickname' => $subject['nickname'], 'fixture' => false,
            ]];
        if ($withUrl && (isset($result['frontendDomain']) || $includeNullUrl)) {
            $response['url'] = isset($result['frontendDomain']) ? $this->buildFrontendUrl($result['frontendDomain']) : null;
        }
        return $response;
    }

    private function normalizeRefreshTokenInput(string $refreshToken): string
    {
        $token = trim($refreshToken);

        if (preg_match('/(?:^|[?&])web_([^&#\s]+)/', $token, $matches) === 1) {
            return $matches[1];
        }

        if (str_starts_with($token, 'web_')) {
            return substr($token, 4);
        }

        return $token;
    }

    /**
     * @return array{success: true, message: string, nickname: mixed, token: array, user: User|array, url?: string|null}
     */
    private function exchangeLoginCodeWithUrl(
        string $rawLoginCode,
        string $message,
        string $invalidMessage,
        int $invalidStatus,
        bool $legacyTelemetrySource = false,
        bool $uniformResponse = false,
    ): array {
        $normalizedCode = $this->normalizeRefreshTokenInput($rawLoginCode);
        if ($this->unityDevLoginFixture?->matches($normalizedCode) === true) {
            $result = $this->unityDevLoginFixture->exchangeResponse();
            $result['message'] = $message;

            return $result;
        }

        $loginCode = $legacyTelemetrySource
            ? $this->loginCodeStore->resolveForKeyToToken($normalizedCode)
            : $this->loginCodeStore->resolveForLoginCode($normalizedCode);

        if ($loginCode->isInfrastructureFailure()) {
            throw new RuntimeException('Login code storage is unavailable.', 503);
        }

        if ($loginCode->status !== LoginCodeLookupStatus::HIT || $loginCode->userId === null) {
            throw new RuntimeException($invalidMessage, $invalidStatus);
        }

        $userId = $loginCode->userId;
        if ($userId <= 0) {
            throw new RuntimeException('User is not found.', 400);
        }

        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        $result = [
            'success' => true,
            'message' => $message,
            'nickname' => $user->get('nickname') ?? '',
            'token' => $this->generateTokenPair($userId, [], $user),
            'user' => $uniformResponse ? $this->createClientUser($user) : $user,
        ];

        if ($loginCode->frontendDomain !== null) {
            $result['url'] = $this->buildFrontendUrl($loginCode->frontendDomain);
        } elseif ($uniformResponse) {
            $result['url'] = null;
        }

        return $result;
    }

    /** @return array{success: true, message: string, nickname: mixed, token: array} */
    private function createRefreshResponse(int $userId, array $context = []): array
    {
        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        return [
            'success' => true,
            'message' => 'refresh',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $this->generateTokenPair($userId, $context, $user),
        ];
    }

    /**
     * Build the strict refresh success envelope. It deliberately has no URL;
     * white-label routing belongs to the login-code exchange response.
     *
     * @return array{success: true, message: string, nickname: mixed, token: array, user: array}
     */
    private function createStrictRefreshResponse(int $userId, array $context = []): array
    {
        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        return $this->createStrictClientResponse($user, $context);
    }

    /** @return array{success: true, message: string, nickname: mixed, token: array, user: array} */
    private function createStrictClientResponse(User $user, array $context = []): array
    {
        return [
            'success' => true,
            'message' => 'keyToTokenWithUrl',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $this->generateTokenPair((int) $user->get('id'), $context, $user),
            'user' => $this->createClientUser($user),
        ];
    }

    /** @return array{id: int, username: string, nickname: string, fixture: false} */
    private function createClientUser(User $user): array
    {
        return [
            'id' => (int) $user->get('id'),
            'username' => (string) ($user->get('username') ?? ''),
            'nickname' => (string) ($user->get('nickname') ?? ''),
            'fixture' => false,
        ];
    }

    /**
     * Generate an accessToken + refreshToken pair for the given user ID.
     *
     * @param int $userId The user ID to generate tokens for.
     * @return array{accessToken: string, refreshToken: string}
     */
    private function generateTokenPair(int $userId, array $context = [], ?User $user = null): array
    {
        $accessToken = $this->jwtService->generateToken($userId, $context);
        $fingerprint = $user === null || !$this->legacyRefreshReplay?->enabled() ? null : LegacyRefreshAccountRepository::fingerprint([
            'password_hash' => $user->get('password_hash'), 'auth_key' => $user->get('auth_key'),
        ]);
        $refreshToken = $this->refreshTokenService->create($userId, $context, $fingerprint);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $expires = $now->modify('+3 hour');

        return [
            'accessToken' => $accessToken,
            'expires' => $expires->format('Y-m-d H:i:s'),
            'refreshToken' => $refreshToken,
        ];
    }

    private function rotateWithReplay(string $token, array $requestContext): ?array
    {
        return $this->legacyRefreshReplay->rotate($token, $requestContext,
            fn (int $userId, array $context) => $this->authorizeDeviceSession($userId, $context),
            function (array $user, array $context, string $successor): array {
                $userId = (int) $user['id'];
                $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
                return ['token' => ['accessToken' => $this->jwtService->generateToken($userId, $context),
                    'expires' => $now->modify('+3 hour')->format('Y-m-d H:i:s'), 'refreshToken' => $successor],
                    'nickname' => (string) ($user['nickname'] ?? ''),
                    'user' => ['id' => $userId, 'username' => (string) ($user['username'] ?? ''),
                        'nickname' => (string) ($user['nickname'] ?? ''), 'fixture' => false]];
            });
    }

    private function replayEnvelope(array $response, bool $strict): array
    {
        $result = ['success' => true, 'message' => $strict ? 'keyToTokenWithUrl' : 'refresh',
            'nickname' => $response['nickname'], 'token' => $response['token']];
        if ($strict) $result['user'] = $response['user'];
        return $result;
    }

    private function buildFrontendUrl(string $frontendDomain): string
    {
        $host = filter_var($frontendDomain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $frontendDomain . ']'
            : $frontendDomain;

        return 'https://' . $host;
    }
}
