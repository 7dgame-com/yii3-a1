<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\LoginCodeRedisClient;

/**
 * Controlled single-key Redis test double. It intentionally implements only
 * GET/PTTL/TIME, which makes any accidental read-path write or scan impossible
 * in login-code consumer unit tests.
 */
final class ControlledLoginCodeRedisClient implements LoginCodeRedisClient
{
    /** @var list<string> */
    public array $getKeys = [];

    /** @var list<string> */
    public array $pttlKeys = [];

    public int $timeCalls = 0;

    public function __construct(
        private readonly mixed $payload,
        private readonly mixed $pttlValue,
        private readonly mixed $timeValue,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function get(string $key): mixed
    {
        $this->getKeys[] = $key;
        $this->throwFailure();

        return $this->payload;
    }

    public function pttl(string $key): mixed
    {
        $this->pttlKeys[] = $key;
        $this->throwFailure();

        return $this->pttlValue;
    }

    public function time(): mixed
    {
        ++$this->timeCalls;
        $this->throwFailure();

        return $this->timeValue;
    }

    private function throwFailure(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
