<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeviceSnCredential;
use App\Service\DeviceSnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeviceSnCredentialTest extends TestCase
{
    public function testNormalizesOnlyTransportFormattingAndHashesCanonicalValue(): void
    {
        $value = " 0123-abcd\n-efgh-jkmn ";
        $this->assertSame('0123ABCDEFGHJKMN', DeviceSnCredential::normalize($value));
        $this->assertSame(hash('sha256', '0123ABCDEFGHJKMN'), DeviceSnCredential::digest($value));
    }

    #[DataProvider('invalidSnValues')]
    public function testRejectsInvalidOrLegacySn(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(400);
        DeviceSnCredential::normalize($value);
    }

    public static function invalidSnValues(): array
    {
        return [
            [''], [str_repeat('A', 15)], [str_repeat('A', 17)], [str_repeat('A', 32)],
            ['0123-ABCD-EFGH-JKMO'], ['0123-ABCD-EFGH-JKMI'], ['0123-ABCD-EFGH-JKML'],
            ['0123-ABCD-EFGH-JKMU'], ['0123-ABCD-EFGH-JKM.'], [str_repeat(' ', 129)],
        ];
    }

    public function testHardwareUuidIsCanonicalWithoutRequiringRfc4122(): void
    {
        $this->assertSame('rokid:alpha_01.dev-2', DeviceSnService::normalizeUuid(' ROKID:ALPHA_01.dev-2 '));
    }

    #[DataProvider('invalidUuidValues')]
    public function testRejectsInvalidUuid(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(400);
        DeviceSnService::normalizeUuid($value);
    }

    public static function invalidUuidValues(): array
    {
        return [[''], ['  '], ['.uuid'], ['uuid/a'], ['uuid a'], ['设备'], [str_repeat('a', 256)]];
    }
}
