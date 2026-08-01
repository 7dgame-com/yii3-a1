<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LoginCodeLookupStatus;
use App\Service\LoginCodeReadinessResult;
use App\Service\LoginCodeRedisClient;
use App\Service\LoginCodeSettings;
use App\Service\LoginCodeStore;
use App\Tests\Support\ControlledLoginCodeRedisClient;
use App\Tests\Support\StaticLoginCodeReadiness;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

final class LoginCodeStoreTest extends TestCase
{
    private const REDIS_NOW = 1_780_000_000;

    public function testRedisHitUsesDigestKeyAndDoesNotExposeRawCode(): void
    {
        $rawCode = str_repeat('a', 64);
        $payload = $this->recordPayload(userId: 42, issuedAt: self::REDIS_NOW);
        $redis = new ControlledLoginCodeRedisClient($payload, 240_001, [self::REDIS_NOW, 0]);
        $store = $this->redisStore($redis);

        $result = $store->resolve($rawCode);

        $this->assertSame(LoginCodeLookupStatus::HIT, $result->status);
        $this->assertSame(42, $result->userId);
        $this->assertSame('auth:login-code:v1:code:' . hash('sha256', $rawCode), $redis->getKeys[0]);
        $this->assertSame($redis->getKeys[0], $redis->pttlKeys[0]);
        $this->assertStringNotContainsString($rawCode, $redis->getKeys[0]);
        $this->assertStringNotContainsString($rawCode, $payload);
    }

