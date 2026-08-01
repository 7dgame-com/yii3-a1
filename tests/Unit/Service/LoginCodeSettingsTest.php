<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LoginCodeSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LoginCodeSettingsTest extends TestCase
{
    public function testDefaultsPreserveDatabaseOnlyCompatibility(): void
    {
        $settings = new LoginCodeSettings();

        $this->assertSame(LoginCodeSettings::READ_DATABASE, $settings->readMode());
        $this->assertSame(LoginCodeSettings::WRITE_DATABASE, $settings->writeMode());
        $this->assertSame('auth:login-code:v1', $settings->prefix());
        $this->assertTrue($settings->legacyDbAvailable());
        $this->assertTrue($settings->isDatabaseRead());
        $this->assertSame('+08:00', LoginCodeSettings::LEGACY_DB_TIME_ZONE);
        $this->assertSame(LoginCodeSettings::defaultProtocolFingerprint(), $settings->protocolFingerprint());
    }

    public function testProtocolWindowsCannotDriftByConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LoginCodeSettings(activeWindowSeconds: 61);
    }

    public function testUnsupportedReadWritePairIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_DATABASE,
        );
    }

    public function testLegacyDbDisabledOnlyPermitsFinalRedisModes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_DATABASE,
            legacyDbAvailable: false,
        );
    }

    public function testFinalRedisModesAllowLegacyDbToBeDisabled(): void
    {
        $settings = new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
            legacyDbAvailable: false,
        );

        $this->assertTrue($settings->isRedisRead());
        $this->assertFalse($settings->legacyDbAvailable());
    }

    public function testRejectsIntegerWithGarbageSuffixInsteadOfCoercingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_ACTIVE_WINDOW_SECONDS must be an integer.');

        new LoginCodeSettings(activeWindowSeconds: '60abc');
    }

    public function testRejectsInvalidBooleanInsteadOfTreatingItAsFalse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_LEGACY_DB_AVAILABLE must be a boolean.');

        new LoginCodeSettings(legacyDbAvailable: 'sometimes');
    }

    public function testAcceptsTheSameStrictStringFormsAsMainApiSettings(): void
    {
        $settings = new LoginCodeSettings(
            activeWindowSeconds: ' 60 ',
            recordRetentionSeconds: '300',
            issueLimit: ' 5 ',
            issueWindowSeconds: '60',
            legacyDbAvailable: 'yes',
        );

        $this->assertSame(5, $settings->issueLimit());
        $this->assertTrue($settings->legacyDbAvailable());
    }

    public function testRedisModeRejectsFingerprintForAnotherPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_PROTOCOL_FINGERPRINT does not match');

        new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
            prefix: 'test:login-code:v1',
            expectedProtocolFingerprint: LoginCodeSettings::defaultProtocolFingerprint(),
        );
    }

    public function testRedisModeRejectsAnExplicitlyEmptyDeploymentFingerprint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_PROTOCOL_FINGERPRINT must not be empty');

        new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
            expectedProtocolFingerprint: '',
        );
    }

    public function testDatabaseOnlyModeAllowsAnExplicitlyEmptyDeploymentFingerprint(): void
    {
        $settings = new LoginCodeSettings(expectedProtocolFingerprint: '');

        $this->assertTrue($settings->isDatabaseRead());
        $this->assertSame(LoginCodeSettings::defaultProtocolFingerprint(), $settings->protocolFingerprint());
    }

    public function testRedisModeAllowsAnOmittedDeploymentFingerprintForDirectConstructorTests(): void
    {
        $settings = new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
        );

        $this->assertSame(LoginCodeSettings::defaultProtocolFingerprint(), $settings->protocolFingerprint());
    }

    public function testRedisModeAcceptsExactSharedFingerprint(): void
    {
        $fingerprint = LoginCodeSettings::protocolFingerprintFor('test:login-code:v1');
        $settings = new LoginCodeSettings(
            readMode: LoginCodeSettings::READ_REDIS,
            writeMode: LoginCodeSettings::WRITE_REDIS,
            prefix: 'test:login-code:v1',
            expectedProtocolFingerprint: $fingerprint,
        );

        $this->assertSame($fingerprint, $settings->protocolFingerprint());
    }
}
