<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\LoginCodeReadiness;
use App\Service\LoginCodeReadinessResult;

/**
 * Deterministic readiness result for consumer and health unit tests.
 */
final class StaticLoginCodeReadiness implements LoginCodeReadiness
{
    public int $checks = 0;

    public function __construct(private readonly LoginCodeReadinessResult $result)
    {
    }

    public static function ready(int $redisDatabase = 0, int $issueLimit = 5): self
    {
        return new self(LoginCodeReadinessResult::ready($redisDatabase, $issueLimit));
    }

    public function check(): LoginCodeReadinessResult
    {
        ++$this->checks;

        return $this->result;
    }
}
