<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Service\LoginCodeSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LoginCodeParamsTest extends TestCase
{
    public function testInvalidLoginCodeIntegerStopsParameterLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_ACTIVE_WINDOW_SECONDS must be an integer.');

        $this->loadParams(['LOGIN_CODE_ACTIVE_WINDOW_SECONDS' => '60abc']);
    }

    public function testInvalidLoginCodeBooleanStopsParameterLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LOGIN_CODE_LEGACY_DB_AVAILABLE must be a boolean.');

        $this->loadParams(['LOGIN_CODE_LEGACY_DB_AVAILABLE' => 'sometimes']);
    }

    public function testInvalidRedisDatabaseStopsParameterLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_DB must be an integer.');

        $this->loadParams(['REDIS_DB' => '7junk']);
    }

    public function testStrictEnvironmentFormsAreConvertedWithoutLossyCoercion(): void
    {
        $params = $this->loadParams([
            'REDIS_PORT' => ' 6380 ',
            'REDIS_DB' => ' 7 ',
            'LOGIN_CODE_ACTIVE_WINDOW_SECONDS' => ' 60 ',
            'LOGIN_CODE_RECORD_TTL_SECONDS' => '300',
            'LOGIN_CODE_ISSUE_LIMIT' => '5',
            'LOGIN_CODE_ISSUE_WINDOW_SECONDS' => '60',
            'LOGIN_CODE_LEGACY_DB_AVAILABLE' => 'On',
        ]);

        $this->assertSame(6380, $params['redis']['port']);
        $this->assertSame(7, $params['redis']['database']);
        $this->assertSame(60, $params['loginCode']['activeWindowSeconds']);
        $this->assertSame(300, $params['loginCode']['recordRetentionSeconds']);
        $this->assertSame(5, $params['loginCode']['issueLimit']);
        $this->assertSame(60, $params['loginCode']['issueWindowSeconds']);
        $this->assertTrue($params['loginCode']['legacyDbAvailable']);
        $this->assertSame(LoginCodeSettings::defaultProtocolFingerprint(), $params['loginCode']['protocolFingerprint']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function loadParams(array $overrides): array
    {
        $previousEnvironment = $_ENV;

        try {
            $_ENV = array_merge($_ENV, $overrides);

            /** @var array<string, mixed> $params */
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';

            return $params;
        } finally {
            $_ENV = $previousEnvironment;
        }
    }
}
