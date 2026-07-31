<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Allow-listed response data that may cross the plugin/A1 trust boundary.
 *
 * Only the JSON body and explicitly supported cache headers are represented;
 * hop-by-hop and arbitrary upstream headers can therefore never leak through.
 */
final class WhiteLabelGatewayResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly ?string $contentType = null,
        public readonly ?string $etag = null,
        public readonly ?string $cacheControl = null,
    ) {
        if (!in_array($statusCode, [200, 304, 404], true)) {
            throw new \InvalidArgumentException('Unsupported white-label gateway status.');
        }

        if ($statusCode === 200 && $body === '') {
            throw new \InvalidArgumentException('A successful white-label response must have a body.');
        }

        if (
            in_array($statusCode, [200, 304], true)
            && ($etag === null || $etag === '' || $cacheControl === null || $cacheControl === '')
        ) {
            throw new \InvalidArgumentException(
                'Successful and not-modified white-label responses require ETag and Cache-Control.',
            );
        }
    }
}
