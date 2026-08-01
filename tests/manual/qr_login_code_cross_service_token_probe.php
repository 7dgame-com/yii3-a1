<?php

declare(strict_types=1);

/**
 * Test-only token companion for tools/identity/test-qr-login-code-cross-service-tokens.mjs.
 *
 * It is a CLI-only probe. It never receives a login code and never prints a
 * token, Redis value, database configuration, or exception detail. The runner
 * may use it only after independently proving that it targets xrugc-test-a1.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

const QR_LOGIN_CODE_TOKEN_HARNESS_TABLE_COMMENT = 'qr_login_code_cross_service_token_harness_v1';

/** @param array<string, mixed> $payload */
function qrLoginCodeTokenProbeEmit(array $payload, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR), PHP_EOL;
    exit($status);
}

function qrLoginCodeTokenProbeAssertIsolatedEnvironment(): void
{
    // A second in-probe guard is needed because A1 images include tests. It
    // prevents direct CLI use against a non-test container even if someone
    // bypasses the Node runner.
    if (
        getenv('DEPLOYMENT_MODE') !== 'test'
        || getenv('REDIS_DB') !== '15'
        || getenv('LOGIN_CODE_READ_MODE') !== 'redis'
        || getenv('LOGIN_CODE_WRITE_MODE') !== 'redis'
        || getenv('LOGIN_CODE_LEGACY_DB_AVAILABLE') !== 'false'
        || getenv('MYSQL_HOST') !== 'db'
    ) {
        throw new RuntimeException('This probe only runs in the isolated Redis-only test environment.');
    }
}

/**
 * @return array{action: string, user_id?: int, username?: string, tokens?: list<array{access_token: string, refresh_token: string}>, refresh_tokens?: list<string>}
 */
function qrLoginCodeTokenProbeInput(): array
{
    $decoded = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid probe input.');
    }

    $action = $decoded['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['setup_user', 'verify_tokens', 'delete_refresh_tokens', 'cleanup_user'], true)) {
        throw new RuntimeException('Invalid probe action.');
    }

    $result = ['action' => $action];
    if (in_array($action, ['setup_user', 'verify_tokens', 'cleanup_user'], true)) {
        $userId = $decoded['user_id'] ?? null;
        if (!is_int($userId) || $userId < 100000000 || $userId > 2000000000) {
            throw new RuntimeException('Invalid probe user.');
        }
        $result['user_id'] = $userId;
    }

    if ($action === 'setup_user') {
        $username = $decoded['username'] ?? null;
        if (!is_string($username) || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $username) !== 1) {
            throw new RuntimeException('Invalid probe username.');
        }
        $result['username'] = $username;
    }

    if ($action === 'verify_tokens') {
        $tokens = $decoded['tokens'] ?? null;
        if (!is_array($tokens) || count($tokens) < 1 || count($tokens) > 4) {
            throw new RuntimeException('Invalid probe tokens.');
        }

        $validated = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                throw new RuntimeException('Invalid probe token.');
            }
            $accessToken = $token['access_token'] ?? null;
            $refreshToken = $token['refresh_token'] ?? null;
            if (
                !is_string($accessToken)
                || strlen($accessToken) < 32
                || strlen($accessToken) > 4096
                || !is_string($refreshToken)
                || preg_match('/^[a-f0-9]{64}$/D', $refreshToken) !== 1
            ) {
                throw new RuntimeException('Invalid probe token.');
            }
            $validated[] = [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
            ];
        }
        $result['tokens'] = $validated;
    }

    if ($action === 'delete_refresh_tokens') {
        $refreshTokens = $decoded['refresh_tokens'] ?? null;
        if (!is_array($refreshTokens) || count($refreshTokens) > 4) {
            throw new RuntimeException('Invalid probe refresh tokens.');
        }
        foreach ($refreshTokens as $refreshToken) {
            if (!is_string($refreshToken) || preg_match('/^[a-f0-9]{64}$/D', $refreshToken) !== 1) {
                throw new RuntimeException('Invalid probe refresh token.');
            }
        }
        $result['refresh_tokens'] = array_values($refreshTokens);
    }

    return $result;
}

