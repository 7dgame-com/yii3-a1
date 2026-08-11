<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;

/**
 * Production adapter for the three single-key/read-only commands used by
 * LoginCodeStore. Exceptions intentionally bubble to the Store, which maps
 * them to a fail-closed unavailable result without logging credentials.
 */
final class PredisLoginCodeRedisClient implements LoginCodeRedisClient
{
    public function __construct(
        private readonly RedisClient $redis,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function pttl(string $key): mixed
    {
        return $this->redis->pttl($key);
    }

    public function time(): mixed
    {
        return $this->redis->time();
    }
}
