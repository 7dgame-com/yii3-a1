<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Minimal read-only Redis surface used by the login-code authorization
 * protocol. Keeping this surface small makes its TIME/PTTL boundary semantics
 * independently testable without broad Redis mocks or namespace scans.
 */
interface LoginCodeRedisClient
{
    public function get(string $key): mixed;

    public function pttl(string $key): mixed;

    public function time(): mixed;
}
