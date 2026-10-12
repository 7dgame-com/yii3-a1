<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeviceSnRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use RuntimeException;

final class DeviceSnRateLimiterTest extends TestCase
{
    public function testSharesMainApiNamespacesLimitsAndCanonicalDigests(): void
    {
        $calls = [];
        $redis = $this->createMock(Client::class);
        $redis->method('__call')->willReturnCallback(function (string $method, array $arguments) use (&$calls): array {
            $this->assertSame('eval', $method);
            $calls[] = $arguments;
            return [1, $arguments[4] - 1, time() + 60, 60];
        });
        (new DeviceSnRateLimiter($redis))->consume('192.0.2.1', '0123-abcd-efgh-jkmn', 'ROKID-123');

        $this->assertCount(3, $calls);
        foreach (['ip' => '192.0.2.1', 'sn' => '0123ABCDEFGHJKMN', 'uuid' => 'rokid-123'] as $strategy => $value) {
            $call = array_shift($calls);
            $this->assertSame('device-sn:rate:' . $strategy . ':' . hash('sha256', $value), $call[2]);
            $this->assertSame(60000, $call[3]);
            $this->assertSame($strategy === 'ip' ? 600 : 30, $call[4]);
            $this->assertStringContainsString("redis.call('TIME')", $call[0]);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $call[5]);
        }
    }

    public function testDeniedIpStopsBeforeCredentialsAndProvidesRetryAfter(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->once())->method('__call')->willReturn([0, 0, time() + 17, 17]);
        $limiter = new DeviceSnRateLimiter($redis);
        try {
            $limiter->consume('192.0.2.1', '0123ABCDEFGHJKMN', 'rokid-123');
            $this->fail('Limit must deny access.');
        } catch (RuntimeException $exception) {
            $this->assertSame(429, $exception->getCode());
        }
        $this->assertSame(17, $limiter->retryAfterSeconds());
    }

    public function testRedisOutageIsRedactedAndFailsClosed(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->method('__call')->willThrowException(new RuntimeException('secret connection data'));
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('Device login is temporarily unavailable.');
        (new DeviceSnRateLimiter($redis))->consumeIp('192.0.2.1');
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidRedisReplyFailsClosed(mixed $response): void
    {
        $redis = $this->createMock(Client::class);
        $redis->method('__call')->willReturn($response);
        $this->expectExceptionCode(503);
        (new DeviceSnRateLimiter($redis))->consumeIp('192.0.2.1');
    }

    public static function invalidResponses(): array
    {
        return [[null], [[]], [[1, 599, 12]], [[2, 0, 12, 10]], [[0, 1, 12, 10]], [[1, 600, 12, 10]],
            [[1, 599, 0, 10]], [[1, 599, 12, 0]], [[1, 599, 12, 61]], [['bad', 599, 12, 1]],
            [[true, 599, 12, 1]], [['+1', 599, 12, 1]], [[1, 599, 12, '1.0']]];
    }
}
