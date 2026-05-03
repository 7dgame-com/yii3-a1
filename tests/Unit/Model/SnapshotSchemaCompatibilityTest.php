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

    public function testSpaceColumnIsNotAnA1ExtraField(): void
    {
        $snapshot = new Snapshot();

        $this->assertSame([], $snapshot->jsonSerialize());
        $this->assertArrayNotHasKey('space', $snapshot->toFullArray());
        $this->assertSame([], $snapshot->toExpandedArray(['space']));
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
