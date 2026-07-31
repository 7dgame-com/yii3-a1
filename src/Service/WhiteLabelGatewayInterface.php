<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Read-only boundary between the public A1 API and the white-label plugin.
 */
interface WhiteLabelGatewayInterface
{
    /**
     * @throws WhiteLabelGatewayException when the upstream is unavailable or
     *         violates the internal API contract.
     */
    public function fetch(
        int $organizationId,
        int $domainId,
        string $ifNoneMatch = '',
    ): WhiteLabelGatewayResponse;
}
