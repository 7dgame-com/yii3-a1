<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LoginCodeReadinessGate;
use App\Service\LoginCodeReadinessResult;
use App\Service\LoginCodeSettings;
use App\Tests\Support\ControlledLoginCodeRedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class LoginCodeReadinessGateTest extends TestCase
{
    public function testDatabaseModeSkipsGateWithoutRedisTimeOrMysql(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [0, 0],
            new RuntimeException('Redis TIME must not be called in database mode'),
        );
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');

        $result = (new LoginCodeReadinessGate($redis, $db, new LoginCodeSettings(), 7))->check();

        $this->assertFalse($result->required);
        $this->assertTrue($result->ready);
        $this->assertSame(LoginCodeReadinessResult::SKIPPED, $result->reason);
        $this->assertSame(0, $redis->timeCalls);
    }

    public function testDatabaseReadDualWriteRequiresRedisReadiness(): void
    {
        $redis = new ControlledLoginCodeRedisClient(null, -2, $this->currentRedisTime());
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_DATABASE,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            7,
        ))->check();

        $this->assertTrue($result->required);
        $this->assertTrue($result->ready);
        $this->assertSame(LoginCodeReadinessResult::READY, $result->reason);
        $this->assertSame(1, $redis->timeCalls);
    }

    public function testRedisModeIsReadyWhenRedisAndAppClocksAreWithinOneSecond(): void
    {
        $redis = new ControlledLoginCodeRedisClient(null, -2, $this->currentRedisTime());
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');

        $result = $this->redisGate($redis, $db)->check();

        $this->assertTrue($result->required);
        $this->assertTrue($result->ready);
        $this->assertSame(LoginCodeReadinessResult::READY, $result->reason);
        $this->assertSame(1, $redis->timeCalls);
        $this->assertSame([
            'status' => 'up',
            'required' => true,
            'protocol' => 'login-code-v1',
            'protocol_fingerprint' => LoginCodeSettings::defaultProtocolFingerprint(),
            'redis_database' => 7,
            'active_window_seconds' => 60,
            'record_retention_seconds' => 300,
            'issue_window_seconds' => 60,
            'issue_limit' => 5,
            'limiter' => 'redis-zset-sliding-window',
            'clock_sync' => 'within_1s',
        ], $result->toHealthDetail());
    }

    public function testRedisModeFailsClosedWhenAppAndRedisClocksDifferByMoreThanOneSecond(): void
    {
        $redisNowMilliseconds = 1_780_000_000_000;
        $redis = new ControlledLoginCodeRedisClient(null, -2, [1_780_000_000, 0]);
        $db = $this->createMock(ConnectionInterface::class);

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            7,
            static fn (): int => $redisNowMilliseconds + 1_001,
        ))->check();

        $this->assertFalse($result->ready);
        $this->assertSame(LoginCodeReadinessResult::APP_CLOCK_SKEW, $result->reason);
    }

    public function testRedisModeAcceptsAnAppClockExactlyOneSecondFromRedisTime(): void
    {
        $redisNowMilliseconds = 1_780_000_000_000;
        $redis = new ControlledLoginCodeRedisClient(null, -2, [1_780_000_000, 0]);
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            7,
            static fn (): int => $redisNowMilliseconds - 1_000,
        ))->check();

        $this->assertTrue($result->required);
        $this->assertTrue($result->ready);
        $this->assertSame(LoginCodeReadinessResult::READY, $result->reason);
    }

    public function testRedisModeFailsClosedWhenRedisTimeIsUnavailable(): void
    {
        $redis = new ControlledLoginCodeRedisClient(
            null,
            -2,
            [0, 0],
            new RuntimeException('TIME connection error'),
        );
        $db = $this->createMock(ConnectionInterface::class);

        $result = $this->redisGate($redis, $db)->check();

        $this->assertFalse($result->ready);
        $this->assertSame(LoginCodeReadinessResult::REDIS_TIME_UNAVAILABLE, $result->reason);
    }

    public function testRedisFirstModeRequiresMysqlUtcAndRedisToBeWithinOneSecond(): void
    {
        [$seconds, $microseconds] = $this->currentRedisTime();
        $redis = new ControlledLoginCodeRedisClient(null, -2, [$seconds, $microseconds]);
        $db = $this->mysqlConnection((string) (($seconds * 1_000_000) + $microseconds));

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            7,
        ))->check();

        $this->assertTrue($result->ready);
        $this->assertSame(LoginCodeReadinessResult::READY, $result->reason);
        $this->assertSame(3, $redis->timeCalls);
    }

    public function testRedisFirstModeFailsClosedWhenMysqlUtcClockIsSkewed(): void
    {
        $redis = new ControlledLoginCodeRedisClient(null, -2, $this->currentRedisTime());
        $db = $this->mysqlConnection('0');

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            7,
        ))->check();

        $this->assertFalse($result->ready);
        $this->assertSame(LoginCodeReadinessResult::MYSQL_CLOCK_SKEW, $result->reason);
    }

    public function testRedisFirstModeFailsClosedWhenMysqlUtcCannotBeRead(): void
    {
        $redis = new ControlledLoginCodeRedisClient(null, -2, $this->currentRedisTime());
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willThrowException(new RuntimeException('DB error'));

        $result = (new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS_FIRST,
                writeMode: LoginCodeSettings::WRITE_DUAL,
            ),
            7,
        ))->check();

        $this->assertFalse($result->ready);
        $this->assertSame(LoginCodeReadinessResult::MYSQL_TIME_UNAVAILABLE, $result->reason);
    }

    private function redisGate(ControlledLoginCodeRedisClient $redis, ConnectionInterface $db): LoginCodeReadinessGate
    {
        return new LoginCodeReadinessGate(
            $redis,
            $db,
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            7,
        );
    }

    private function mysqlConnection(string $utcMicroseconds): ConnectionInterface
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryScalar')->willReturn($utcMicroseconds);

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('createCommand')
            ->with("SELECT TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6))")
            ->willReturn($command);

        return $db;
    }

    /** @return array{int, int} */
    private function currentRedisTime(): array
    {
        $now = microtime(true);
        $seconds = (int) floor($now);

        return [$seconds, (int) floor(($now - $seconds) * 1_000_000)];
    }
}
