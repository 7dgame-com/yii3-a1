<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UnityDevLoginFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnityDevLoginFixtureTest extends TestCase
{
    private const RAW_KEY = 'unity-unit-test-key-000000000000000000000001';

    public function testEnabledDevFixtureMatchesOnlyConfiguredRawKey(): void
    {
        $fixture = new UnityDevLoginFixture(
            enabled: true,
            environment: 'dev',
            keySha256: hash('sha256', self::RAW_KEY),
        );

        $this->assertTrue($fixture->matches(self::RAW_KEY));
        $this->assertFalse($fixture->matches(self::RAW_KEY . '-wrong'));
        $this->assertSame(UnityDevLoginFixture::USER_ID, $fixture->userPayload()['id']);
        $this->assertTrue($fixture->userPayload()['fixture']);
        $this->assertSame('d.dev.xrugc.com', $fixture->frontendDomain());
    }

    public function testDisabledFixtureNeverMatches(): void
    {
        $fixture = new UnityDevLoginFixture(
            enabled: false,
            environment: 'production',
            keySha256: hash('sha256', self::RAW_KEY),
        );

        $this->assertFalse($fixture->matches(self::RAW_KEY));
    }

    public function testEnabledFixtureIsRejectedOutsideDevEnvironment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only be enabled when YII_ENV=dev');

        new UnityDevLoginFixture(
            enabled: true,
            environment: 'production',
        );
    }

    public function testEnabledFixtureRejectsInvalidDigestAndDomain(): void
    {
        try {
            new UnityDevLoginFixture(
                enabled: true,
                environment: 'dev',
                keySha256: 'not-a-digest',
            );
            $this->fail('Expected invalid digest rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('SHA-256', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid lowercase hostname');
        new UnityDevLoginFixture(
            enabled: true,
            environment: 'dev',
            frontendDomain: 'https://d.dev.xrugc.com/path',
        );
    }
}
