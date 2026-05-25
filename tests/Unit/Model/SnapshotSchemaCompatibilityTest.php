<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Snapshot;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Regression tests for production snapshot table compatibility.
 *
 * Yii3 ActiveRecord hydrates database rows into declared public properties.
 * A1/Yii2 tolerates production columns dynamically, but Y1/Yii3 must declare
 * them explicitly to avoid HTTP 500 when snapshot rows are returned.
 */
final class SnapshotSchemaCompatibilityTest extends TestCase
{
    private const PRODUCTION_SNAPSHOT_COLUMNS = [
        'id',
        'verse_id',
        'uuid',
        'code',
        'data',
        'metas',
        'resources',
        'created_at',
        'created_by',
        'managers',
        'space',
    ];

    private const JSON_LIKE_COLUMNS = [
        'data',
        'metas',
        'resources',
        'managers',
        'space',
    ];

    public function testSnapshotDeclaresEveryProductionColumnAsPublicProperty(): void
    {
        $reflection = new ReflectionClass(Snapshot::class);

        foreach (self::PRODUCTION_SNAPSHOT_COLUMNS as $column) {
            $this->assertTrue(
                $reflection->hasProperty($column),
                "Snapshot must declare public property for production column {$column}.",
            );

            $property = $reflection->getProperty($column);
            $this->assertTrue(
                $property->isPublic(),
                "Snapshot property {$column} must be public for ActiveRecord hydration.",
            );
        }
    }

    public function testJsonLikeSnapshotColumnsAcceptDriverJsonRepresentations(): void
    {
        $reflection = new ReflectionClass(Snapshot::class);

        foreach (self::JSON_LIKE_COLUMNS as $column) {
            $property = $reflection->getProperty($column);

            foreach (['string', 'array', 'object', 'null'] as $typeName) {
                $this->assertTrue(
                    $this->propertyAllowsType($property, $typeName),
                    "Snapshot property {$column} must accept {$typeName} values returned by MySQL JSON hydration.",
                );
            }
        }
    }

    public function testJsonSnapshotColumnsAreDecodedWhenExpanded(): void
    {
        $snapshot = new Snapshot();
        $snapshot->metas = '[{"id":730,"type":"entity"}]';
        $snapshot->resources = '[{"id":333,"type":"polygen"}]';
        $snapshot->managers = '[{"id":1}]';
        $snapshot->space = '{"type":"immersal"}';

        $reflection = new ReflectionClass($snapshot);
        $extraFieldsMap = $reflection->getMethod('getExtraFieldsMap')->invoke($snapshot);

        $this->assertSame([], $snapshot->jsonSerialize());
        foreach (['metas', 'resources', 'managers', 'space'] as $field) {
            $this->assertArrayHasKey($field, $extraFieldsMap);
        }

        $this->assertSame(
            [
                'metas' => [['id' => 730, 'type' => 'entity']],
                'resources' => [['id' => 333, 'type' => 'polygen']],
                'managers' => [['id' => 1]],
                'space' => ['type' => 'immersal'],
            ],
            $snapshot->toExpandedArray(['metas', 'resources', 'managers', 'space']),
        );
    }

    public function testExpandedSnapshotOutputUsesA1ExtraFieldOrderAndReturnsSpace(): void
    {
        $snapshot = new Snapshot();
        $snapshot->id = 3;
        $snapshot->uuid = '88f275bf-9425-3240-8309-ecbc6a535041';
        $snapshot->data = '{"type":"Verse"}';
        $snapshot->metas = '[]';
        $snapshot->resources = '[]';
        $snapshot->space = '{"type":"immersal"}';

        $expanded = $snapshot->toExpandedArray(['id', 'name', 'data', 'metas', 'resources', 'uuid', 'image', 'space']);

        $this->assertSame(
            ['id', 'name', 'image', 'uuid', 'data', 'metas', 'resources', 'space'],
            array_keys($expanded),
        );
        $this->assertSame(['type' => 'immersal'], $expanded['space']);
    }

    public function testInvalidJsonSnapshotColumnsRemainUnchangedWhenExpanded(): void
    {
        $snapshot = new Snapshot();
        $snapshot->metas = 'not-json';

        $this->assertSame(
            ['metas' => 'not-json'],
            $snapshot->toExpandedArray(['metas']),
        );
    }

    private function propertyAllowsType(ReflectionProperty $property, string $typeName): bool
    {
        $type = $property->getType();
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($typeName === 'null') {
                return $type->allowsNull() || $type->getName() === 'mixed';
            }

            return $type->getName() === $typeName || $type->getName() === 'mixed';
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($typeName === 'null' && $unionType->getName() === 'null') {
                    return true;
                }

                if ($unionType->getName() === $typeName || $unionType->getName() === 'mixed') {
                    return true;
                }
            }
        }

        return false;
    }
}
