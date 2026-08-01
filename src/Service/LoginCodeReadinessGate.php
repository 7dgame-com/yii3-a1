<?php

declare(strict_types=1);

namespace App\Service;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Verifies the time sources that make Redis login-code authorization safe.
 *
 * It is deliberately inactive only when neither reads nor writes use Redis.
 * Redis-backed read/write modes require app↔Redis skew <= 1 second;
 * redis-first additionally requires MySQL UTC↔Redis skew <= 1 second before
 * legacy fallback is allowed.
 */
final class LoginCodeReadinessGate implements LoginCodeReadiness
{
    private const MAX_CLOCK_SKEW_MILLISECONDS = 1_000;

    /** @var \Closure():int|null */
    private readonly ?\Closure $clock;

    public function __construct(
        private readonly LoginCodeRedisClient $redis,
        private readonly ConnectionInterface $db,
        private readonly LoginCodeSettings $settings,
        private readonly int $redisDatabase,
        ?callable $clock = null,
    ) {
        if ($this->redisDatabase < 0) {
            throw new \InvalidArgumentException('REDIS_DB must be a non-negative integer.');
        }

        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
    }

    public function check(): LoginCodeReadinessResult
    {
        if (!$this->settings->usesRedis()) {
            return LoginCodeReadinessResult::skipped();
        }

        // Use the app-clock midpoint around TIME so a normal command
        // round-trip does not look like clock skew.
        $appBeforeMilliseconds = $this->applicationTimeMilliseconds();
        $redisNowMilliseconds = $this->redisNowMilliseconds();
        $appAfterMilliseconds = $this->applicationTimeMilliseconds();
        if ($redisNowMilliseconds === null) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::REDIS_TIME_UNAVAILABLE);
        }

        $appNowMilliseconds = intdiv($appBeforeMilliseconds + $appAfterMilliseconds, 2);
        if (abs($appNowMilliseconds - $redisNowMilliseconds) > self::MAX_CLOCK_SKEW_MILLISECONDS) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::APP_CLOCK_SKEW);
        }

        if (!$this->settings->isRedisFirst()) {
            return LoginCodeReadinessResult::ready(
                $this->redisDatabase,
                $this->settings->issueLimit(),
                $this->settings->protocolFingerprint(),
            );
        }

        // Keep the Redis/MySQL comparison close to the DB query itself. The
        // midpoint accepts ordinary query round-trip latency but still bounds
        // a genuine clock disagreement at one second.
        $redisBeforeMysqlMilliseconds = $this->redisNowMilliseconds();
        if ($redisBeforeMysqlMilliseconds === null) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::REDIS_TIME_UNAVAILABLE);
        }

        $mysqlUtcMilliseconds = $this->mysqlUtcMilliseconds();
        if ($mysqlUtcMilliseconds === null) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::MYSQL_TIME_UNAVAILABLE);
        }

        // Read Redis TIME again after MySQL so the comparison is not widened by
        // the DB query's round-trip latency. Both values remain redacted.
        $redisAfterMysqlMilliseconds = $this->redisNowMilliseconds();
        if ($redisAfterMysqlMilliseconds === null) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::REDIS_TIME_UNAVAILABLE);
        }

        $redisForMysqlMilliseconds = intdiv(
            $redisBeforeMysqlMilliseconds + $redisAfterMysqlMilliseconds,
            2,
        );

        if (abs($mysqlUtcMilliseconds - $redisForMysqlMilliseconds) > self::MAX_CLOCK_SKEW_MILLISECONDS) {
            return LoginCodeReadinessResult::failed(LoginCodeReadinessResult::MYSQL_CLOCK_SKEW);
        }

        return LoginCodeReadinessResult::ready(
            $this->redisDatabase,
            $this->settings->issueLimit(),
            $this->settings->protocolFingerprint(),
        );
    }

    private function redisNowMilliseconds(): ?int
    {
        try {
            $time = $this->redis->time();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($time) || count($time) < 2) {
            return null;
        }

        $seconds = $this->parseInteger($time[0] ?? null);
        $microseconds = $this->parseInteger($time[1] ?? null);
        if ($seconds === null || $seconds < 0 || $microseconds === null || $microseconds < 0 || $microseconds >= 1_000_000) {
            return null;
        }

        return ($seconds * 1000) + intdiv($microseconds, 1000);
    }

    private function applicationTimeMilliseconds(): int
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return (int) round(microtime(true) * 1000);
    }

    private function mysqlUtcMilliseconds(): ?int
    {
        // UTC_TIMESTAMP is deliberately converted with UTC-to-epoch
        // arithmetic, rather than UNIX_TIMESTAMP(datetime), whose argument is
        // interpreted in the current MySQL session zone.
        try {
            $value = $this->db
                ->createCommand("SELECT TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6))")
                ->queryScalar();
        } catch (\Throwable) {
            return null;
        }

        if (is_int($value)) {
            return intdiv($value, 1000);
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return intdiv((int) $value, 1000);
    }

    private function parseInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
