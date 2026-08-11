<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LoginCodeSettings;
use App\Service\LoginCodeStore;
use App\Service\LoginCodeTelemetry;
use App\Tests\Support\ControlledLoginCodeRedisClient;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StaticLoginCodeReadiness;
use PHPUnit\Framework\TestCase;

final class LoginCodeTelemetryTest extends TestCase
{
    private const REDIS_NOW = 1_780_000_000;

    public function testItEmitsOnlyFixedLowCardinalityDimensions(): void
    {
        $logger = new RecordingLogger();
        $telemetry = new LoginCodeTelemetry($logger);

        $telemetry->record('redis_hit', LoginCodeTelemetry::SOURCE_YII3_REFRESH);

        $this->assertSame([[
            'level' => 'info',
            'message' => 'Login-code protocol event.',
            'context' => [
                'event' => 'redis_hit',
                'source' => 'yii3-refresh',
            ],
        ]], $logger->records);
        $this->assertSensitiveValuesAndLabelsAreAbsent(
            $logger->records,
            'code-secret-' . str_repeat('a', 48),
            hash('sha256', 'code-secret-' . str_repeat('a', 48)),
            424242,
            'token-secret-' . str_repeat('b', 48),
        );
    }

    public function testItRejectsUntrustedDimensionsWithoutLoggingTheirValues(): void
    {
        $rawCode = 'code-secret-' . str_repeat('c', 48);
        $digest = hash('sha256', $rawCode);
        $logger = new RecordingLogger();
        $telemetry = new LoginCodeTelemetry($logger);

        $telemetry->record('redis_hit:' . $rawCode, 'yii3-refresh:' . $digest);

        $this->assertSame([[
            'level' => 'warning',
            'message' => 'Rejected invalid login-code telemetry dimensions.',
            'context' => [],
        ]], $logger->records);
        $this->assertSensitiveValuesAndLabelsAreAbsent(
            $logger->records,
            $rawCode,
            $digest,
            424242,
            'token-secret-' . str_repeat('d', 48),
        );
    }

    public function testStoreEmitsRedactedFixedEventsForAllThreeConsumers(): void
    {
        $rawCode = 'code-secret-' . str_repeat('e', 48);
        $digest = hash('sha256', $rawCode);
        $userId = 424242;
        $logger = new RecordingLogger();
        $store = new LoginCodeStore(
            new ControlledLoginCodeRedisClient(
                $this->recordPayload($userId),
                240_001,
                [self::REDIS_NOW, 0],
            ),
            new LoginCodeSettings(
                readMode: LoginCodeSettings::READ_REDIS,
                writeMode: LoginCodeSettings::WRITE_REDIS,
            ),
            StaticLoginCodeReadiness::ready(),
            new LoginCodeTelemetry($logger),
        );

        $store->resolve($rawCode);
        $store->resolveForKeyToToken($rawCode);
        $store->resolveForContext($rawCode);

        $this->assertSame([
            ['event' => 'redis_hit', 'source' => 'yii3-refresh'],
            ['event' => 'active', 'source' => 'yii3-refresh'],
            ['event' => 'redis_hit', 'source' => 'yii3-key-to-token'],
            ['event' => 'active', 'source' => 'yii3-key-to-token'],
            ['event' => 'redis_hit', 'source' => 'yii3-login-code-context'],
            ['event' => 'active', 'source' => 'yii3-login-code-context'],
        ], array_column($logger->records, 'context'));
        $this->assertSensitiveValuesAndLabelsAreAbsent(
            $logger->records,
            $rawCode,
            $digest,
            $userId,
            'token-secret-' . str_repeat('f', 48),
        );
    }

    /**
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function assertSensitiveValuesAndLabelsAreAbsent(
        array $records,
        string $rawCode,
        string $digest,
        int $userId,
        string $token,
    ): void {
        $serialized = json_encode($records, JSON_THROW_ON_ERROR);
        foreach ([$rawCode, $digest, (string) $userId, $token] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }

        foreach ($records as $record) {
            $this->assertTrue(
                $record['context'] === [] || array_keys($record['context']) === ['event', 'source'],
            );
            foreach (['code', 'digest', 'user', 'user_id', 'token', 'access_token', 'refresh_token'] as $forbiddenLabel) {
                $this->assertArrayNotHasKey($forbiddenLabel, $record['context']);
            }
        }
    }

    private function recordPayload(int $userId): string
    {
        return json_encode([
            'v' => 1,
            'user_id' => $userId,
            'issued_at' => self::REDIS_NOW,
            'expires_at' => self::REDIS_NOW + LoginCodeSettings::ACTIVE_WINDOW_SECONDS,
            'purpose' => 'web-device-login',
            'issuer' => 'main-api',
            'context' => new \stdClass(),
        ], JSON_THROW_ON_ERROR);
    }
}
