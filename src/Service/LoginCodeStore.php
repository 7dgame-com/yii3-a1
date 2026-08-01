<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;
use Yiisoft\Db\Connection\ConnectionProvider;

/**
 * Read boundary for QR login codes shared with the main API.
 *
 * It is intentionally a read-only, single-Code_Record protocol: no raw code
 * is written to Redis, and resolve never refreshes a TTL, consumes a code, or
 * scans the login-code namespace. Legacy user_linked keys are always treated
 * as SHA256(raw-code) values; a stored digest is never itself a bearer code.
 */
final class LoginCodeStore
{
    private const CODE_KEY_SEGMENT = ':code:';
    private const PROTOCOL_VERSION = 1;
    private const PURPOSE = 'web-device-login';
    private const ISSUER = 'main-api';
    private const MAX_RECORD_BYTES = 2048;
    private const ACTIVE_WINDOW_MILLISECONDS = 60_000;
    private const RECORD_RETENTION_MILLISECONDS = 300_000;
    private const ACTIVE_PTTL_MINIMUM_MILLISECONDS = 240_000;
    private const MINIMUM_LOGIN_CODE_LENGTH = 32;

    private readonly LoginCodeRedisClient $redis;

    public function __construct(
        RedisClient|LoginCodeRedisClient $redis,
        private readonly LoginCodeSettings $settings,
        private readonly ?LoginCodeReadiness $readiness = null,
        private readonly ?LoginCodeTelemetry $telemetry = null,
    ) {
        $this->redis = $redis instanceof RedisClient
            ? new PredisLoginCodeRedisClient($redis)
            : $redis;
    }

    /**
     * Resolve a raw, normalized login code using the configured read mode.
     *
     * In redis-first mode only a healthy Redis miss reaches Legacy_DB. The
     * fallback receives the same Redis TIME value used for the miss so its
     * 60/300-second boundaries cannot depend on the PHP application clock.
     */
    public function resolve(string $rawLoginCode): LoginCodeLookupResult
    {
        return $this->resolveForSource($rawLoginCode, LoginCodeTelemetry::SOURCE_YII3_REFRESH);
    }

    /**
     * Resolve a login code used by the key-to-token consumer endpoint.
     *
     * Keeping the source selection in this storage boundary prevents callers
     * from adding request-derived telemetry labels.
     */
    public function resolveForKeyToToken(string $rawLoginCode): LoginCodeLookupResult
    {
        return $this->resolveForSource($rawLoginCode, LoginCodeTelemetry::SOURCE_YII3_KEY_TO_TOKEN);
    }

    private function resolveForSource(string $rawLoginCode, string $telemetrySource): LoginCodeLookupResult
    {
        $rawLoginCode = trim($rawLoginCode);
        if (strlen($rawLoginCode) < self::MINIMUM_LOGIN_CODE_LENGTH) {
            return LoginCodeLookupResult::miss();
        }

        if ($this->settings->isDatabaseRead()) {
            // Preserve the historical database/database path as-is. Rollout
            // telemetry begins only once this consumer uses Redis-backed
            // login-code authorization.
            return $this->resolveLegacyDatabase($rawLoginCode);
        }

        // Redis authorization relies on a bounded relationship between the
        // time source that issued the record and the time source that reads
        // it. A missing, skipped, failed, or throwing gate is never allowed
        // to degrade into an authentication miss or a legacy DB fallback.
        try {
            $readiness = $this->readiness?->check();
        } catch (\Throwable) {
            return $this->recordRedisOutcome(LoginCodeLookupResult::unavailable(), $telemetrySource);
        }

        if ($readiness === null || !$readiness->required || !$readiness->ready) {
            return $this->recordRedisOutcome(LoginCodeLookupResult::unavailable(), $telemetrySource);
        }

        $redisResult = $this->resolveRedisCodeRecord($rawLoginCode);
        if (!$this->settings->isRedisFirst() || $redisResult->status !== LoginCodeLookupStatus::MISS) {
            return $this->recordRedisOutcome($redisResult, $telemetrySource);
        }

        // Emit the Redis miss separately from the final fallback outcome, so
        // operators can measure bounded compatibility use without a
        // request-derived label.
        $this->recordRedisOutcome($redisResult, $telemetrySource);

        if ($redisResult->redisTimeMilliseconds === null) {
            return $this->recordRedisOutcome(LoginCodeLookupResult::unavailable(), $telemetrySource);
        }

        return $this->recordLegacyOutcome(
            $this->resolveLegacyDatabase($rawLoginCode, $redisResult->redisTimeMilliseconds),
            $telemetrySource,
            true,
        );
    }

