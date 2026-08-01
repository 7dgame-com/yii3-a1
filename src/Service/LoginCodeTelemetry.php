<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Fixed, low-cardinality rollout events for QR login-code authorization.
 *
 * This boundary intentionally accepts no caller-controlled dimensions. In
 * particular, do not add login codes, digests, user IDs, JWTs, refresh
 * tokens, exception messages, or Redis/DB connection data to the context.
 */
final class LoginCodeTelemetry
{
    public const SOURCE_YII3_REFRESH = 'yii3-refresh';
    public const SOURCE_YII3_KEY_TO_TOKEN = 'yii3-key-to-token';

    private const EVENT_MESSAGE = 'Login-code protocol event.';
    private const INVALID_DIMENSIONS_MESSAGE = 'Rejected invalid login-code telemetry dimensions.';

    /** @var list<string> */
    private const EVENTS = [
        'issued',
        'dual_write_success',
        'redis_write_failed',
        'db_write_failed',
        'compensation_failed',
        'rate_limited',
        'rate_limit_error',
        'redis_hit',
        'db_fallback_hit',
        'miss',
        'active',
        'expired',
        'malformed',
        'redis_error',
        'readiness_down',
    ];

    /** @var list<string> */
    private const SOURCES = [
        self::SOURCE_YII3_REFRESH,
        self::SOURCE_YII3_KEY_TO_TOKEN,
    ];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * Emit one protocol event using only fixed event/source dimensions.
     */
    public function record(string $event, string $source): void
    {
        if (!in_array($event, self::EVENTS, true) || !in_array($source, self::SOURCES, true)) {
            $this->logger?->warning(self::INVALID_DIMENSIONS_MESSAGE);
            return;
        }

        $this->logger?->info(self::EVENT_MESSAGE, [
            'event' => $event,
            'source' => $source,
        ]);
    }
}
