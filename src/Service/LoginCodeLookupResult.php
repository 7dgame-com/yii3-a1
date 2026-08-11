<?php

declare(strict_types=1);

namespace App\Service;

/**
 * `malformed` and `unavailable` are deliberately distinct from invalid codes:
 * callers must fail closed instead of treating infrastructure failures as a
 * normal authentication miss.
 */
final class LoginCodeLookupResult
{
    private function __construct(
        public readonly LoginCodeLookupStatus $status,
        public readonly ?int $userId = null,
        public readonly ?int $redisTimeMilliseconds = null,
        public readonly ?string $frontendDomain = null,
    ) {
    }

    public static function hit(
        int $userId,
        ?int $redisTimeMilliseconds = null,
        ?string $frontendDomain = null,
    ): self
    {
        return new self(LoginCodeLookupStatus::HIT, $userId, $redisTimeMilliseconds, $frontendDomain);
    }

    public static function miss(?int $redisTimeMilliseconds = null): self
    {
        return new self(LoginCodeLookupStatus::MISS, null, $redisTimeMilliseconds);
    }

    public static function expired(?int $redisTimeMilliseconds = null): self
    {
        return new self(LoginCodeLookupStatus::EXPIRED, null, $redisTimeMilliseconds);
    }

    public static function malformed(?int $redisTimeMilliseconds = null): self
    {
        return new self(LoginCodeLookupStatus::MALFORMED, null, $redisTimeMilliseconds);
    }

    public static function unavailable(): self
    {
        return new self(LoginCodeLookupStatus::UNAVAILABLE);
    }

    public function isInfrastructureFailure(): bool
    {
        return $this->status === LoginCodeLookupStatus::MALFORMED
            || $this->status === LoginCodeLookupStatus::UNAVAILABLE;
    }
}