    /**
     * Build the only Redis key used by the login-code authorization protocol.
     *
     * This helper is public for deterministic, exact-key test cleanup. It is
     * not a user index and callers must never use it for namespace scans.
     */
    public function keyFor(string $rawLoginCode): string
    {
        return $this->settings->prefix() . self::CODE_KEY_SEGMENT . hash('sha256', $rawLoginCode);
    }

    private function resolveRedisCodeRecord(string $rawLoginCode): LoginCodeLookupResult
    {
        try {
            $key = $this->keyFor($rawLoginCode);
            $payload = $this->redis->get($key);
            $pttl = $this->parseRedisInteger($this->redis->pttl($key));
            $nowMilliseconds = $this->redisTimeMilliseconds($this->redis->time());
        } catch (\Throwable) {
            return LoginCodeLookupResult::unavailable();
        }

        if ($nowMilliseconds === null || $pttl === null) {
            return LoginCodeLookupResult::unavailable();
        }

        if ($payload === null) {
            // Only an absent value paired with Redis' explicit no-key PTTL is
            // a healthy miss. Any other GET/PTTL combination may mean the key
            // changed between commands and must not unlock DB fallback.
            return $pttl === -2
                ? LoginCodeLookupResult::miss($nowMilliseconds)
                : LoginCodeLookupResult::malformed($nowMilliseconds);
        }

        if (!is_string($payload)) {
            return LoginCodeLookupResult::malformed($nowMilliseconds);
        }

        // A key that disappeared between GET and PTTL is a healthy miss. This
        // is fail-closed for Redis-only and the only case redis-first may use
        // the bounded legacy compatibility path.
        if ($pttl === -2) {
            return LoginCodeLookupResult::miss($nowMilliseconds);
        }

        if ($pttl < -2 || $pttl === -1 || $pttl > self::RECORD_RETENTION_MILLISECONDS) {
            return LoginCodeLookupResult::malformed($nowMilliseconds);
        }

        $record = $this->parseCodeRecord($payload, $nowMilliseconds);
        if ($record === null) {
            return LoginCodeLookupResult::malformed($nowMilliseconds);
        }

        if (
            $nowMilliseconds >= $record['expires_at'] * 1000
            || $pttl <= self::ACTIVE_PTTL_MINIMUM_MILLISECONDS
        ) {
            return LoginCodeLookupResult::expired($nowMilliseconds);
        }

        return LoginCodeLookupResult::hit($record['user_id'], $nowMilliseconds);
    }

