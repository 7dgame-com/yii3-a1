<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\UserLinked;
use RuntimeException;
use Yiisoft\ActiveRecord\ActiveQuery;

/**
 * Authentication business logic orchestration service.
 *
 * Coordinates JwtService and RefreshTokenService to provide:
 * - login: validate credentials and generate token pair
 * - refresh: rotate refresh tokens and generate new token pair
 * - keyToToken: authenticate via UserLinked key and generate token pair
 *
 * @see Requirements 3.1, 3.2, 3.5
 */
final class AuthService
{
    public function __construct(
        private JwtService $jwtService,
        private RefreshTokenService $refreshTokenService,
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
    public function login(string $username, string $password): array
    {
        $user = (new ActiveQuery(User::class))
            ->where(['username' => $username])
            ->one();

        if ($user === null) {
            throw new RuntimeException('no user', 400);
        }

        if (!$user->validatePassword($password)) {
            throw new RuntimeException('wrong password', 400);
        }

        $tokenData = $this->generateTokenPair((int) $user->get('id'));

        return [
            'success' => true,
            'message' => 'login',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $tokenData,
            'user' => $user,
        ];
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
    public function refresh(string $refreshToken): array
    {
        $userId = $this->refreshTokenService->validate($refreshToken);

        if ($userId === null) {
            throw new RuntimeException('Refresh token is invalid.', 401);
        }

        // Delete the old refresh token
        $this->refreshTokenService->delete($refreshToken);

        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        $tokenData = $this->generateTokenPair($userId);

        return [
            'success' => true,
            'message' => 'refresh',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $tokenData,
        ];
    }

    /**
     * Authenticate via a UserLinked key.
     *
     * Finds the UserLinked record by key, retrieves the associated user,
     * and generates an accessToken + refreshToken pair.
     *
     * @param string $key The linked key to authenticate with.
     * @return array{accessToken: string, refreshToken: string} The generated token pair.
     * @throws RuntimeException If the key is not found or has no associated user (401).
     *
     * @see Requirement 3.5
     */
    public function keyToToken(string $key): array
    {
        $userLinked = (new ActiveQuery(UserLinked::class))
            ->where(['key' => $key])
            ->one();

        if ($userLinked === null) {
            throw new RuntimeException('Linked key is invalid.', 400);
        }

        $userId = (int) $userLinked->get('user_id');

        if ($userId <= 0) {
            throw new RuntimeException('User is not found.', 400);
        }

        $user = (new ActiveQuery(User::class))
            ->where(['id' => $userId])
            ->one();

        if ($user === null) {
            throw new RuntimeException('User is not found.', 400);
        }

        $tokenData = $this->generateTokenPair($userId);

        return [
            'success' => true,
            'message' => 'keyToToken',
            'nickname' => $user->get('nickname') ?? '',
            'token' => $tokenData,
            'user' => $user,
        ];
    }

    /**
     * Generate an accessToken + refreshToken pair for the given user ID.
     *
     * @param int $userId The user ID to generate tokens for.
     * @return array{accessToken: string, refreshToken: string}
     */
    private function generateTokenPair(int $userId): array
    {
        $accessToken = $this->jwtService->generateToken($userId);
        $refreshToken = $this->refreshTokenService->create($userId);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $expires = $now->modify('+3 hour');

        return [
            'accessToken' => $accessToken,
            'expires' => $expires->format('Y-m-d H:i:s'),
            'refreshToken' => $refreshToken,
        ];
    }
}
