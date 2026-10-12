<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Controller\V1\AuthController;
use App\Service\IdentityAuthorityClient;
use App\Service\JwtService;
use App\Service\LoginCodeStore;
use App\Service\RefreshTokenService;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

final class IdentityAuthorityDiTest extends TestCase
{
    public function testConfigurationDefaultsAndScopedTokenFallbacks(): void
    {
        $saved = $_ENV;
        try {
            foreach (array_keys($_ENV) as $name) if (str_starts_with($name, 'IDENTITY_')) unset($_ENV[$name]);
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';
            self::assertSame('legacy', $params['identity']['dataSource']);
            self::assertFalse($params['identity']['ready']);
            $_ENV['IDENTITY_INTERNAL_API_TOKEN'] = 'general';
            $_ENV['IDENTITY_TOKEN_ISSUANCE_INTERNAL_API_TOKEN'] = 'auth';
            $_ENV['IDENTITY_IAM_INTERNAL_API_TOKEN'] = 'iam';
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';
            self::assertSame('auth', $params['identity']['internalToken']); self::assertSame('iam', $params['identity']['iamToken']);
            $_ENV['IDENTITY_TOKEN_ISSUANCE_INTERNAL_API_TOKEN'] = '';
            $_ENV['IDENTITY_IAM_INTERNAL_API_TOKEN'] = '';
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';
            self::assertSame('general', $params['identity']['internalToken']); self::assertSame('general', $params['identity']['iamToken']);
        } finally { $_ENV = $saved; }
    }

    public function testRuntimeDiAuthenticatesWithoutLegacyDatabaseRedisOrSigningKey(): void
    {
        $params = require dirname(__DIR__, 3) . '/config/common/params.php';
        $params['jwt']['keyFile'] = '/missing-legacy-signing-key';
        $definitions = array_merge(require dirname(__DIR__, 3) . '/config/common/di/services.php', require dirname(__DIR__, 3) . '/config/common/di/jwt.php');
        $authority = new IdentityAuthorityClient('identity', 'http://authority.invalid', 'auth', 'iam', 1000, true,
            static fn($path) => ['status' => 200, 'body' => $path === '/v1/auth/login'
                ? ['token' => ['accessToken' => 'private-access', 'refreshToken' => 'private-refresh', 'expires' => '2026-10-12T12:00:00.000Z']]
                : ['data' => ['id' => 42, 'username' => 'private', 'status' => 10, 'roles' => ['user']]]]);
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');
        $definitions[IdentityAuthorityClient::class] = $authority;
        $definitions[ConnectionInterface::class] = $db;
        $definitions[Client::class] = new Client('tcp://127.0.0.1:1');
        $definitions[LoggerInterface::class] = new NullLogger();
        $definitions[ResponseFactoryInterface::class] = new ResponseFactory();
        $definitions[StreamFactoryInterface::class] = new StreamFactory();
        $container = new Container(ContainerConfig::create()->withDefinitions($definitions));
        $response = $container->get(AuthController::class)->login((new ServerRequest())->withParsedBody(['username' => 'private', 'password' => 'private-password']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['user_id' => 42], $container->get(JwtService::class)->parseToken('private-access'));
        foreach ([
            static fn() => $container->get(RefreshTokenService::class)->create(42),
            static fn() => $container->get(RefreshTokenService::class)->consumeSession('legacy-refresh'),
            static fn() => $container->get(RefreshTokenService::class)->delete('legacy-refresh'),
            static fn() => $container->get(LoginCodeStore::class)->resolve('legacy-code'),
        ] as $attempt) {
            try { $attempt(); self::fail('Retired local authority must not be reachable.'); }
            catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        }
    }
}
