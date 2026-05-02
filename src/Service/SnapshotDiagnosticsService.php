<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Snapshot;
use ReflectionClass;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Builds a small diagnostic report for snapshot list failures.
 *
 * The report avoids returning business rows or credentials. It focuses on the
 * most common hydration failure class: database columns that are not declared
 * as public properties on the ActiveRecord model.
 */
final class SnapshotDiagnosticsService
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * Collect snapshot diagnostics.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $schemaCheck = $this->collectSnapshotSchemaCheck();
        $tableCheck = $this->collectSnapshotTableCheck();
        $status = $this->determineStatus($schemaCheck, $tableCheck);

        return [
            'status' => $status,
            'generated_at' => date('c'),
            'runtime' => [
                'php_version' => PHP_VERSION,
                'yii_debug' => (bool) ($_ENV['YII_DEBUG'] ?? false),
                'mysql_host_set' => $this->isEnvSet('MYSQL_HOST'),
                'mysql_db_set' => $this->isEnvSet('MYSQL_DB'),
            ],
            'summary' => $this->buildSummary($schemaCheck, $tableCheck),
            'checks' => [
                'snapshot_schema' => $schemaCheck,
                'snapshot_table' => $tableCheck,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSnapshotSchemaCheck(): array
    {
        try {
            $columns = $this->fetchSnapshotColumns();
            $columnNames = array_map(
                static fn(array $column): string => $column['name'],
                $columns,
            );
            $modelProperties = $this->getSnapshotModelProperties();

            $missingModelProperties = array_values(array_diff($columnNames, $modelProperties));
            $extraModelProperties = array_values(array_diff($modelProperties, $columnNames));

            return [
                'status' => empty($missingModelProperties) ? 'ok' : 'error',
                'table_columns' => $columnNames,
                'table_column_details' => $columns,
                'model_properties' => $modelProperties,
                'missing_model_properties' => $missingModelProperties,
                'extra_model_properties' => $extraModelProperties,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $this->formatThrowable($e),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSnapshotTableCheck(): array
    {
        try {
            $rowCount = $this->db
                ->createCommand('SELECT COUNT(*) FROM `snapshot`')
                ->queryScalar();

            return [
                'status' => 'ok',
                'row_count' => (int) $rowCount,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $this->formatThrowable($e),
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSnapshotColumns(): array
    {
        $rows = $this->db
            ->createCommand('SHOW COLUMNS FROM `snapshot`')
            ->queryAll();

        return array_map(
            static fn(array $row): array => [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'nullable' => (($row['Null'] ?? '') === 'YES'),
                'key' => (string) ($row['Key'] ?? ''),
                'default' => $row['Default'] ?? null,
                'extra' => (string) ($row['Extra'] ?? ''),
            ],
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function getSnapshotModelProperties(): array
    {
        $reflection = new ReflectionClass(Snapshot::class);
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($property->getDeclaringClass()->getName() !== Snapshot::class) {
                continue;
            }

            $properties[] = $property->getName();
        }

        sort($properties);

        return $properties;
    }

    /**
     * @param array<string, mixed> $schemaCheck
     * @param array<string, mixed> $tableCheck
     */
    private function determineStatus(array $schemaCheck, array $tableCheck): string
    {
        if (
            ($schemaCheck['status'] ?? 'error') === 'error'
            || ($tableCheck['status'] ?? 'error') === 'error'
            || !empty($schemaCheck['missing_model_properties'])
        ) {
            return 'error';
        }

        if (!empty($schemaCheck['extra_model_properties'])) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param array<string, mixed> $schemaCheck
     * @param array<string, mixed> $tableCheck
     * @return array<string, mixed>
     */
    private function buildSummary(array $schemaCheck, array $tableCheck): array
    {
        $probableCauses = [];
        $nextSteps = [];

        if (!empty($schemaCheck['missing_model_properties'])) {
            $missing = implode(', ', $schemaCheck['missing_model_properties']);
            $probableCauses[] = 'snapshot table has columns not declared on App\\Model\\Snapshot: ' . $missing;
            $nextSteps[] = 'Add matching public properties to App\\Model\\Snapshot or roll back the unmatched columns.';
        }

        if (($schemaCheck['status'] ?? null) === 'error' && isset($schemaCheck['error']['message'])) {
            $probableCauses[] = 'Unable to read snapshot table schema: ' . $schemaCheck['error']['message'];
            $nextSteps[] = 'Check the database connection and whether the snapshot table exists.';
        }

        if (($tableCheck['status'] ?? null) === 'error' && isset($tableCheck['error']['message'])) {
            $probableCauses[] = 'Unable to count snapshot rows: ' . $tableCheck['error']['message'];
            $nextSteps[] = 'Check table permissions and the snapshot table health.';
        }

        if ($probableCauses === []) {
            $probableCauses[] = 'No snapshot schema mismatch detected by this diagnostic check.';
            $nextSteps[] = 'If list endpoints still fail, inspect the application log for the captured exception stack.';
        }

        return [
            'probable_causes' => $probableCauses,
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatThrowable(\Throwable $e): array
    {
        return [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private function isEnvSet(string $name): bool
    {
        return isset($_ENV[$name]) && $_ENV[$name] !== '';
    }
}
