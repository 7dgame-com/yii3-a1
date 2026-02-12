<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Model\Meta;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for Meta data upgrade idempotency.
 *
 * Feature: yii2-to-yii3-migration, Property 16: Meta 数据升级幂等性
 *
 * **Validates: Requirements 8.5**
 *
 * Property 16: For any Meta data field, applying the data upgrade logic twice
 * should produce the same result as applying it once: f(x) = f(f(x)).
 *
 * The upgrade logic fixes legacy typos:
 * - 'transfrom' → 'transform' in parameters
 * - 'chieldren' → 'children'
 * Applied recursively through the data tree.
 */
final class MetaUpgradePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 16: Meta data upgrade is idempotent — f(x) = f(f(x)).
     *
     * Generates random data arrays with various combinations of typo fields
     * and verifies that applying performDataUpgrade twice yields the same result
     * as applying it once.
     *
     * **Validates: Requirements 8.5**
     *
     * @eris-repeat 100
     */
    public function testMetaDataUpgradeIsIdempotent(): void
    {
        $this->forAll(
            Generator\choose(0, 15),   // bitmask: which fields to include
            Generator\string(),        // extra field value
            Generator\choose(0, 5)     // nesting depth selector
        )
            ->then(function (int $fieldMask, string $extra, int $nestType): void {
                $data = $this->buildTestData($fieldMask, $extra, $nestType);

                $result1 = Meta::performDataUpgrade($data);
                $result2 = Meta::performDataUpgrade($result1);

                $this->assertSame(
                    $result1,
                    $result2,
                    sprintf(
                        "Meta data upgrade is not idempotent.\nInput: %s\nFirst: %s\nSecond: %s",
                        json_encode($data, JSON_UNESCAPED_UNICODE),
                        json_encode($result1, JSON_UNESCAPED_UNICODE),
                        json_encode($result2, JSON_UNESCAPED_UNICODE)
                    )
                );
            });
    }

    /**
     * Property 16b: Upgrade fixes 'transfrom' typo to 'transform'.
     *
     * **Validates: Requirements 8.5**
     *
     * @eris-repeat 100
     */
    public function testUpgradeFixesTransfromTypo(): void
    {
        $this->forAll(
            Generator\string()
        )
            ->then(function (string $value): void {
                $data = [
                    'parameters' => [
                        'transfrom' => $value,
                        'id' => 1,
                    ],
                ];

                $result = Meta::performDataUpgrade($data);

                $this->assertArrayNotHasKey(
                    'transfrom',
                    $result['parameters'],
                    'transfrom typo must be removed after upgrade'
                );
                $this->assertArrayHasKey(
                    'transform',
                    $result['parameters'],
                    'transform must exist after upgrade'
                );
                $this->assertSame($value, $result['parameters']['transform']);
            });
    }

    /**
     * Property 16c: Upgrade fixes 'chieldren' typo to 'children'.
     *
     * **Validates: Requirements 8.5**
     *
     * @eris-repeat 100
     */
    public function testUpgradeFixesChieldrenTypo(): void
    {
        $this->forAll(
            Generator\choose(1, 100)
        )
            ->then(function (int $id): void {
                $data = [
                    'chieldren' => [
                        'entities' => [
                            ['parameters' => ['id' => $id]],
                        ],
                    ],
                ];

                $result = Meta::performDataUpgrade($data);

                $this->assertArrayNotHasKey(
                    'chieldren',
                    $result,
                    'chieldren typo must be removed after upgrade'
                );
                $this->assertArrayHasKey(
                    'children',
                    $result,
                    'children must exist after upgrade'
                );
            });
    }

    /**
     * Property 16d: Extra fields are preserved through upgrade.
     *
     * **Validates: Requirements 8.5**
     *
     * @eris-repeat 100
     */
    public function testExtraFieldsPreservedThroughUpgrade(): void
    {
        $this->forAll(
            Generator\string(),
            Generator\int()
        )
            ->when(static fn (string $s, int $i): bool => $s !== '')
            ->then(function (string $extraValue, int $extraInt): void {
                $data = [
                    'custom_field' => $extraValue,
                    'numeric_field' => $extraInt,
                ];

                $result = Meta::performDataUpgrade($data);

                $this->assertSame($extraValue, $result['custom_field']);
                $this->assertSame($extraInt, $result['numeric_field']);
            });
    }

    /**
     * Build test data with various typo combinations.
     */
    private function buildTestData(int $fieldMask, string $extra, int $nestType): array
    {
        $data = [];

        // Bit 0: include 'transfrom' typo in parameters
        if ($fieldMask & 1) {
            $data['parameters'] = ['transfrom' => ['x' => 0], 'id' => 1];
        }

        // Bit 1: include 'chieldren' typo
        if ($fieldMask & 2) {
            $data['chieldren'] = ['entities' => []];
        }

        // Bit 2: include correct 'children' (no typo)
        if ($fieldMask & 4) {
            $data['children'] = ['modules' => []];
        }

        // Bit 3: include extra fields
        if ($fieldMask & 8) {
            $data['custom_label'] = $extra;
        }

        // Add nested children with typos based on nestType
        if ($nestType > 0 && $nestType <= 3) {
            $child = ['parameters' => ['transfrom' => 'test', 'id' => $nestType]];
            $data['children'] = $data['children'] ?? [];
            $data['children']['entities'] = [$child];
        }

        return $data;
    }
}