function qrLoginCodeTokenProbeDatabase(): PDO
{
    $host = getenv('MYSQL_HOST');
    $database = getenv('MYSQL_DB');
    $username = getenv('MYSQL_USER');
    $password = getenv('MYSQL_PASS');
    if (!is_string($host) || !is_string($database) || !is_string($username) || !is_string($password) || $database === '') {
        throw new RuntimeException('Test database configuration is unavailable.');
    }

    return new PDO(
        'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

function qrLoginCodeTokenProbeHarnessTableComment(PDO $database): ?string
{
    $statement = $database->prepare(
        'SELECT TABLE_COMMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName LIMIT 1'
    );
    $statement->execute([':tableName' => 'user']);
    $comment = $statement->fetchColumn();

    return is_string($comment) ? $comment : null;
}

function qrLoginCodeTokenProbeSetupUser(PDO $database, int $userId, string $username): void
{
    // Never alter an existing user table. The isolated test database starts
    // empty; any pre-existing table belongs to another test and is a hard stop.
    if (qrLoginCodeTokenProbeHarnessTableComment($database) !== null) {
        throw new RuntimeException('The isolated test database is not empty for this harness.');
    }

    $database->exec(
        'CREATE TABLE `user` ('
        . '`id` INT NOT NULL, '
        . '`username` VARCHAR(255) NULL, '
        . '`auth_key` VARCHAR(32) NULL, '
        . "`password_hash` VARCHAR(255) NOT NULL DEFAULT '', "
        . '`password_reset_token` VARCHAR(255) NULL, '
        . '`email` VARCHAR(255) NULL, '
        . '`status` SMALLINT NOT NULL DEFAULT 10, '
        . '`created_at` INT NULL, '
        . '`updated_at` INT NULL, '
        . '`verification_token` VARCHAR(255) NULL, '
        . '`access_token` VARCHAR(255) NULL, '
        . '`wx_openid` VARCHAR(255) NULL, '
        . '`nickname` VARCHAR(255) NULL, '
        . '`email_verified_at` INT NULL, '
        . 'PRIMARY KEY (`id`), '
        . 'UNIQUE KEY `uq_qr_login_code_token_username` (`username`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
        . "COMMENT='" . QR_LOGIN_CODE_TOKEN_HARNESS_TABLE_COMMENT . "'"
    );

    $statement = $database->prepare(
        'INSERT INTO `user` (`id`, `username`, `nickname`, `status`) '
        . 'VALUES (:id, :username, :nickname, 10)'
    );
    $statement->execute([
        ':id' => $userId,
        ':username' => $username,
        ':nickname' => 'qr-token-test',
    ]);
}

function qrLoginCodeTokenProbeCleanupUser(PDO $database, int $userId): void
{
    $comment = qrLoginCodeTokenProbeHarnessTableComment($database);
    if ($comment === null) {
        return;
    }
    if (!hash_equals(QR_LOGIN_CODE_TOKEN_HARNESS_TABLE_COMMENT, $comment)) {
        throw new RuntimeException('The user table was not created by this harness.');
    }

    $statement = $database->prepare('DELETE FROM `user` WHERE `id` = :id');
    $statement->execute([':id' => $userId]);
    $database->exec('DROP TABLE `user`');
}

/** @return App\Service\RefreshTokenService */
function qrLoginCodeTokenProbeRefreshTokenService(): App\Service\RefreshTokenService
{
    $redis = new Predis\Client([
        'scheme' => 'tcp',
        'host' => 'redis',
        'port' => 6379,
        'database' => 15,
    ]);

    return new App\Service\RefreshTokenService($redis);
}

/** @param list<array{access_token: string, refresh_token: string}> $tokens */
function qrLoginCodeTokenProbeVerifyTokens(int $userId, array $tokens): void
{
    $jwtKey = getenv('JWT_KEY');
    if (!is_string($jwtKey) || !is_file($jwtKey)) {
        throw new RuntimeException('Test JWT key is unavailable.');
    }

    $jwt = new App\Service\JwtService($jwtKey);
    $refreshTokens = qrLoginCodeTokenProbeRefreshTokenService();
    foreach ($tokens as $token) {
        $parsed = $jwt->parseToken($token['access_token']);
        if (!is_array($parsed) || ($parsed['user_id'] ?? null) !== $userId) {
            throw new RuntimeException('Unexpected access token.');
        }
        if ($refreshTokens->validate($token['refresh_token']) !== $userId) {
            throw new RuntimeException('Unexpected refresh token.');
        }
    }
}

/** @param list<string> $refreshTokens */
function qrLoginCodeTokenProbeDeleteRefreshTokens(array $refreshTokens): void
{
    $service = qrLoginCodeTokenProbeRefreshTokenService();
    foreach ($refreshTokens as $refreshToken) {
        $service->delete($refreshToken);
    }
}

try {
    qrLoginCodeTokenProbeAssertIsolatedEnvironment();
    $input = qrLoginCodeTokenProbeInput();
    $basePath = dirname(__DIR__, 2);
    require_once $basePath . '/vendor/autoload.php';

    if ($input['action'] === 'setup_user') {
        qrLoginCodeTokenProbeSetupUser(
            qrLoginCodeTokenProbeDatabase(),
            $input['user_id'],
            $input['username'],
        );
        qrLoginCodeTokenProbeEmit(['ok' => true, 'operation' => 'setup_user']);
    }

    if ($input['action'] === 'cleanup_user') {
        qrLoginCodeTokenProbeCleanupUser(qrLoginCodeTokenProbeDatabase(), $input['user_id']);
        qrLoginCodeTokenProbeEmit(['ok' => true, 'operation' => 'cleanup_user']);
    }

    if ($input['action'] === 'verify_tokens') {
        qrLoginCodeTokenProbeVerifyTokens($input['user_id'], $input['tokens']);
        qrLoginCodeTokenProbeEmit(['ok' => true, 'operation' => 'verify_tokens']);
    }

    qrLoginCodeTokenProbeDeleteRefreshTokens($input['refresh_tokens']);
    qrLoginCodeTokenProbeEmit(['ok' => true, 'operation' => 'delete_refresh_tokens']);
} catch (Throwable) {
    // Inputs contain bearer-equivalent material; do not expose it or a driver
    // message in a test log.
    qrLoginCodeTokenProbeEmit(['ok' => false, 'error' => 'probe_failed'], 1);
}
