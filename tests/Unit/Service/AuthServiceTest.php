<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\LoginCodeSettings;
use App\Service\LoginCodeStore;
use App\Service\RefreshTokenService;
use App\Tests\Support\ControlledLoginCodeRedisClient;
use App\Tests\Support\MappedLoginCodeRedisClient;
use App\Tests\Support\RedisTestClientFactory;
use App\Tests\Support\StaticLoginCodeReadiness;
use DateTimeImmutable;
use DateTimeZone;
use Predis\Client as RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;
use Yiisoft\Db\Schema\Column\ColumnInterface;

final class AuthServiceTest extends TestCase
{
    private JwtService $jwtService;
    private RefreshTokenService $refreshTokenService;
    private LoginCodeStore $databaseLoginCodeStore;
    private RedisClient $redis;

    protected function setUp(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willReturn(['id' => 42, 'nickname' => 'testuser']);
        $command->method('queryAll')->willReturn([['id' => 42, 'nickname' => 'testuser']]);

        $quoter = $this->createMock(QuoterInterface::class);
        $quoter->method('quoteTableName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteColumnName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteSql')->willReturnCallback(fn($s) => $s);
        $quoter->method('getRawTableName')->willReturnCallback(fn($n) => trim($n, '{}%`'));

        $col = $this->createMock(ColumnInterface::class);
        $col->method('phpTypecast')->willReturnCallback(fn($v) => $v);

        $tableSchema = $this->createMock(TableSchemaInterface::class);
        $tableSchema->method('getColumns')->willReturn(['id' => $col, 'nickname' => $col]);
        $tableSchema->method('getColumn')->willReturn($col);

        $schema = $this->createMock(SchemaInterface::class);
        $schema->method('getTableSchema')->willReturn($tableSchema);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $queryBuilder->method('build')->willReturn(['SELECT * FROM `user` WHERE `id`=42', []]);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);
        $connection->method('getQueryBuilder')->willReturn($queryBuilder);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getQuoter')->willReturn($quoter);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('getTableSchema')->willReturn($tableSchema);

        ConnectionProvider::set($connection);

        $keyFilePath = tempnam(sys_get_temp_dir(), 'jwt_auth_test_');
        file_put_contents($keyFilePath, 'test-secret-key-for-auth-service-testing-minimum');
        $this->jwtService = new JwtService($keyFilePath);

