<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeviceSnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\Exception as DatabaseException;
use Yiisoft\Db\Transaction\TransactionInterface;

final class DeviceSnServiceTest extends TestCase
{
    private const SN = '0123ABCDEFGHJKMN';
    private array $queries = [];
    private array $writes = [];
    private int $commits = 0;
    private int $rollbacks = 0;

    public function testActivationLocksAndCommitsBindingWithExistingAuditShape(): void
    {
        $service = $this->service([$this->row(), $this->user()]);
        $result = $service->authenticate(self::SN, ' ROKID-123 ', true);

        $this->assertSame(['user_id' => 42, 'device_sn_id' => 7, 'nickname' => 'Test', 'username' => 'user42'], $result);
        $this->assertSame(1, $this->commits);
        $this->assertSame(0, $this->rollbacks);
        $this->assertCount(2, $this->writes);
        $this->assertStringContainsString('FOR UPDATE', $this->queries[0]['sql']);
        $this->assertSame([':digest' => hash('sha256', self::SN)], $this->queries[0]['parameters']);
        $this->assertSame('rokid-123', $this->writes[0]['parameters'][':uuid']);
        $this->assertSame('activate', $this->writes[1]['parameters'][':action']);
        $this->assertSame('device_sn/7', $this->writes[1]['parameters'][':resource']);
        $this->assertSame(['device_uuid' => 'rokid-123'], json_decode($this->writes[1]['parameters'][':context'], true));
        $this->assertStringNotContainsString(self::SN, json_encode([$this->queries, $this->writes]));
    }

    #[DataProvider('repeatModes')]
    public function testSamePairActivationAndLoginAreIdempotent(bool $activate): void
    {
        $row = $this->row();
        $row['device_uuid'] = 'rokid-123';
        $row['activated_at'] = '2026-09-29 00:00:00';
        $service = $this->service([$row, $this->user()]);

        $this->assertSame(7, $service->authenticate(self::SN, 'ROKID-123', $activate)['device_sn_id']);
        $this->assertCount(0, $this->writes);
        $this->assertSame(1, $this->commits);
    }

