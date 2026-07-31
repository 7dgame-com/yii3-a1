<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V1;

use App\Controller\V1\WhiteLabelController;
use App\Service\WhiteLabelGatewayException;
use App\Service\WhiteLabelGatewayInterface;
use App\Service\WhiteLabelGatewayResponse;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class WhiteLabelControllerTest extends TestCase
{
    private const ORGANIZATION_ID = 12;
    private const DOMAIN_ID = 34;
    private const ETAG = '"wl-o12-d34-or4-dr7"';
    private const CONFIG_JSON = '{"version":1,"organization":{"id":12,"name":"academy","title":"Academy","revision":4,"schemaVersion":1,"config":{"locale":"zh-CN"}},"domain":{"id":34,"configKey":"dev.xrugc.com","revision":7,"schemaVersion":1,"config":{"name":"dev.xrugc.com","description":"XR UGC Dev","is_active":true,"fallback_domain":null,"default_config":{"primaryColor":"#2563EB"},"configs":{"zh-CN":{}}}}}';

    public function testReturnsTwoConfigurationNamespacesAndAllowListedCacheHeaders(): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetch')
            ->with(self::ORGANIZATION_ID, self::DOMAIN_ID, '"old-etag"')
            ->willReturn(new WhiteLabelGatewayResponse(
                statusCode: 200,
                body: self::CONFIG_JSON,
                contentType: 'application/json; charset=utf-8',
                etag: self::ETAG,
                cacheControl: 'private, max-age=60',
            ));

        $response = $this->controller($gateway)->resolve($this->request(
            ['o' => '12', 'd' => '34'],
            '"old-etag"',
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::CONFIG_JSON, (string) $response->getBody());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(self::ETAG, $response->getHeaderLine('ETag'));
        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAcceptsPositiveIntegerQueryValuesFromProgrammaticRequest(): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetch')
            ->with(self::ORGANIZATION_ID, self::DOMAIN_ID, '')
            ->willReturn(new WhiteLabelGatewayResponse(404));

        $response = $this->controller($gateway)->resolve($this->request([
            'o' => self::ORGANIZATION_ID,
            'd' => self::DOMAIN_ID,
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturnsBodyless304WithValidators(): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->method('fetch')->willReturn(new WhiteLabelGatewayResponse(
            statusCode: 304,
            etag: self::ETAG,
            cacheControl: 'private, max-age=60',
        ));

        $response = $this->controller($gateway)->resolve($this->request(
            ['o' => '12', 'd' => '34'],
            self::ETAG,
        ));

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(self::ETAG, $response->getHeaderLine('ETag'));
        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('', $response->getHeaderLine('Content-Type'));
    }

    public function testReturnsUnified404WithoutLeakingPairState(): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->method('fetch')->willReturn(new WhiteLabelGatewayResponse(404));

        $response = $this->controller($gateway)->resolve($this->request([
            'o' => '12',
            'd' => '34',
        ]));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $body['name']);
        $this->assertSame('White-label configuration not found.', $body['message']);
        $this->assertSame(404, $body['status']);
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testReturnsGeneric503ForEveryGatewayFailure(): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->method('fetch')->willThrowException(
            new WhiteLabelGatewayException('internal URL and secret details'),
        );

        $response = $this->controller($gateway)->resolve($this->request([
            'o' => '12',
            'd' => '34',
        ]));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Service Unavailable', $body['name']);
        $this->assertSame('White-label configuration service is unavailable.', $body['message']);
        $this->assertStringNotContainsString('secret', (string) $response->getBody());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * @dataProvider invalidQueryProvider
     *
     * @param array<string, mixed> $query
     */
    public function testRejectsInvalidQueryBeforeCallingUpstream(array $query): void
    {
        $gateway = $this->createMock(WhiteLabelGatewayInterface::class);
        $gateway->expects($this->never())->method('fetch');

        $response = $this->controller($gateway)->resolve($this->request($query));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Query parameters o and d must be positive integers.',
            $body['message'],
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidQueryProvider(): iterable
    {
        yield 'both missing' => [[]];
        yield 'organization missing' => [['d' => '34']];
        yield 'domain missing' => [['o' => '12']];
        yield 'zero organization' => [['o' => '0', 'd' => '34']];
        yield 'negative domain' => [['o' => '12', 'd' => '-34']];
        yield 'leading zero' => [['o' => '012', 'd' => '34']];
        yield 'decimal' => [['o' => '12', 'd' => '34.0']];
        yield 'scientific notation' => [['o' => '1e1', 'd' => '34']];
        yield 'surrounding whitespace' => [['o' => ' 12', 'd' => '34']];
        yield 'array injection' => [['o' => ['12'], 'd' => '34']];
        yield 'boolean value' => [['o' => true, 'd' => '34']];
        yield 'unsafe integer' => [['o' => '9007199254740992', 'd' => '34']];
        yield 'unsafe programmatic integer' => [['o' => 9_007_199_254_740_992, 'd' => 34]];
    }

    private function controller(
        ?WhiteLabelGatewayInterface $gateway = null,
    ): WhiteLabelController {
        return new WhiteLabelController(
            $gateway ?? $this->createMock(WhiteLabelGatewayInterface::class),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(
        array $query,
        string $ifNoneMatch = '',
    ): ServerRequestInterface {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getHeaderLine')
            ->with('If-None-Match')
            ->willReturn($ifNoneMatch);

        return $request;
    }
}
