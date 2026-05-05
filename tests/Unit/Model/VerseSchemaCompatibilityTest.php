<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Verse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Regression tests for production verse table compatibility.
 */
final class VerseSchemaCompatibilityTest extends TestCase
{
    private const JSON_LIKE_COLUMNS = [
        'info',
        'data',
    ];

    public function testJsonLikeVerseColumnsAcceptDriverJsonRepresentations(): void
    {
        $reflection = new ReflectionClass(Verse::class);

        foreach (self::JSON_LIKE_COLUMNS as $column) {
            $property = $reflection->getProperty($column);

            foreach (['string', 'array', 'object', 'null'] as $typeName) {
                $this->assertTrue(
                    $this->propertyAllowsType($property, $typeName),
                    "Verse property {$column} must accept {$typeName} values returned by MySQL JSON hydration.",
                );
            }
        }
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
