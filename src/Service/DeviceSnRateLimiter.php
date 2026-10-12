<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use RuntimeException;
use Throwable;

/** Shared main-API/y1 sliding windows; Redis time avoids differences between API clocks. */
class DeviceSnRateLimiter
{
    private const CONSUME_LUA = <<<'LUA'
local now = redis.call('TIME')
local now_ms = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
local window_ms = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local nonce = ARGV[3]
local cutoff = now_ms - window_ms

redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', cutoff)
local count = tonumber(redis.call('ZCARD', KEYS[1]))
local allowed = 0

if count < limit then
    local member = tostring(now_ms) .. ':' .. nonce
    redis.call('ZADD', KEYS[1], now_ms, member)
    count = count + 1
    allowed = 1
end

if allowed == 1 then
    redis.call('PEXPIRE', KEYS[1], window_ms)
end

local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
local reset_ms = now_ms + window_ms
if oldest[2] ~= nil then
    reset_ms = tonumber(oldest[2]) + window_ms
end

local remaining = limit - count
if remaining < 0 then
    remaining = 0
end

local retry_after = math.ceil((reset_ms - now_ms) / 1000)
if retry_after < 1 then
    retry_after = 1
end

return {allowed, remaining, math.ceil(reset_ms / 1000), retry_after}
LUA;

    private int $retryAfter = 1;

    public function __construct(private readonly Client $redis)
    {
    }

    public function consume(string $ip, string $normalizedSn, string $normalizedUuid): void
    {
        $this->consumeIp($ip);
        $this->consumeCredentials($normalizedSn, $normalizedUuid);
    }

    /** Call before parsing untrusted credentials so malformed attempts also consume IP allowance. */
    public function consumeIp(string $ip): void
    {
        $this->consumeWindow('ip', $ip, 600);
    }

    public function consumeCredentials(string $normalizedSn, string $normalizedUuid): void
    {
        $this->consumeWindow('sn', DeviceSnCredential::normalize($normalizedSn), 30);
        $this->consumeWindow('uuid', DeviceSnService::normalizeUuid($normalizedUuid), 30);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfter;
    }

    private function consumeWindow(string $strategy, string $value, int $limit): void
    {
        try {
            $response = $this->redis->eval(
                self::CONSUME_LUA, 1, 'device-sn:rate:' . $strategy . ':' . hash('sha256', $value),
                60000, $limit, bin2hex(random_bytes(16)),
            );
            if (!is_array($response) || count($response) !== 4) {
                throw new RuntimeException('Invalid limiter response.');
            }

            $values = array_map(static function ($part): int|false {
                if (!is_int($part) && (!is_string($part) || preg_match('/^(?:0|[1-9]\d*)$/D', $part) !== 1)) {
                    return false;
                }
                return filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            }, $response);
            [$allowed, $remaining, $resetAt, $retryAfter] = $values;
            if (in_array(false, $values, true) || !in_array($allowed, [0, 1], true)
                || $remaining > $limit || ($allowed === 1 && $remaining >= $limit)
                || ($allowed === 0 && $remaining !== 0) || $resetAt < 1 || $retryAfter < 1 || $retryAfter > 60) {
                throw new RuntimeException('Invalid limiter response.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Device login is temporarily unavailable.', 503);
        }

        if ($allowed === 0) {
            $this->retryAfter = $retryAfter;
            throw new RuntimeException('Too many device login attempts.', 429);
        }
    }
}
