<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Immutable settings for the shared short-lived QR login-code protocol.
 *
 * The defaults deliberately retain the legacy database-only read path. Redis
 * is not contacted for login-code reads until an operator explicitly selects
 * a Redis read mode.
 */
final class LoginCodeSettings
{
    public const READ_DATABASE = 'database';
    public const READ_REDIS_FIRST = 'redis-first';
    public const READ_REDIS = 'redis';

    public const WRITE_DATABASE = 'database';
    public const WRITE_DUAL = 'dual';
    public const WRITE_REDIS = 'redis';

    public const ACTIVE_WINDOW_SECONDS = 60;
    public const RECORD_RETENTION_SECONDS = 300;
    public const ISSUE_WINDOW_SECONDS = 60;
    public const PROTOCOL_VERSION = 'login-code-v1';
    /**
     * Legacy user_linked.created_at is a DATETIME wall-clock value produced
     * by the existing application in Asia/Shanghai. Redis-first fallback must
     * interpret it at this fixed offset rather than inherit a DB session zone.
     */
    public const LEGACY_DB_TIME_ZONE = '+08:00';

    private readonly string $readMode;
    private readonly string $writeMode;
    private readonly string $prefix;
    private readonly int $issueLimit;
    private readonly bool $legacyDbAvailable;
    private readonly string $protocolFingerprint;

    public function __construct(
        string $readMode = self::READ_DATABASE,
        string $writeMode = self::WRITE_DATABASE,
        string $prefix = 'auth:login-code:v1',
        mixed $activeWindowSeconds = self::ACTIVE_WINDOW_SECONDS,
        mixed $recordRetentionSeconds = self::RECORD_RETENTION_SECONDS,
        mixed $issueLimit = 5,
        mixed $issueWindowSeconds = self::ISSUE_WINDOW_SECONDS,
        mixed $legacyDbAvailable = true,
        mixed $expectedProtocolFingerprint = null,
    ) {
        $this->readMode = strtolower(trim($readMode));
        $this->writeMode = strtolower(trim($writeMode));
        $this->prefix = rtrim(trim($prefix), ':');
        $this->issueLimit = $this->integerValue($issueLimit, 'LOGIN_CODE_ISSUE_LIMIT');
        $this->legacyDbAvailable = $this->booleanValue($legacyDbAvailable, 'LOGIN_CODE_LEGACY_DB_AVAILABLE');
        $hasExplicitEmptyExpectedProtocolFingerprint = $expectedProtocolFingerprint === '';
        $expectedProtocolFingerprint = $this->optionalFingerprint($expectedProtocolFingerprint);

        $activeWindowSeconds = $this->integerValue($activeWindowSeconds, 'LOGIN_CODE_ACTIVE_WINDOW_SECONDS');
        $recordRetentionSeconds = $this->integerValue($recordRetentionSeconds, 'LOGIN_CODE_RECORD_TTL_SECONDS');
        $issueWindowSeconds = $this->integerValue($issueWindowSeconds, 'LOGIN_CODE_ISSUE_WINDOW_SECONDS');

        if (!in_array($this->readMode, [self::READ_DATABASE, self::READ_REDIS_FIRST, self::READ_REDIS], true)) {
            throw new InvalidArgumentException('LOGIN_CODE_READ_MODE is invalid.');
        }

        if (!in_array($this->writeMode, [self::WRITE_DATABASE, self::WRITE_DUAL, self::WRITE_REDIS], true)) {
            throw new InvalidArgumentException('LOGIN_CODE_WRITE_MODE is invalid.');
        }

        if (!in_array(
            $this->readMode . '/' . $this->writeMode,
            [
                self::READ_DATABASE . '/' . self::WRITE_DATABASE,
                self::READ_DATABASE . '/' . self::WRITE_DUAL,
                self::READ_REDIS_FIRST . '/' . self::WRITE_DUAL,
                self::READ_REDIS_FIRST . '/' . self::WRITE_REDIS,
                self::READ_REDIS . '/' . self::WRITE_REDIS,
            ],
            true,
        )) {
            throw new InvalidArgumentException('The LOGIN_CODE_READ_MODE/LOGIN_CODE_WRITE_MODE combination is unsupported.');
        }

        if (preg_match('/^[a-z][a-z0-9:_-]{0,127}$/D', $this->prefix) !== 1) {
            throw new InvalidArgumentException('LOGIN_CODE_REDIS_PREFIX must use the v1 namespace form.');
        }

        if (
            $activeWindowSeconds !== self::ACTIVE_WINDOW_SECONDS
            || $recordRetentionSeconds !== self::RECORD_RETENTION_SECONDS
            || $issueWindowSeconds !== self::ISSUE_WINDOW_SECONDS
        ) {
            throw new InvalidArgumentException('The v1 login-code time windows are protocol constants (60/300/60 seconds).');
        }

        if ($this->issueLimit < 2 || $this->issueLimit > 20) {
            throw new InvalidArgumentException('LOGIN_CODE_ISSUE_LIMIT must be an integer from 2 through 20.');
        }

        if (!$this->legacyDbAvailable && ($this->readMode !== self::READ_REDIS || $this->writeMode !== self::WRITE_REDIS)) {
            throw new InvalidArgumentException('LOGIN_CODE_LEGACY_DB_AVAILABLE=false only permits redis/redis mode.');
        }

        $this->protocolFingerprint = self::protocolFingerprintFor($this->prefix);
        if ($this->usesRedis() && $hasExplicitEmptyExpectedProtocolFingerprint) {
            throw new InvalidArgumentException('LOGIN_CODE_PROTOCOL_FINGERPRINT must not be empty when Redis mode is enabled.');
        }

        if (
            $this->usesRedis()
            && $expectedProtocolFingerprint !== null
            && !hash_equals($expectedProtocolFingerprint, $this->protocolFingerprint)
        ) {
            throw new InvalidArgumentException('LOGIN_CODE_PROTOCOL_FINGERPRINT does not match the v1 protocol settings.');
        }
    }