    /**
     * @return array{user_id: int, expires_at: int}|null
     */
    private function parseCodeRecord(string $payload, int $nowMilliseconds): ?array
    {
        if (strlen($payload) > self::MAX_RECORD_BYTES) {
            return null;
        }

        try {
            $record = json_decode($payload, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!$record instanceof \stdClass) {
            return null;
        }

        foreach (['v', 'user_id', 'issued_at', 'expires_at', 'purpose', 'issuer', 'context'] as $field) {
            if (!property_exists($record, $field)) {
                return null;
            }
        }

        if (
            !is_int($record->v)
            || $record->v !== self::PROTOCOL_VERSION
            || !is_int($record->user_id)
            || $record->user_id <= 0
            || !is_int($record->issued_at)
            || $record->issued_at <= 0
            || !is_int($record->expires_at)
            || $record->expires_at <= 0
            || !is_string($record->purpose)
            || $record->purpose !== self::PURPOSE
            || !is_string($record->issuer)
            || $record->issuer !== self::ISSUER
            || !is_object($record->context)
        ) {
            return null;
        }

        if ($record->expires_at - $record->issued_at !== LoginCodeSettings::ACTIVE_WINDOW_SECONDS) {
            return null;
        }

        // The issuer reads Redis TIME before SET, therefore a valid record can
        // never begin in the future according to the same Redis clock.
        if ($record->issued_at > intdiv($nowMilliseconds, 1000)) {
            return null;
        }

        return [
            'user_id' => $record->user_id,
            'expires_at' => $record->expires_at,
        ];
    }

    private function resolveLegacyDatabase(string $rawLoginCode, ?int $redisTimeMilliseconds = null): LoginCodeLookupResult
    {
        if (!$this->settings->legacyDbAvailable()) {
            return LoginCodeLookupResult::miss($redisTimeMilliseconds);
        }

        try {
            return $this->resolveLegacyDatabaseRecord($rawLoginCode, $redisTimeMilliseconds);
        } catch (\Throwable $exception) {
            // A redis-first compatibility fallback must not turn any failed DB
            // operation into a normal invalid-code response. Do not expose the
            // underlying exception because a driver message can contain SQL or
            // login-code-derived values. Database mode retains legacy errors.
            if ($redisTimeMilliseconds !== null) {
                return LoginCodeLookupResult::unavailable();
            }

            throw $exception;
        }
    }

    private function resolveLegacyDatabaseRecord(string $rawLoginCode, ?int $redisTimeMilliseconds): LoginCodeLookupResult
    {
        $lookupDigest = hash('sha256', $rawLoginCode);

        if ($redisTimeMilliseconds !== null) {
            return $this->resolveCurrentLegacyDatabaseFallback($lookupDigest, $redisTimeMilliseconds);
        }

        $row = $this->queryLatestLegacyDatabaseRow($lookupDigest);
        if ($row === null) {
            return LoginCodeLookupResult::miss($redisTimeMilliseconds);
        }

        $userId = $this->legacySnapshotInteger($row['user_id'] ?? null);
        if ($userId <= 0) {
            return LoginCodeLookupResult::miss($redisTimeMilliseconds);
        }

        $createdAt = $this->legacyDatabaseModeTimestamp($row['created_at'] ?? null);
        return $createdAt <= 0 || $createdAt + LoginCodeSettings::ACTIVE_WINDOW_SECONDS <= time()
            ? LoginCodeLookupResult::expired()
            : LoginCodeLookupResult::hit($userId);
    }

    /**
     * Database/database mode preserves the historical Asia/Shanghai PHP
     * baseline while still querying only SHA256(input). The NOT EXISTS
     * predicate limits it to the current row for that user, so a historical
     * code cannot revive after a newer code has been issued.
     *
     * @return array<string, mixed>|null
     */
    private function queryLatestLegacyDatabaseRow(string $lookupDigest): ?array
    {
        $command = ConnectionProvider::get()->createCommand(
            'SELECT `linked`.`user_id` AS `user_id`, `linked`.`created_at` AS `created_at` '
            . 'FROM `user_linked` AS `linked` '
            . 'WHERE `linked`.`key` = :loginCodeKey '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM `user_linked` AS `newer` '
            . 'WHERE `newer`.`user_id` = `linked`.`user_id` '
            . 'AND `newer`.`id` > `linked`.`id`'
            . ') '
            . 'ORDER BY `linked`.`id` DESC LIMIT 1',
        );

        $command->bindParam(':loginCodeKey', $lookupDigest);
        $row = $command->queryOne();

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve a redis-first compatibility miss from one current-row snapshot.
     *
     * Old user_linked data can contain historical duplicate rows. The
     * NOT EXISTS predicate means a row only authorizes its user when it is the
     * current row for that user; an earlier code cannot become valid again
     * after a newer dual-write code replaced it. Keeping the matching key,
     * user id and fixed-offset-normalized timestamp in one statement also
     * prevents a concurrent write from pairing code A with code B's fresh
     * timestamp. The conversion is deliberately independent of the MySQL
     * connection's session time_zone so both APIs agree during dual write.
     */
    private function resolveCurrentLegacyDatabaseFallback(string $lookupDigest, int $redisTimeMilliseconds): LoginCodeLookupResult
    {
        $command = ConnectionProvider::get()->createCommand(
            'SELECT `linked`.`user_id` AS `user_id`, '
            . "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', "
            . "CONVERT_TZ(`linked`.`created_at`, '" . LoginCodeSettings::LEGACY_DB_TIME_ZONE . "', '+00:00')) "
            . 'AS `created_at_epoch` '
            . 'FROM `user_linked` AS `linked` '
            . 'WHERE `linked`.`key` = :loginCodeKey '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM `user_linked` AS `newer` '
            . 'WHERE `newer`.`user_id` = `linked`.`user_id` '
            . 'AND `newer`.`id` > `linked`.`id`'
            . ') '
            . 'ORDER BY `linked`.`id` DESC LIMIT 1',
        );

        // Bind after command creation instead of passing values to
        // createCommand(). Yii3's query logger/profiler derives its raw SQL
        // and parameter list from command-bound values; bindParam() keeps the
        // credential-equivalent digest out of that metadata while retaining
        // native prepared-statement binding through query execution.
        $command->bindParam(':loginCodeKey', $lookupDigest);

        $row = $command->queryOne();

        if (!is_array($row)) {
            return LoginCodeLookupResult::miss($redisTimeMilliseconds);
        }

        $userId = $this->legacySnapshotInteger($row['user_id'] ?? null);
        $createdAt = $this->legacySnapshotInteger($row['created_at_epoch'] ?? null);
        if ($userId <= 0 || $createdAt <= 0) {
            return LoginCodeLookupResult::miss($redisTimeMilliseconds);
        }

        $ageMilliseconds = $redisTimeMilliseconds - ($createdAt * 1000);
        if ($ageMilliseconds < 0) {
            return LoginCodeLookupResult::expired($redisTimeMilliseconds);
        }

        if ($ageMilliseconds < self::ACTIVE_WINDOW_MILLISECONDS) {
            return LoginCodeLookupResult::hit($userId, $redisTimeMilliseconds);
        }

        if ($ageMilliseconds < self::RECORD_RETENTION_MILLISECONDS) {
            return LoginCodeLookupResult::expired($redisTimeMilliseconds);
        }

        return LoginCodeLookupResult::miss($redisTimeMilliseconds);
    }

    /**
     * Normalize scalar values returned by the MySQL snapshot without accepting
     * a malformed value as an authorization input.
     */
    private function legacySnapshotInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Match the legacy ActiveRecord model's database/database interpretation
     * of a DATETIME without depending on a MySQL session timezone.
     */
    private function legacyDatabaseModeTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai')))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    private function parseRedisInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param mixed $time Redis TIME response: [epoch seconds, microseconds].
     */
    private function redisTimeMilliseconds(mixed $time): ?int
    {
        if (!is_array($time) || count($time) < 2) {
            return null;
        }

        $seconds = $this->parseRedisInteger($time[0] ?? null);
        $microseconds = $this->parseRedisInteger($time[1] ?? null);
        if ($seconds === null || $seconds < 0 || $microseconds === null || $microseconds < 0 || $microseconds >= 1_000_000) {
            return null;
        }

        return ($seconds * 1000) + intdiv($microseconds, 1000);
    }

    private function recordRedisOutcome(LoginCodeLookupResult $result, string $source): LoginCodeLookupResult
    {
        match ($result->status) {
            LoginCodeLookupStatus::HIT => $this->recordEvents(['redis_hit', 'active'], $source),
            LoginCodeLookupStatus::MISS => $this->recordEvents(['miss'], $source),
            LoginCodeLookupStatus::EXPIRED => $this->recordEvents(['expired'], $source),
            LoginCodeLookupStatus::MALFORMED => $this->recordEvents(['malformed'], $source),
            LoginCodeLookupStatus::UNAVAILABLE => $this->recordEvents(['redis_error'], $source),
        };

        return $result;
    }

    private function recordLegacyOutcome(
        LoginCodeLookupResult $result,
        string $source,
        bool $isRedisFirstFallback = false,
    ): LoginCodeLookupResult {
        match ($result->status) {
            LoginCodeLookupStatus::HIT => $this->recordEvents(
                $isRedisFirstFallback ? ['db_fallback_hit', 'active'] : ['active'],
                $source,
            ),
            LoginCodeLookupStatus::MISS => $this->recordEvents(['miss'], $source),
            LoginCodeLookupStatus::EXPIRED => $this->recordEvents(['expired'], $source),
            // Database-mode errors preserve their existing error contract;
            // redis-first DB failures are intentionally fail-closed without
            // misclassifying them as a Redis dependency outage.
            LoginCodeLookupStatus::MALFORMED, LoginCodeLookupStatus::UNAVAILABLE => null,
        };

        return $result;
    }

    /** @param list<string> $events */
    private function recordEvents(array $events, string $source): void
    {
        if ($this->telemetry === null) {
            return;
        }

        foreach ($events as $event) {
            $this->telemetry->record($event, $source);
        }
    }
}
