<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\RefreshTokenService;
use DateTimeImmutable;
use DateTimeZone;
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

        $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
        $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
        $redisDb = (int)(getenv('REDIS_DB') ?: 1);
        $redis = new \Predis\Client(['scheme'=>'tcp','host'=>$redisHost,'port'=>$redisPort,'database'=>$redisDb]);
        $this->refreshTokenService = new RefreshTokenService($redis);
    }

    public function testRefreshReturnsTokenPairForValidToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
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
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $parsed = $this->jwtService->parseToken($result['token']['accessToken']);
        $this->assertNotNull($parsed);
        $this->assertSame(42, $parsed['user_id']);
    }

    public function testRefreshDeletesOldToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $authService->refresh($oldRefreshToken);
        $this->assertNull($this->refreshTokenService->validate($oldRefreshToken));
    }

    public function testRefreshNewTokenIsValid(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $validatedUserId = $this->refreshTokenService->validate($result['token']['refreshToken']);
        $this->assertSame(42, $validatedUserId);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testRefreshReturnsDifferentToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $result = $authService->refresh($oldRefreshToken);
        $this->assertNotSame($oldRefreshToken, $result['token']['refreshToken']);
        $this->refreshTokenService->delete($result['token']['refreshToken']);
    }

    public function testRefreshAcceptsReusableBareLinkedLoginCode(): void
    {
        $loginCode = str_repeat('a', 64);
        $createdAt = $this->shanghaiTime('now');
        $linkedRow = ['id' => 1, 'user_id' => 42, 'key' => hash('sha256', $loginCode), 'created_at' => $createdAt];
        $userRow = ['id' => 42, 'nickname' => 'testuser'];
        $this->useLinkedLoginCodeConnection([$linkedRow, $userRow, $linkedRow, $userRow]);

        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
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

        $authService = new AuthService($this->jwtService, $this->refreshTokenService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Refresh token is invalid.');
        $authService->refresh($loginCode);
    }

    public function testRefreshThrows401ForInvalidToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('Refresh token is invalid.');
        $authService->refresh('nonexistent_invalid_token');
    }

    public function testRefreshThrows401ForEmptyToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $authService->refresh('');
    }

    public function testRefreshThrows401ForAlreadyUsedToken(): void
    {
        $authService = new AuthService($this->jwtService, $this->refreshTokenService);
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
}
