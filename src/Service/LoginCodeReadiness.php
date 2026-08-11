<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Readiness boundary for Redis-backed login-code authorization.
 */
interface LoginCodeReadiness
{
    public function check(): LoginCodeReadinessResult;
}