    public function readMode(): string
    {
        return $this->readMode;
    }

    public function writeMode(): string
    {
        return $this->writeMode;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function protocolFingerprint(): string
    {
        return $this->protocolFingerprint;
    }

    public static function defaultProtocolFingerprint(): string
    {
        return self::protocolFingerprintFor('auth:login-code:v1');
    }

    public static function protocolFingerprintFor(string $prefix): string
    {
        return hash('sha256', implode("\n", [
            self::PROTOCOL_VERSION,
            rtrim(trim($prefix), ':'),
            (string) self::ACTIVE_WINDOW_SECONDS,
            (string) self::RECORD_RETENTION_SECONDS,
        ]));
    }

    public function issueLimit(): int
    {
        return $this->issueLimit;
    }

    public function legacyDbAvailable(): bool
    {
        return $this->legacyDbAvailable;
    }

    public function isDatabaseRead(): bool
    {
        return $this->readMode === self::READ_DATABASE;
    }

    public function isRedisFirst(): bool
    {
        return $this->readMode === self::READ_REDIS_FIRST;
    }

    public function isRedisRead(): bool
    {
        return $this->readMode === self::READ_REDIS;
    }

    public function writesRedis(): bool
    {
        return $this->writeMode !== self::WRITE_DATABASE;
    }

    public function usesRedis(): bool
    {
        return !$this->isDatabaseRead() || $this->writesRedis();
    }

    private function integerValue(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException($name . ' must be an integer.');
    }

    private function booleanValue(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException($name . ' must be a boolean.');
    }

    private function optionalFingerprint(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('LOGIN_CODE_PROTOCOL_FINGERPRINT must be a lowercase SHA-256 hex value.');
        }

        return $value;
    }
}