        $this->redis = RedisTestClientFactory::create();
        $this->refreshTokenService = new RefreshTokenService($this->redis);
        $this->databaseLoginCodeStore = new LoginCodeStore($this->redis, new LoginCodeSettings());
    }

    public function testRefreshReturnsTokenPairForValidToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $this->assertTrue($result['success']);
        $this->assertSame('refresh', $result['message']);
        $this->assertArrayHasKey('nickname', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']['accessToken']);
        $this->assertNotEmpty($result['token']['refreshToken']);
    }

    public function testRefreshAccessTokenIsValidJwt(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $parsed = $this->jwtService->parseToken($result['token']['accessToken']);
        $this->assertNotNull($parsed);
        $this->assertSame(42, $parsed['user_id']);
    }

    public function testRefreshDeletesOldToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $authService->refresh($oldRefreshToken);
        $this->assertNull($this->refreshTokenService->validate($oldRefreshToken));
    }

    public function testRefreshNewTokenIsValid(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $validatedUserId = $this->refreshTokenService->validate($result['token']['refreshToken']);
        $this->assertSame(42, $validatedUserId);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testRefreshReturnsDifferentToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $this->assertNotSame($oldRefreshToken, $result['token']['refreshToken']);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testRefreshPrefersOrdinaryRefreshTokenBeforeLoginCodeStore(): void
    {
        $loginCodeRedis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [1_780_000_000, 0],
            new RuntimeException('login-code Redis must not be queried'),
        );
        $loginCodeStore = new LoginCodeStore(
            $loginCodeRedis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);
        $refreshToken = $this->refreshTokenService->create(42);

        $result = $authService->refresh($refreshToken);

        $this->assertTrue($result['success']);
        $this->assertSame([], $loginCodeRedis->getKeys);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testRefreshAcceptsReusableRedisLoginCodeWithoutConsumption(): void
    {
        $rawCode = bin2hex(random_bytes(32));
        $loginCodeRedis = new ControlledLoginCodeRedisClient(
            $this->redisCodePayload(userId: 42, issuedAt: 1_780_000_000),
            240_001,
            [1_780_000_000, 0],
        );
        $loginCodeStore = new LoginCodeStore(
            $loginCodeRedis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $first = $authService->refresh($rawCode);
        $second = $authService->refresh($rawCode);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertArrayNotHasKey('frontendDomain', $first);
        $this->assertArrayNotHasKey('frontendDomain', $second);
        $this->assertCount(2, $loginCodeRedis->getKeys);
        $this->assertSame($loginCodeStore->keyFor($rawCode), $loginCodeRedis->getKeys[0]);
        $this->assertSame($loginCodeStore->keyFor($rawCode), $loginCodeRedis->getKeys[1]);
        $this->refreshTokenService->delete($first['token']['refreshToken']);
        $this->refreshTokenService->delete($second['token']['refreshToken']);
    }

    public function testMultipleRedisLoginCodesForOneUserCanEachRefreshAndExchangeForTokens(): void
    {
        $firstCode = bin2hex(random_bytes(32));
        $secondCode = bin2hex(random_bytes(32));
        $settings = new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
        );
        $loginCodeRedis = new MappedLoginCodeRedisClient([1_780_000_000, 0]);
        $loginCodeStore = new LoginCodeStore(
            $loginCodeRedis,
            $settings,
            StaticLoginCodeReadiness::ready(),
        );
        $payload = $this->redisCodePayload(userId: 42, issuedAt: 1_780_000_000);
        $loginCodeRedis->put($loginCodeStore->keyFor($firstCode), $payload, 240_001);
        $loginCodeRedis->put($loginCodeStore->keyFor($secondCode), $payload, 240_001);
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $firstRefresh = $authService->refresh($firstCode);
        $secondRefresh = $authService->refresh($secondCode);
        $firstExchange = $authService->keyToToken($firstCode);
        $secondExchange = $authService->keyToToken($secondCode);

        $this->assertSame('refresh', $firstRefresh['message']);
        $this->assertSame('refresh', $secondRefresh['message']);
        $this->assertSame('keyToToken', $firstExchange['message']);
        $this->assertSame('keyToToken', $secondExchange['message']);
        foreach ([$firstRefresh, $secondRefresh, $firstExchange, $secondExchange] as $result) {
            $this->assertTrue($result['success']);
            $this->assertSame(42, $this->refreshTokenService->validate($result['token']['refreshToken']));
            $this->refreshTokenService->delete($result['token']['refreshToken']);
        }

        $this->assertCount(4, $loginCodeRedis->getKeys);
        $this->assertSame(2, count(array_unique($loginCodeRedis->getKeys)));
        $this->assertSame(0, $loginCodeRedis->unknownGetCalls);
        $this->assertSame(0, $loginCodeRedis->unknownPttlCalls);
        $this->assertSame(4, $loginCodeRedis->timeCalls);
    }

    public function testRefreshReturns401ForExpiredRedisLoginCode(): void
    {
        $issuedAt = 1_780_000_000 - LoginCodeSettings::ACTIVE_WINDOW_SECONDS;
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                $this->redisCodePayload(userId: 42, issuedAt: $issuedAt),
                250_000,
                [1_780_000_000, 0],
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Refresh token is invalid.');
        $authService->refresh(str_repeat('8', 64));
    }

    public function testRefreshReturnsRedacted503ForUnavailableRedisLoginCodeStore(): void
    {
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                null,
                -2,
                [1_780_000_000, 0],
                new RuntimeException('GET auth:login-code:v1:code:sensitive-digest failed'),
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        try {
            $authService->refresh(str_repeat('9', 64));
            $this->fail('Expected a redacted login-code storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(503, $exception->getCode());
            $this->assertSame('Login code storage is unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sensitive-digest', $exception->getMessage());
        }
    }

    public function testKeyToTokenAcceptsRedisLoginCode(): void
    {
        $rawCode = bin2hex(random_bytes(32));
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                $this->redisCodePayload(userId: 42, issuedAt: 1_780_000_000),
                240_001,
                [1_780_000_000, 0],
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $result = $authService->keyToToken($rawCode);

        $this->assertTrue($result['success']);
        $this->assertSame('keyToToken', $result['message']);
        $this->assertArrayNotHasKey('frontendDomain', $result);
        $this->assertNotEmpty($result['token']['accessToken']);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testLoginCodeContextReturnsDomainWithoutChangingTokenEndpoints(): void
    {
        $rawCode = bin2hex(random_bytes(32));
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                $this->redisCodePayload(
                    userId: 42,
                    issuedAt: 1_780_000_000,
                    frontendDomain: 'd.dev.xrugc.com',
                ),
                240_001,
                [1_780_000_000, 0],
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $result = $authService->loginCodeContext('https://example.invalid/?web_' . $rawCode);

        $this->assertSame([
            'success' => true,
            'message' => 'loginCodeContext',
            'frontendDomain' => 'd.dev.xrugc.com',
        ], $result);
    }

    public function testLoginCodeContextReturnsNullForCompatibleOldRecord(): void
    {
        $rawCode = bin2hex(random_bytes(32));
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                $this->redisCodePayload(userId: 42, issuedAt: 1_780_000_000),
                240_001,
                [1_780_000_000, 0],
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $result = $authService->loginCodeContext($rawCode);

        $this->assertTrue($result['success']);
        $this->assertNull($result['frontendDomain']);
    }

    public function testRefreshNormalizesWebQueryWrappedRedisLoginCode(): void
    {
        $rawCode = bin2hex(random_bytes(32));
        $loginCodeRedis = new ControlledLoginCodeRedisClient(
            $this->redisCodePayload(userId: 42, issuedAt: 1_780_000_000),
            240_001,
            [1_780_000_000, 0],
        );
        $loginCodeStore = new LoginCodeStore(
            $loginCodeRedis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $result = $authService->refresh('https://example.invalid/?web_' . $rawCode);

        $this->assertTrue($result['success']);
        $this->assertSame($loginCodeStore->keyFor($rawCode), $loginCodeRedis->getKeys[0]);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testKeyToTokenReturns503ForMalformedRedisRecord(): void
    {
        $loginCodeStore = new LoginCodeStore(
            new ControlledLoginCodeRedisClient('{"v":1}', 299_999, [1_780_000_000, 0]),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $loginCodeStore);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('Login code storage is unavailable.');
        $authService->keyToToken(str_repeat('4', 64));
    }

    public function testRefreshAcceptsReusableBareLinkedLoginCode(): void
    {
        $loginCode = str_repeat('a', 64);
        $createdAt = $this->shanghaiTime('now');
        $linkedRow = ['id' => 1, 'user_id' => 42, 'key' => hash('sha256', $loginCode), 'created_at' => $createdAt];
        $userRow = ['id' => 42, 'nickname' => 'testuser'];
        $this->useLinkedLoginCodeConnection([$linkedRow, $userRow, $linkedRow, $userRow]);

        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $first = $authService->refresh($loginCode);
        $second = $authService->refresh($loginCode);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertSame('refresh', $first['message']);
        $this->assertSame('refresh', $second['message']);
        $this->assertNotEmpty($first['token']['accessToken']);
        $this->assertNotEmpty($second['token']['accessToken']);
        $this->assertNotEmpty($first['token']['refreshToken']);
        $this->assertNotEmpty($second['token']['refreshToken']);
        $this->assertNotSame($first['token']['refreshToken'], $second['token']['refreshToken']);
        $this->assertSame(42, $this->refreshTokenService->validate($first['token']['refreshToken']));
        $this->assertSame(42, $this->refreshTokenService->validate($second['token']['refreshToken']));
        $this->refreshTokenService->delete($first['token']['refreshToken']);
        $this->refreshTokenService->delete($second['token']['refreshToken']);
    }

    public function testRefreshThrows401ForExpiredLinkedLoginCode(): void
    {
        $loginCode = str_repeat('b', 64);
        $this->useLinkedLoginCodeConnection([
            [
                'id' => 1,
                'user_id' => 42,
                'key' => hash('sha256', $loginCode),
                'created_at' => $this->shanghaiTime('-2 minutes'),
            ],
        ]);

        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Refresh token is invalid.');
        $authService->refresh($loginCode);
    }

    public function testRefreshThrows401ForInvalidToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Refresh token is invalid.');
        $authService->refresh('nonexistent_invalid_token');
    }

    public function testRefreshThrows401ForEmptyToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $authService->refresh('');
    }

    public function testRefreshThrows401ForAlreadyUsedToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService, $this->databaseLoginCodeStore);
        $refreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($refreshToken);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $authService->refresh($refreshToken);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    private function useLinkedLoginCodeConnection(array $queryOneResults): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willReturnOnConsecutiveCalls(...$queryOneResults);
        $command->expects($this->never())->method('execute');
        $command->method('queryAll')->willReturn([]);

        $quoter = $this->createMock(QuoterInterface::class);
        $quoter->method('quoteTableName')->willReturnCallback(fn ($name) => "`$name`");
        $quoter->method('quoteColumnName')->willReturnCallback(fn ($name) => "`$name`");
        $quoter->method('quoteSql')->willReturnCallback(fn ($sql) => $sql);
        $quoter->method('getRawTableName')->willReturnCallback(fn ($name) => trim($name, '{}%`'));

        $col = $this->createMock(ColumnInterface::class);
        $col->method('phpTypecast')->willReturnCallback(fn ($value) => $value);

        $tableSchema = $this->createMock(TableSchemaInterface::class);
        $tableSchema->method('getColumns')->willReturn([
            'id' => $col,
            'user_id' => $col,
            'key' => $col,
            'created_at' => $col,
            'nickname' => $col,
        ]);
        $tableSchema->method('getColumn')->willReturn($col);
        $tableSchema->method('getPrimaryKey')->willReturn(['id']);

        $schema = $this->createMock(SchemaInterface::class);
        $schema->method('getTableSchema')->willReturn($tableSchema);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $queryBuilder->method('build')->willReturn(['SELECT * FROM test', []]);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);
        $connection->method('getQueryBuilder')->willReturn($queryBuilder);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getQuoter')->willReturn($quoter);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('getTableSchema')->willReturn($tableSchema);

        ConnectionProvider::set($connection);
    }

    private function shanghaiTime(string $modifier): string
    {
        return (new DateTimeImmutable($modifier, new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    private function redisCodePayload(int $userId, int $issuedAt, ?string $frontendDomain = null): string
    {
        $context = $frontendDomain === null
            ? (object) []
            : ['frontend_domain' => $frontendDomain];

        return json_encode([
            'v' => 1,
            'user_id' => $userId,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + LoginCodeSettings::ACTIVE_WINDOW_SECONDS,
            'purpose' => 'web-device-login',
            'issuer' => 'main-api',
            'context' => $context,
        ], JSON_THROW_ON_ERROR);
    }
}
