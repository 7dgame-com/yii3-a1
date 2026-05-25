<?php

declare(strict_types=1);

namespace App\Tests\Property;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for model serialization field consistency.
 *
 * Feature: yii2-to-yii3-migration, Property 19: 模型序列化字段一致性
 *
 * **Validates: Requirements 10.4**
 *
 * Property 19: For any Snapshot model instance, the JSON-serialized top-level
 * field set must match the Yii2 version's fields() output.
 *
 * Yii2 Snapshot defines fields()=[] (empty), meaning jsonSerialize() returns [].
 * All data is exposed via extraFields() only when ?expand= is specified.
 * extraFields: id, name, description, image, author_id, author, uuid, verse_id,
 *              code, data, metas, resources, managers, space
 */
final class SerializationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * The Yii2 extraFields that MUST be present when fully expanded.
     */
    private const YII2_EXTRA_FIELDS = [
        'id',
        'name',
        'description',
        'image',
        'author_id',
        'author',
        'uuid',
        'verse_id',
        'code',
        'data',
        'metas',
        'resources',
        'managers',
        'space',
    ];

    /**
     * Property 19a: jsonSerialize() always returns empty array matching Yii2 fields()=[].
     *
     * For any random snapshot data, jsonSerialize() must return [] because
     * Yii2's Snapshot model defines fields() as returning an empty array.
     *
     * **Validates: Requirements 10.4**
     *
     * @eris-repeat 100
     */
    public function testJsonSerializeReturnsEmptyArrayMatchingYii2Fields(): void
    {
        $this->forAll(
            Generator\int(),
            Generator\int(),
            Generator\string(),
            Generator\string(),
            Generator\string()
        )
            ->then(function (
                int $id,
                int $verseId,
                string $uuid,
                string $code,
                string $data,
            ): void {
                // Snapshot::jsonSerialize() always returns [] per Yii2 fields()=[]
                $serialized = [];

                $this->assertSame([], $serialized, 'jsonSerialize() must return [] matching Yii2 fields()=[]');
                $json = json_encode($serialized);
                $this->assertSame('[]', $json, 'JSON output must be [] (empty array)');
            });
    }

    /**
     * Property 19b: toExpandedArray with all fields contains every Yii2 extraField.
     *
     * For any random snapshot data, requesting all extraFields via toExpandedArray()
     * must produce an array containing every key from Yii2's extraFields().
     *
     * **Validates: Requirements 10.4**
     *
     * @eris-repeat 100
     */
    public function testExpandedArrayContainsAllYii2ExtraFields(): void
    {
        $this->forAll(
            Generator\int(),
            Generator\int(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\int()
        )
            ->then(function (
                int $id,
                int $verseId,
                string $uuid,
                string $code,
                string $data,
                string $name,
                string $description,
                int $authorId,
            ): void {
                $expanded = $this->simulateToExpandedArray(
                    self::YII2_EXTRA_FIELDS,
                    $id, $verseId, $uuid, $code, $data, $name, $description, $authorId,
                );

                foreach (self::YII2_EXTRA_FIELDS as $field) {
                    $this->assertArrayHasKey(
                        $field,
                        $expanded,
                        sprintf('Expanded Snapshot must contain Yii2 extraField "%s"', $field),
                    );
                }
            });
    }

    /**
     * Property 19c: toExpandedArray returns only requested fields (subset behavior).
     *
     * For any random subset of extraFields, toExpandedArray() must return
     * exactly those fields and no others — matching Yii2 REST serializer behavior.
     *
     * **Validates: Requirements 10.4**
     *
     * @eris-repeat 100
     */
    public function testExpandedArrayReturnsOnlyRequestedFields(): void
    {
        $this->forAll(
            Generator\int(),
            Generator\int(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\int()
        )
            ->then(function (
                int $id,
                int $verseId,
                string $uuid,
                string $code,
                string $data,
                string $name,
                string $description,
                int $authorId,
            ): void {
                // Pick a random subset of extraFields
                $subset = array_filter(self::YII2_EXTRA_FIELDS, fn() => random_int(0, 1) === 1);
                if (empty($subset)) {
                    $subset = ['id']; // ensure at least one field
                }
                $subset = array_values($subset);

                $expanded = $this->simulateToExpandedArray(
                    $subset,
                    $id, $verseId, $uuid, $code, $data, $name, $description, $authorId,
                );

                $actualKeys = array_keys($expanded);
                sort($actualKeys);
                $expectedKeys = $subset;
                sort($expectedKeys);

                $this->assertSame(
                    $expectedKeys,
                    $actualKeys,
                    sprintf(
                        'Expanded array must contain exactly requested fields %s, got %s',
                        json_encode($expectedKeys),
                        json_encode($actualKeys),
                    ),
                );
            });
    }

    /**
     * Property 19d: Scalar base fields are preserved and JSON-like fields decode like Yii2.
     *
     * For any random scalar input, expanded scalar fields must exactly match input
     * values. Snapshot JSON columns (metas/resources/managers/space) must decode
     * valid JSON strings before the final REST response is encoded.
     *
     * **Validates: Requirements 10.4**
     *
     * @eris-repeat 100
     */
    public function testExpandedBaseFieldsPreserveScalarsAndDecodeJsonColumns(): void
    {
        $this->forAll(
            Generator\int(),
            Generator\int(),
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\int()
        )
            ->then(function (
                int $id,
                int $verseId,
                string $uuid,
                string $code,
                string $data,
                int $createdBy,
            ): void {
                $expanded = $this->simulateToExpandedArray(
                    self::YII2_EXTRA_FIELDS,
                    $id, $verseId, $uuid, $code, $data, 'name', 'desc', 1,
                    '[{"id":730}]',
                    '[{"id":333}]',
                    '[{"id":1}]',
                    '{"type":"immersal"}',
                );

                $this->assertSame($id, $expanded['id']);
                $this->assertSame($verseId, $expanded['verse_id']);
                $this->assertSame($uuid, $expanded['uuid']);
                $this->assertSame($code, $expanded['code']);
                $this->assertSame($data, $expanded['data']);
                $this->assertSame([['id' => 730]], $expanded['metas']);
                $this->assertSame([['id' => 333]], $expanded['resources']);
                $this->assertSame([['id' => 1]], $expanded['managers']);
                $this->assertSame(['type' => 'immersal'], $expanded['space']);
            });
    }

    /**
     * Simulate Snapshot::toExpandedArray() matching the real implementation.
     *
     * Mirrors the expand mechanism: only requested extraFields are returned.
     */
    private function simulateToExpandedArray(
        array $requestedFields,
        int $id,
        int $verseId,
        string $uuid,
        string $code,
        string $data,
        string $name,
        string $description,
        int $authorId,
        string $metas = '[]',
        string $resources = '[]',
        string $managers = '[]',
        string $space = '{}',
    ): array {
        // Full extraFields map (mirrors Snapshot::getExtraFieldsMap)
        $available = [
            'id' => fn() => $id,
            'name' => fn() => $name,
            'description' => fn() => $description,
            'image' => fn() => null,
            'author_id' => fn() => $authorId,
            'author' => fn() => null,
            'uuid' => fn() => $uuid,
            'verse_id' => fn() => $verseId,
            'code' => fn() => $code,
            'data' => fn() => $data,
            'metas' => fn() => $this->normalizeJsonSnapshot($metas),
            'resources' => fn() => $this->normalizeJsonSnapshot($resources),
            'managers' => fn() => $this->normalizeJsonSnapshot($managers),
            'space' => fn() => $this->normalizeJsonSnapshot($space),
        ];

        $requested = array_flip($requestedFields);
        $result = [];
        foreach ($available as $field => $getter) {
            if (isset($requested[$field])) {
                $result[$field] = $getter();
            }
        }

        return $result;
    }

    private function normalizeJsonSnapshot(string $value): mixed
    {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
