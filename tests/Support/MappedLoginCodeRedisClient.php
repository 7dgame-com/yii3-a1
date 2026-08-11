<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\LoginCodeRedisClient;

/**
 * In-memory, multi-record Redis read double for login-code consumer tests.
 *
 * Records are installed by their already-derived Redis key, so tests can
 * prove that separate bearer codes resolve separate records without exposing
 * either raw codes or digests in fixtures.
 */
final class MappedLoginCodeRedisClient implements LoginCodeRedisClient
{
    /** @var array<string, array{payload: mixed, pttl: mixed}> */
    private array $records = [];

    /** @var list<string> */
    public array $getKeys = [];

    /** @var list<string> */
    public array $pttlKeys = [];

    public int $timeCalls = 0;

    public int $unknownGetCalls = 0;

    public int $unknownPttlCalls = 0;

    public function __construct(private readonly mixed $timeValue)
    {
    }

    public function put(string $key, mixed $payload, mixed $pttl): void
    {
        $this->records[$key] = [
            'payload' => $payload,
            'pttl' => $pttl,
        ];
    }

    public function get(string $key): mixed
    {
        $this->getKeys[] = $key;
        if (!array_key_exists($key, $this->records)) {
            ++$this->unknownGetCalls;

            return null;
        }

        return $this->records[$key]['payload'];
    }

    public function pttl(string $key): mixed
    {
        $this->pttlKeys[] = $key;
        if (!array_key_exists($key, $this->records)) {
            ++$this->unknownPttlCalls;

            return -2;
        }

        return $this->records[$key]['pttl'];
    }

    public function time(): mixed
    {
        ++$this->timeCalls;

        return $this->timeValue;
    }
}
