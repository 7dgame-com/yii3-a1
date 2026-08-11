<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Redacted, low-cardinality readiness outcome for the login-code protocol.
 *
 * It deliberately contains no Redis command, key, payload, user, code, token,
 * endpoint, host, or underlying exception detail.
 */
final class LoginCodeReadinessResult
{
    private const PROTOCOL = 'login-code-v1';
    private const LIMITER = 'redis-zset-sliding-window';
    private const CLOCK_SYNC = 'within_1s';

    public const SKIPPED = 'skipped';
    public const READY = 'ready';
    public const REDIS_TIME_UNAVAILABLE = 'redis_time_unavailable';
    public const APP_CLOCK_SKEW = 'app_clock_skew';
    public const MYSQL_TIME_UNAVAILABLE = 'mysql_time_unavailable';
    public const MYSQL_CLOCK_SKEW = 'mysql_clock_skew';
    public const READINESS_UNAVAILABLE = 'readiness_unavailable';

    private function __construct(
        public readonly bool $required,
        public readonly bool $ready,
        public readonly string $reason,
        private readonly ?int $redisDatabase = null,
        private readonly ?int $issueLimit = null,
        private readonly ?string $protocolFingerprint = null,
    ) {
    }

    public static function skipped(): self
    {
        return new self(false, true, self::SKIPPED);
    }

    public static function ready(
        int $redisDatabase = 0,
        int $issueLimit = 5,
        ?string $protocolFingerprint = null,
    ): self
    {
        if ($redisDatabase < 0) {
            throw new InvalidArgumentException('REDIS_DB must be a non-negative integer.');
        }

        if ($issueLimit < 2 || $issueLimit > 20) {
            throw new InvalidArgumentException('LOGIN_CODE_ISSUE_LIMIT must be an integer from 2 through 20.');
        }

        $protocolFingerprint ??= LoginCodeSettings::defaultProtocolFingerprint();
        if (preg_match('/^[a-f0-9]{64}$/D', $protocolFingerprint) !== 1) {
            throw new InvalidArgumentException('LOGIN_CODE_PROTOCOL_FINGERPRINT must be a lowercase SHA-256 hex value.');
        }

        return new self(true, true, self::READY, $redisDatabase, $issueLimit, $protocolFingerprint);
    }

    public static function failed(string $reason): self
    {
        if (!in_array($reason, [
            self::REDIS_TIME_UNAVAILABLE,
            self::APP_CLOCK_SKEW,
            self::MYSQL_TIME_UNAVAILABLE,
            self::MYSQL_CLOCK_SKEW,
            self::READINESS_UNAVAILABLE,
        ], true)) {
            throw new InvalidArgumentException('Login-code readiness reason is invalid.');
        }

        return new self(true, false, $reason);
    }

    /**
     * Safe detail for the public health endpoint and structured telemetry.
     * The fixed reason vocabulary intentionally remains low cardinality.
     *
     * @return array<string, int|string|bool>
     */
    public function toHealthDetail(): array
    {
        if (!$this->required) {
            return [
                'status' => self::SKIPPED,
                'required' => false,
            ];
        }

        if ($this->ready) {
            // A ready result can only be constructed with this bounded,
            // non-secret metadata. Never add a host, key, credential, code,
            // digest, user or token to this public health shape.
            return [
                'status' => 'up',
                'required' => true,
                'protocol' => self::PROTOCOL,
                'protocol_fingerprint' => $this->protocolFingerprint,
                'redis_database' => $this->redisDatabase,
                'active_window_seconds' => LoginCodeSettings::ACTIVE_WINDOW_SECONDS,
                'record_retention_seconds' => LoginCodeSettings::RECORD_RETENTION_SECONDS,
                'issue_window_seconds' => LoginCodeSettings::ISSUE_WINDOW_SECONDS,
                'issue_limit' => $this->issueLimit,
                'limiter' => self::LIMITER,
                'clock_sync' => self::CLOCK_SYNC,
            ];
        }

        return [
            'status' => 'down',
            'required' => true,
            'reason' => $this->reason,
        ];
    }
}