    public static function repeatModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('invalidBindingRows')]
    public function testInvalidBindingsNeverChangeDatabase(?array $row, bool $activate, int $status): void
    {
        $service = $this->service([$row, $this->user()]);
        try {
            $service->authenticate(self::SN, 'rokid-123', $activate);
            $this->fail('Invalid authorization must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($status, $exception->getCode());
        }
        $this->assertSame(0, $this->commits);
        $this->assertSame(1, $this->rollbacks);
        $this->assertSame([], $this->writes);
    }

    public static function invalidBindingRows(): array
    {
        $row = ['id' => 7, 'user_id' => 42, 'enabled' => 1, 'device_uuid' => null, 'activated_at' => null];
        return [
            [null, true, 401], [array_replace($row, ['user_id' => null]), true, 401],
            [array_replace($row, ['enabled' => 0]), true, 401], [$row, false, 409],
            [array_replace($row, ['device_uuid' => 'other-device', 'activated_at' => '2026-09-29']), true, 409],
            [array_replace($row, ['device_uuid' => 'rokid-123']), true, 409],
            [array_replace($row, ['activated_at' => '2026-09-29']), true, 409],
        ];
    }

    public function testAccountLookupRequiresActiveStatusAndExcludesEveryPrivilegedRole(): void
    {
        $service = $this->service([$this->row(), null]);
        try {
            $service->authenticate(self::SN, 'rokid-123', true);
            $this->fail('Ineligible account must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(401, $exception->getCode());
        }
        $sql = $this->queries[1]['sql'];
        $this->assertStringContainsString('`u`.`status` = 10', $sql);
        $this->assertStringContainsString("IN ('root', 'admin', 'manager')", $sql);
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertSame([], $this->writes);
    }

    #[DataProvider('databaseFailures')]
    public function testDriverFailureRollsBackAndDoesNotExposeSql(int $mysqlCode, int $expectedStatus): void
    {
        $failure = new DatabaseException('secret SQL SN 0123ABCDEFGHJKMN', ['23000', $mysqlCode]);
        $service = $this->service([$this->row(), $this->user()], $failure);

        try {
            $service->authenticate(self::SN, 'rokid-123', true);
            $this->fail('Database failure must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedStatus, $exception->getCode());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(1, $this->rollbacks);
        $this->assertSame(0, $this->commits);
    }

    public static function databaseFailures(): array
    {
        return [[1062, 409], [1205, 409], [1213, 409], [2006, 503], [1146, 503]];
    }

    public function testMissingRevokedDeletedDisabledOrPromotedAccountCannotUseExistingAccessToken(): void
    {
        $service = $this->service([null]);
        try {
            $service->authorizeSession(7, 42, false);
            $this->fail('Invalid session must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(401, $exception->getCode());
        }
        $sql = $this->queries[0]['sql'];
        $this->assertStringContainsString('INNER JOIN `user`', $sql);
        $this->assertStringContainsString('`sn`.`user_id` = :user', $sql);
        $this->assertStringContainsString('`u`.`status` = 10', $sql);
        $this->assertStringContainsString("IN ('root', 'admin', 'manager')", $sql);
        $this->assertStringNotContainsString('`sn`.`enabled` = 1', $sql);
    }

    public function testRefreshRequiresEnabledButExistingAccessMayExpireNaturally(): void
    {
        $service = $this->service([['device_uuid' => 'rokid-123'], ['device_uuid' => 'rokid-123']]);
        $service->authorizeSession(7, 42, true);
        $service->authorizeSession(7, 42, false);

        $this->assertStringContainsString('`sn`.`enabled` = 1', $this->queries[0]['sql']);
        $this->assertStringNotContainsString('`sn`.`enabled` = 1', $this->queries[1]['sql']);
    }

    public function testSessionRejectsDamagedUuid(): void
    {
        $this->expectExceptionCode(401);
        $this->service([['device_uuid' => 'not a valid uuid']])->authorizeSession(7, 42);
    }

    public function testRecordLoginContainsNoCredential(): void
    {
        $service = $this->service([]);
        $service->recordLogin(7);
        $this->assertSame(7, $this->writes[0]['parameters'][':id']);
        $this->assertStringContainsString('`last_login_at`', $this->writes[0]['sql']);
        $this->assertSame(0, $this->commits);
    }

    private function row(): array
    {
        return ['id' => 7, 'user_id' => 42, 'enabled' => 1, 'device_uuid' => null, 'activated_at' => null];
    }

    private function user(): array
    {
        return ['id' => 42, 'username' => 'user42', 'nickname' => 'Test'];
    }

    private function service(array $rows, ?Throwable $writeFailure = null): DeviceSnService
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->method('isActive')->willReturn(true);
        $transaction->method('commit')->willReturnCallback(function (): void { $this->commits++; });
        $transaction->method('rollBack')->willReturnCallback(function (): void { $this->rollbacks++; });
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('beginTransaction')->willReturn($transaction);
        $db->method('createCommand')->willReturnCallback(function (?string $sql, array $params = []) use (&$rows, $writeFailure): CommandInterface {
            $this->assertSame([], $params, 'Secrets must not be passed into logged createCommand parameters.');
            $bound = [];
            $command = $this->createMock(CommandInterface::class);
            $command->method('bindParam')->willReturnCallback(function ($name, &$value) use (&$bound, $command): CommandInterface {
                $bound[$name] = &$value;
                return $command;
            });
            $command->method('queryOne')->willReturnCallback(function () use ($sql, &$bound, &$rows): ?array {
                $this->queries[] = ['sql' => $sql, 'parameters' => $bound];
                return array_shift($rows);
            });
            $command->method('execute')->willReturnCallback(function () use ($sql, &$bound, $writeFailure): int {
                $this->writes[] = ['sql' => $sql, 'parameters' => $bound];
                if ($writeFailure !== null) {
                    throw $writeFailure;
                }
                return 1;
            });
            return $command;
        });

        return new DeviceSnService($db);
    }
}
