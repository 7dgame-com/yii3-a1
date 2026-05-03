<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SnapshotDiagnosticsService;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Unit tests for SnapshotDiagnosticsService.
 *
 * Validates the diagnostic report used by /debug/snapshot:
 * - compares database snapshot columns against Snapshot model public properties
 * - reports columns that would fail ActiveRecord hydration
 * - captures database errors without throwing from the diagnostic endpoint
 */
final class SnapshotDiagnosticsServiceTest extends TestCase
{
    public function testCollectReportsOkForCurrentProductionSnapshotSchema(): void
    {
        $db = $this->createDbMock([
            ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'verse_id', 'Type' => 'int', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'uuid', 'Type' => 'varchar(64)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'code', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'data', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'metas', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'resources', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'managers', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'created_by', 'Type' => 'int', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'created_at', 'Type' => 'timestamp', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'space', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
        ]);

        $service = new SnapshotDiagnosticsService($db);
        $report = $service->collect();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(8, $report['checks']['snapshot_table']['row_count']);
        $this->assertSame([], $report['checks']['snapshot_schema']['missing_model_properties']);
        $this->assertStringContainsString('No snapshot schema mismatch detected', $report['summary']['probable_causes'][0]);
    }

    public function testCollectReportsMissingModelPropertyForUnknownSnapshotColumn(): void
    {
        $db = $this->createDbMock([
            ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'verse_id', 'Type' => 'int', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'uuid', 'Type' => 'varchar(64)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'code', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'data', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'metas', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'resources', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'managers', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'created_by', 'Type' => 'int', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'created_at', 'Type' => 'timestamp', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'space', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'future_column', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
        ]);

        $service = new SnapshotDiagnosticsService($db);
        $report = $service->collect();

        $this->assertSame('error', $report['status']);
        $this->assertContains('future_column', $report['checks']['snapshot_schema']['missing_model_properties']);
        $this->assertStringContainsString('future_column', $report['summary']['probable_causes'][0]);
    }

    public function testCollectCapturesColumnReadFailure(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')
            ->willReturnCallback(function (string $sql): CommandInterface {
                $command = $this->createMock(CommandInterface::class);

                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    $command->method('queryAll')->willThrowException(new \RuntimeException('table is missing'));
                }

                return $command;
            });

        $service = new SnapshotDiagnosticsService($db);
        $report = $service->collect();

        $this->assertSame('error', $report['status']);
        $this->assertSame('error', $report['checks']['snapshot_schema']['status']);
        $this->assertSame('table is missing', $report['checks']['snapshot_schema']['error']['message']);
    }

    private function createDbMock(array $columns): ConnectionInterface
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')
            ->willReturnCallback(function (string $sql) use ($columns): CommandInterface {
                $command = $this->createMock(CommandInterface::class);

                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    $command->method('queryAll')->willReturn($columns);
                    return $command;
                }

                if (str_starts_with($sql, 'SELECT COUNT(*)')) {
                    $command->method('queryScalar')->willReturn(8);
                    return $command;
                }

                throw new \RuntimeException('Unexpected SQL: ' . $sql);
            });

        return $db;
    }
}
