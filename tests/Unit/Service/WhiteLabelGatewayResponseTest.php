<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\WhiteLabelGatewayResponse;
use PHPUnit\Framework\TestCase;

final class WhiteLabelGatewayResponseTest extends TestCase
{
    /**
     * @dataProvider missingMandatoryHeaderProvider
     */
    public function testRejectsCacheableResponsesWithoutMandatoryHeaders(
        int $statusCode,
        string $body,
        ?string $etag,
        ?string $cacheControl,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new WhiteLabelGatewayResponse(
            statusCode: $statusCode,
            body: $body,
            contentType: $statusCode === 200 ? 'application/json' : null,
            etag: $etag,
            cacheControl: $cacheControl,
        );
    }

    /**
     * @return iterable<string, array{int, string, ?string, ?string}>
     */
    public static function missingMandatoryHeaderProvider(): iterable
    {
        yield '200 without etag' => [200, '{}', null, 'private, max-age=60'];
        yield '200 without cache control' => [200, '{}', '"etag"', null];
        yield '304 without etag' => [304, '', null, 'private, max-age=60'];
        yield '304 without cache control' => [304, '', '"etag"', null];
    }
}