    public function testRedisCodeAtPttlBoundaryIsExpired(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: self::REDIS_NOW),
            240_000,
            [self::REDIS_NOW, 0],
        );

        $result = $this->redisStore($redis)->resolve(str_repeat('b', 64));

        $this->assertSame(LoginCodeLookupStatus::EXPIRED, $result->status);
    }

    public function testRedisTimeOneMillisecondBeforeExpiresAtRemainsHitWhenPttlIsAboveBoundary(): void
    {
        $issuedAt = self::REDIS_NOW;
        $expiresAt = $issuedAt + LoginCodeSettings::ACTIVE_WINDOW_SECONDS;
        $pttl = 240_001;
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: $issuedAt),
            $pttl,
            [$expiresAt - 1, 999_000],
        );

        $result = $this->redisStore($redis)->resolve(str_repeat('c', 64));

        // PTTL remains above the separate 240-second cutoff, so this proves
        // the exact Redis TIME value one millisecond before expiry stays live.
        $this->assertGreaterThan(240_000, $pttl);
        $this->assertSame(LoginCodeLookupStatus::HIT, $result->status);
    }

    public function testRedisTimeAtExpiresAtIsExpiredWhenPttlIsStillAboveBoundary(): void
    {
        $issuedAt = self::REDIS_NOW;
        $expiresAt = $issuedAt + LoginCodeSettings::ACTIVE_WINDOW_SECONDS;
        $pttl = 240_001;
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: $issuedAt),
            $pttl,
            [$expiresAt, 0],
        );

        $result = $this->redisStore($redis)->resolve(str_repeat('c', 64));

        // The PTTL gate is deliberately inactive; equality at expires_at *
        // 1000 must therefore be enough to expire the record.
        $this->assertGreaterThan(240_000, $pttl);
        $this->assertSame(LoginCodeLookupStatus::EXPIRED, $result->status);
    }

    public function testMalformedRedisPayloadFailsClosed(): void
    {
        $redis = new ControlledLoginCodeRedisClient('{"v":1}', 299_999, [self::REDIS_NOW, 0]);

        $result = $this->redisStore($redis)->resolve(str_repeat('d', 64));

        $this->assertSame(LoginCodeLookupStatus::MALFORMED, $result->status);
        $this->assertTrue($result->isInfrastructureFailure());
    }

    public function testRedisFailureIsUnavailableRatherThanAuthenticationMiss(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [self::REDIS_NOW, 0],
            new RuntimeException('connection failed'),
        );

        $result = $this->redisStore($redis)->resolve(str_repeat('e', 64));

        $this->assertSame(LoginCodeLookupStatus::UNAVAILABLE, $result->status);
        $this->assertTrue($result->isInfrastructureFailure());
    }

    public function testConflictingGetAndPttlResultFailsClosedInsteadOfDbFallbackMiss(): void
    {
        $this->useLinkedLoginCodeConnection([], prohibitLookup: true);
        $redis = new ControlledLoginCodeRedisClient(null, 299_999, [self::REDIS_NOW, 0]);

        $result = $this->redisFirstStore($redis)->resolve(str_repeat('0', 64));

        $this->assertSame(LoginCodeLookupStatus::MALFORMED, $result->status);
        $this->assertTrue($result->isInfrastructureFailure());
    }

    public function testRedisFirstFallsBackOnlyForHealthyMiss(): void
    {
        $rawCode = str_repeat('f', 64);
        $this->useLinkedLoginCodeConnection([
            [
                'user_id' => 42,
                'created_at_epoch' => self::REDIS_NOW - 59,
            ],
        ]);
        $redis = new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]);
        $store = new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            StaticLoginCodeReadiness::ready(),
        );

        $result = $store->resolve($rawCode);

        $this->assertSame(LoginCodeLookupStatus::HIT, $result->status);
        $this->assertSame(42, $result->userId);
    }

    public function testRedisFirstFallbackUsesOneCurrentRowSnapshot(): void
    {
        $rawCode = str_repeat('a', 64);
        $boundParameters = [];
        $command = $this->createMock(CommandInterface::class);
        $command->expects($this->once())
            ->method('bindParam')
            ->willReturnCallback(function (string $placeholder, mixed &$value) use (&$boundParameters, $command): CommandInterface {
                $boundParameters[$placeholder] = $value;

                return $command;
            });
        $command->expects($this->once())
            ->method('queryOne')
            ->willReturn(null);
        $command->expects($this->never())->method('queryScalar');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('createCommand')
            ->with(
                $this->callback(static function (mixed $sql): bool {
                    return is_string($sql)
                        && str_contains($sql, "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00'")
                        && str_contains($sql, "CONVERT_TZ(`linked`.`created_at`, '+08:00', '+00:00')")
                        && !str_contains($sql, 'UNIX_TIMESTAMP')
                        && str_contains($sql, '`linked`.`key` = :loginCodeKey')
                        && str_contains($sql, 'NOT EXISTS')
                        && str_contains($sql, '`newer`.`user_id` = `linked`.`user_id`')
                        && str_contains($sql, '`newer`.`id` > `linked`.`id`');
                }),
                [],
            )
            ->willReturn($command);
        ConnectionProvider::set($connection);

        $result = $this->redisFirstStore(
            new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]),
        )->resolve($rawCode);

        // The mocked DB reports no current row. In production that is the
        // result when a matching historical row has been superseded for its
        // user, so it must not revive the old login code.
        $this->assertSame(LoginCodeLookupStatus::MISS, $result->status);
        $this->assertSame([
            ':loginCodeKey' => hash('sha256', $rawCode),
        ], $boundParameters);
    }

    public function testDatabaseModeDoesNotTreatStoredDigestAsBearer(): void
    {
        $rawCode = str_repeat('c', 64);
        $storedDigest = hash('sha256', $rawCode);
        $boundParameters = [];
        $command = $this->createMock(CommandInterface::class);
        $command->expects($this->once())
            ->method('bindParam')
            ->willReturnCallback(function (string $placeholder, mixed &$value) use (&$boundParameters, $command): CommandInterface {
                $boundParameters[$placeholder] = $value;

                return $command;
            });
        $command->expects($this->once())->method('queryOne')->willReturn(null);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('createCommand')
            ->with($this->callback(static function (mixed $sql): bool {
                return is_string($sql)
                    && str_contains($sql, '`linked`.`key` = :loginCodeKey')
                    && str_contains($sql, 'NOT EXISTS')
                    && str_contains($sql, '`newer`.`id` > `linked`.`id`');
            }))
            ->willReturn($command);
        ConnectionProvider::set($connection);

        $result = (new LoginCodeStore(
            new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]),
            new LoginCodeSettings(),
        ))->resolve($storedDigest);

        $this->assertSame(LoginCodeLookupStatus::MISS, $result->status);
        $this->assertSame([':loginCodeKey' => hash('sha256', $storedDigest)], $boundParameters);
        $this->assertNotSame($storedDigest, $boundParameters[':loginCodeKey']);
    }

    public function testRedisFirstFallbackDoesNotTreatStoredDigestAsBearer(): void
    {
        $rawCode = str_repeat('d', 64);
        $storedDigest = hash('sha256', $rawCode);
        $boundParameters = [];
        $command = $this->createMock(CommandInterface::class);
        $command->expects($this->once())
            ->method('bindParam')
            ->willReturnCallback(function (string $placeholder, mixed &$value) use (&$boundParameters, $command): CommandInterface {
                $boundParameters[$placeholder] = $value;

                return $command;
            });
        $command->expects($this->once())->method('queryOne')->willReturn(null);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);
        ConnectionProvider::set($connection);

        $result = $this->redisFirstStore(
            new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]),
        )->resolve($storedDigest);

        $this->assertSame(LoginCodeLookupStatus::MISS, $result->status);
        $this->assertSame([':loginCodeKey' => hash('sha256', $storedDigest)], $boundParameters);
        $this->assertNotSame($storedDigest, $boundParameters[':loginCodeKey']);
    }

    public function testRedisFirstFallbackDatabaseExceptionIsRedactedAndFailsClosed(): void
    {
        $rawCode = str_repeat('b', 64);
        $digest = hash('sha256', $rawCode);
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willThrowException(
            new RuntimeException('DB lookup failed for ' . $rawCode . ' / ' . $digest),
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);
        ConnectionProvider::set($connection);

        $result = $this->redisFirstStore(
            new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]),
        )->resolve($rawCode);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame(LoginCodeLookupStatus::UNAVAILABLE, $result->status);
        $this->assertStringNotContainsString($rawCode, $serialized);
        $this->assertStringNotContainsString($digest, $serialized);
    }

    public function testRedisFirstDoesNotFallbackForExpiredRedisRecord(): void
    {
        $this->useLinkedLoginCodeConnection([], prohibitLookup: true);
        $issuedAt = self::REDIS_NOW - LoginCodeSettings::ACTIVE_WINDOW_SECONDS;
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: $issuedAt),
            250_000,
            [self::REDIS_NOW, 0],
        );
        $store = new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            StaticLoginCodeReadiness::ready(),
        );

        $result = $store->resolve(str_repeat('1', 64));

        $this->assertSame(LoginCodeLookupStatus::EXPIRED, $result->status);
    }

    public function testRedisFirstDoesNotFallbackForMalformedRedisRecord(): void
    {
        $this->useLinkedLoginCodeConnection([], prohibitLookup: true);
        $redis = new ControlledLoginCodeRedisClient('{"v":1}', 299_999, [self::REDIS_NOW, 0]);

        $result = $this->redisFirstStore($redis)->resolve(str_repeat('5', 64));

        $this->assertSame(LoginCodeLookupStatus::MALFORMED, $result->status);
    }

    public function testRedisFirstDoesNotFallbackWhenRedisIsUnavailable(): void
    {
        $this->useLinkedLoginCodeConnection([], prohibitLookup: true);
        $redis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [self::REDIS_NOW, 0],
            new RuntimeException('redis failure'),
        );

        $result = $this->redisFirstStore($redis)->resolve(str_repeat('6', 64));

        $this->assertSame(LoginCodeLookupStatus::UNAVAILABLE, $result->status);
    }

    public function testRedisOnlyMissDoesNotQueryLegacyDatabase(): void
    {
        $this->useLinkedLoginCodeConnection([], prohibitLookup: true);
        $redis = new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]);

        $result = $this->redisStore($redis)->resolve(str_repeat('7', 64));

        $this->assertSame(LoginCodeLookupStatus::MISS, $result->status);
    }

    public function testRedisFirstLegacyFallbackUsesSixtyAndThreeHundredSecondBoundaries(): void
    {
        $rawCode = str_repeat('2', 64);
        $this->useLinkedLoginCodeConnection([
            [
                'user_id' => 42,
                'created_at_epoch' => self::REDIS_NOW - 60,
            ],
            [
                'user_id' => 42,
                'created_at_epoch' => self::REDIS_NOW - 300,
            ],
        ]);
        $redis = new ControlledLoginCodeRedisClient(null, -2, [self::REDIS_NOW, 0]);
        $store = new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            StaticLoginCodeReadiness::ready(),
        );

        $atSixtySeconds = $store->resolve($rawCode);
        $atThreeHundredSeconds = $store->resolve($rawCode);

        $this->assertSame(LoginCodeLookupStatus::EXPIRED, $atSixtySeconds->status);
        $this->assertSame(LoginCodeLookupStatus::MISS, $atThreeHundredSeconds->status);
    }

    public function testDatabaseModeDoesNotCallLoginCodeRedis(): void
    {
        $rawCode = str_repeat('3', 64);
        $this->useLinkedLoginCodeConnection([
            [
                'id' => 1,
                'user_id' => 42,
                'key' => hash('sha256', $rawCode),
                'created_at' => $this->shanghaiTimestamp(time()),
            ],
        ]);
        $redis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [self::REDIS_NOW, 0],
            new RuntimeException('database mode must not use Redis'),
        );
        $readiness = new StaticLoginCodeReadiness(
            LoginCodeReadinessResult::failed(LoginCodeReadinessResult::APP_CLOCK_SKEW),
        );
        $store = new LoginCodeStore($redis, new LoginCodeSettings(), $readiness);

        $result = $store->resolve($rawCode);

        $this->assertSame(LoginCodeLookupStatus::HIT, $result->status);
        $this->assertSame([], $redis->getKeys);
        $this->assertSame(0, $redis->timeCalls);
        $this->assertSame(0, $readiness->checks);
    }

    public function testRedisModeFailsClosedBeforeReadWhenReadinessIsNotReady(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: self::REDIS_NOW),
            240_001,
            [self::REDIS_NOW, 0],
        );
        $readiness = new StaticLoginCodeReadiness(
            LoginCodeReadinessResult::failed(LoginCodeReadinessResult::APP_CLOCK_SKEW),
        );
        $store = new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            $readiness,
        );

        $result = $store->resolve(str_repeat('4', 64));

        $this->assertSame(LoginCodeLookupStatus::UNAVAILABLE, $result->status);
        $this->assertTrue($result->isInfrastructureFailure());
        $this->assertSame(1, $readiness->checks);
        $this->assertSame([], $redis->getKeys);
        $this->assertSame(0, $redis->timeCalls);
    }

    public function testRedisModeFailsClosedWhenReadinessGateIsMissing(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            $this->recordPayload(userId: 42, issuedAt: self::REDIS_NOW),
            240_001,
            [self::REDIS_NOW, 0],
        );
        $store = new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
        );

        $result = $store->resolve(str_repeat('a', 64));

        $this->assertSame(LoginCodeLookupStatus::UNAVAILABLE, $result->status);
        $this->assertSame([], $redis->getKeys);
        $this->assertSame(0, $redis->timeCalls);
    }

    private function redisStore(LoginCodeRedisClient $redis): LoginCodeStore
    {
        return new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
        );
    }

    private function redisFirstStore(LoginCodeRedisClient $redis): LoginCodeStore
    {
        return new LoginCodeStore(
            $redis,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            StaticLoginCodeReadiness::ready(),
        );
    }

    private function recordPayload(int $userId, int $issuedAt): string
    {
        return json_encode([
            'v' => 1,
            'user_id' => $userId,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + LoginCodeSettings::ACTIVE_WINDOW_SECONDS,
            'purpose' => 'web-device-login',
            'issuer' => 'main-api',
            'context' => (object) [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $queryOneResults
     */
    private function useLinkedLoginCodeConnection(
        array $queryOneResults,
        bool $prohibitLookup = false,
    ): void
    {
        $command = $this->createMock(CommandInterface::class);
        if ($prohibitLookup) {
            $command->expects($this->never())->method('queryOne');
        } else {
            $command->method('queryOne')->willReturnOnConsecutiveCalls(...$queryOneResults);
        }
        $command->method('bindParam')->willReturnSelf();

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);

        ConnectionProvider::set($connection);
    }

    private function shanghaiTimestamp(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
    }
}
