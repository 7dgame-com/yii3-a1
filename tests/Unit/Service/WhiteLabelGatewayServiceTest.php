<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\WhiteLabelGatewayException;
use App\Service\WhiteLabelGatewayService;
use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class WhiteLabelGatewayServiceTest extends TestCase
{
    private const ORGANIZATION_ID = 12;
    private const DOMAIN_ID = 34;
    private const INTERNAL_TOKEN = '0123456789abcdef0123456789abcdef';
    private const ETAG = '"wl-o12-d34-or4-dr7"';
    private const CONFIG_JSON = '{"version":1,"organization":{"id":12,"name":"academy","title":"Academy","revision":4,"schemaVersion":1,"config":{"locale":"zh-CN"}},"domain":{"id":34,"configKey":"dev.xrugc.com","revision":7,"schemaVersion":2,"config":{"name":"dev.xrugc.com","description":"XR UGC Dev","is_active":true,"fallback_domain":null,"default_config":{"primaryColor":"#2563EB"},"configs":{"zh-CN":{}}}}}';

    public function testCallsOnlyFixedResolveEndpointAndForwardsConditionalHeader(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): ResponseInterface {
                $this->assertSame(
                    'http://plugin-whitelabel:3000/base/internal/v1/white-label-configs/resolve?o=12&d=34',
                    (string) $request->getUri(),
                );
                $this->assertSame('application/json', $request->getHeaderLine('Accept'));
                $this->assertSame(self::INTERNAL_TOKEN, $request->getHeaderLine('X-Internal-Token'));
                $this->assertSame(self::ETAG, $request->getHeaderLine('If-None-Match'));
                $this->assertFalse($request->hasHeader('Authorization'));

                return $this->response(200, self::CONFIG_JSON, [
                    'Content-Type' => 'application/vnd.7dgame.whitelabel+json; charset=utf-8',
                    'ETag' => '"new-etag"',
                    'Cache-Control' => 'private, max-age=60',
                ]);
            });

        $service = $this->createService(
            $client,
            'http://plugin-whitelabel:3000/base/',
            self::INTERNAL_TOKEN,
        );
        $result = $service->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
            self::ETAG,
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(self::CONFIG_JSON, $result->body);
        $this->assertSame('application/vnd.7dgame.whitelabel+json; charset=utf-8', $result->contentType);
        $this->assertSame('"new-etag"', $result->etag);
        $this->assertSame('private, max-age=60', $result->cacheControl);
    }

    public function testAcceptsA253CharacterDomainConfigKeyAtTheSchemaBoundary(): void
    {
        $configKey = str_repeat('a', 63)
            . '.' . str_repeat('b', 63)
            . '.' . str_repeat('c', 63)
            . '.' . str_repeat('d', 61);
        $body = self::configurationJson($configKey);
        $response = $this->response(200, $body, [
            'Content-Type' => 'application/json',
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ]);

        $result = $this->createService($this->clientReturning($response))->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );

        $this->assertSame(253, strlen($configKey));
        $this->assertSame(200, $result->statusCode);
        $this->assertSame($body, $result->body);
    }

    public function testAllowsSelfFallbackMetadataWithoutRecursiveLookup(): void
    {
        $body = self::configurationJson('dev.xrugc.com', [
            'fallback_domain' => 'dev.xrugc.com',
            'default_config' => (object) [],
            'configs' => (object) [],
        ]);
        $response = $this->response(200, $body, [
            'Content-Type' => 'application/json',
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ]);

        $result = $this->createService($this->clientReturning($response))->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame($body, $result->body);
    }

    /**
     * @dataProvider nonPositiveIdProvider
     */
    public function testRejectsNonPositiveIdentifiers(
        int $organizationId,
        int $domainId,
    ): void {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('sendRequest');

        $this->expectException(\InvalidArgumentException::class);

        $this->createService($client)->fetch($organizationId, $domainId);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function nonPositiveIdProvider(): iterable
    {
        yield 'zero organization' => [0, self::DOMAIN_ID];
        yield 'negative organization' => [-1, self::DOMAIN_ID];
        yield 'zero domain' => [self::ORGANIZATION_ID, 0];
        yield 'negative domain' => [self::ORGANIZATION_ID, -1];
        yield 'organization exceeds safe integer' => [9_007_199_254_740_992, self::DOMAIN_ID];
        yield 'domain exceeds safe integer' => [self::ORGANIZATION_ID, 9_007_199_254_740_992];
    }

    public function testReturnsNotModifiedFromUpstreamWithoutReadingJsonBody(): void
    {
        $client = $this->clientReturning($this->response(304, headers: [
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ]));

        $result = $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
            '"older", W/' . self::ETAG,
        );

        $this->assertSame(304, $result->statusCode);
        $this->assertSame('', $result->body);
        $this->assertSame(self::ETAG, $result->etag);
        $this->assertSame('private, max-age=60', $result->cacheControl);
    }

    public function testSynthesizesNotModifiedWhenLegacyUpstreamIgnoresConditionalHeader(): void
    {
        $client = $this->clientReturning($this->response(
            200,
            self::CONFIG_JSON,
            [
                'Content-Type' => 'application/json',
                'ETag' => self::ETAG,
                'Cache-Control' => 'private, max-age=60',
            ],
        ));

        $result = $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
            'W/' . self::ETAG,
        );

        $this->assertSame(304, $result->statusCode);
        $this->assertSame(self::ETAG, $result->etag);
    }

    public function testSynthesizesNotModifiedForWildcardCondition(): void
    {
        $client = $this->clientReturning($this->response(
            200,
            self::CONFIG_JSON,
            [
                'Content-Type' => 'application/json',
                'ETag' => self::ETAG,
                'Cache-Control' => 'private, max-age=60',
            ],
        ));

        $result = $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
            '*',
        );

        $this->assertSame(304, $result->statusCode);
        $this->assertSame(self::ETAG, $result->etag);
    }

    public function testMapsMissingDisabledOrMismatchedPairToBodyless404(): void
    {
        $client = $this->clientReturning($this->response(
            404,
            '{"error":{"code":"NOT_FOUND","message":"White-label configuration not found"}}',
            ['Content-Type' => 'application/json'],
        ));

        $result = $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );

        $this->assertSame(404, $result->statusCode);
        $this->assertSame('', $result->body);
        $this->assertNull($result->etag);
    }

    /**
     * @dataProvider unverifiableNotModifiedProvider
     */
    public function testRejectsUnverifiableNotModifiedResponses(
        string $ifNoneMatch,
        array $headers,
    ): void {
        $client = $this->clientReturning($this->response(304, headers: $headers));

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
            $ifNoneMatch,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function unverifiableNotModifiedProvider(): iterable
    {
        $validHeaders = [
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ];

        yield 'client sent no condition' => ['', $validHeaders];
        yield 'client validator does not match' => ['"different"', $validHeaders];
        yield 'weak client validator does not match' => ['W/"different"', $validHeaders];
        yield 'client condition is malformed' => ['not-an-etag', $validHeaders];
        yield 'client condition exceeds safe length' => [str_repeat('"x", ', 1_000), $validHeaders];
        yield 'upstream omitted etag' => [self::ETAG, ['Cache-Control' => 'private, max-age=60']];
        yield 'upstream etag is malformed' => [self::ETAG, [
            'ETag' => 'not-an-etag',
            'Cache-Control' => 'private, max-age=60',
        ]];
        yield 'upstream omitted cache control' => [self::ETAG, ['ETag' => self::ETAG]];
        yield 'upstream cache control is oversized' => [self::ETAG, [
            'ETag' => self::ETAG,
            'Cache-Control' => str_repeat('x', 513),
        ]];
    }

    public function testRejectsOversizedDeclaredSuccessBeforeReadingBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'ETag' => self::ETAG,
                'Cache-Control' => 'private, max-age=60',
                'Content-Length' => (string) (WhiteLabelGatewayService::MAX_RESPONSE_BYTES + 1),
                default => '',
            },
        );
        $response->expects($this->never())->method('getBody');

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($this->clientReturning($response))->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );
    }

    public function testBoundedReaderStopsAfterOneMiBPlusOneByte(): void
    {
        $stream = (new StreamFactory())->createStream(str_repeat('x', 2_097_152));
        $response = $this->response(200, headers: [
            'Content-Type' => 'application/json',
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ])->withBody($stream);

        try {
            $this->createService($this->clientReturning($response))->fetch(
                self::ORGANIZATION_ID,
                self::DOMAIN_ID,
            );
            $this->fail('Expected the bounded response reader to reject the oversized body.');
        } catch (WhiteLabelGatewayException) {
            $this->assertSame(WhiteLabelGatewayService::MAX_RESPONSE_BYTES + 1, $stream->tell());
        }
    }

    public function testRejectsCacheControlContainingControlCharacters(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'ETag' => self::ETAG,
                'Cache-Control' => "private,\tmax-age=60",
                default => '',
            },
        );
        $response->expects($this->never())->method('getBody');

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($this->clientReturning($response))->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );
    }

    /**
     * @dataProvider invalidUpstreamResponseProvider
     *
     * @param array<string, string> $headers
     */
    public function testRejectsUnusableUpstreamResponses(
        int $statusCode,
        string $body,
        array $headers,
    ): void {
        $client = $this->clientReturning($this->response($statusCode, $body, $headers));

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );
    }

    /**
     * @return iterable<string, array{int, string, array<string, string>}>
     */
    public static function invalidUpstreamResponseProvider(): iterable
    {
        $validSuccessHeaders = [
            'Content-Type' => 'application/json',
            'ETag' => self::ETAG,
            'Cache-Control' => 'private, max-age=60',
        ];

        yield 'upstream server error' => [
            500,
            '{"message":"failed"}',
            ['Content-Type' => 'application/json'],
        ];
        yield 'unauthorized internal token' => [
            401,
            '{"message":"unauthorized"}',
            ['Content-Type' => 'application/json'],
        ];
        yield 'not found without content type' => [
            404,
            '{"error":{"code":"NOT_FOUND","message":"not found"}}',
            [],
        ];
        yield 'not found with non-json content type' => [
            404,
            '{"error":{"code":"NOT_FOUND","message":"not found"}}',
            ['Content-Type' => 'text/html'],
        ];
        yield 'not found with malformed json' => [
            404,
            '{"error":',
            ['Content-Type' => 'application/json'],
        ];
        yield 'not found with wrong stable code' => [
            404,
            '{"error":{"code":"ROUTE_NOT_FOUND","message":"not found"}}',
            ['Content-Type' => 'application/json'],
        ];
        yield 'not found with legacy message shape' => [
            404,
            '{"message":"domain does not belong to organization"}',
            ['Content-Type' => 'application/json'],
        ];
        yield 'html instead of json' => [
            200,
            '<html>proxy error</html>',
            array_replace($validSuccessHeaders, ['Content-Type' => 'text/html']),
        ];
        yield 'json suffix without media type slash' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['Content-Type' => 'evil+json']),
        ];
        yield 'json suffix without subtype prefix' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['Content-Type' => 'application/+json']),
        ];
        yield 'success omitted etag' => [
            200,
            self::CONFIG_JSON,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'private, max-age=60',
            ],
        ];
        yield 'success etag is malformed' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['ETag' => 'not-an-etag']),
        ];
        yield 'success etag is oversized' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['ETag' => '"' . str_repeat('x', 255) . '"']),
        ];
        yield 'success omitted cache control' => [
            200,
            self::CONFIG_JSON,
            [
                'Content-Type' => 'application/json',
                'ETag' => self::ETAG,
            ],
        ];
        yield 'success cache control is oversized' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['Cache-Control' => str_repeat('x', 513)]),
        ];
        yield 'success content length is invalid' => [
            200,
            self::CONFIG_JSON,
            array_replace($validSuccessHeaders, ['Content-Length' => 'not-a-number']),
        ];
        yield 'malformed json' => [
            200,
            '{"broken":',
            $validSuccessHeaders,
        ];
        yield 'json array instead of contract object' => [
            200,
            '[]',
            $validSuccessHeaders,
        ];
        yield 'wrong contract version' => [
            200,
            self::configurationJson('dev.xrugc.com', version: 2),
            $validSuccessHeaders,
        ];
        yield 'unexpected top-level namespace' => [
            200,
            self::configurationJson('dev.xrugc.com', topLevelOverrides: ['debug' => (object) []]),
            $validSuccessHeaders,
        ];
        yield 'organization id mismatch' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['id' => 13]),
            $validSuccessHeaders,
        ];
        yield 'domain id mismatch' => [
            200,
            self::configurationJson('dev.xrugc.com', domainOverrides: ['id' => 35]),
            $validSuccessHeaders,
        ];
        yield 'organization name is missing' => [
            200,
            self::configurationJson('dev.xrugc.com', removeOrganizationField: 'name'),
            $validSuccessHeaders,
        ];
        yield 'organization name is not a string' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['name' => 12]),
            $validSuccessHeaders,
        ];
        yield 'organization name is empty' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['name' => '']),
            $validSuccessHeaders,
        ];
        yield 'organization title is missing' => [
            200,
            self::configurationJson('dev.xrugc.com', removeOrganizationField: 'title'),
            $validSuccessHeaders,
        ];
        yield 'organization title is not a string' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['title' => []]),
            $validSuccessHeaders,
        ];
        yield 'organization title is empty' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['title' => '']),
            $validSuccessHeaders,
        ];
        yield 'domain config key is missing' => [
            200,
            self::configurationJson('dev.xrugc.com', removeDomainField: 'configKey'),
            $validSuccessHeaders,
        ];
        yield 'domain config key is not a string' => [
            200,
            self::configurationJson('dev.xrugc.com', domainOverrides: ['configKey' => 34]),
            $validSuccessHeaders,
        ];
        yield 'domain config key is empty' => [
            200,
            self::configurationJson('dev.xrugc.com', domainOverrides: ['configKey' => '']),
            $validSuccessHeaders,
        ];
        yield 'config must be object' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['config' => []]),
            $validSuccessHeaders,
        ];
        yield 'revision must be positive integer' => [
            200,
            self::configurationJson('dev.xrugc.com', organizationOverrides: ['revision' => 0]),
            $validSuccessHeaders,
        ];
        yield 'schema version must be positive integer' => [
            200,
            self::configurationJson('dev.xrugc.com', domainOverrides: ['schemaVersion' => '1']),
            $validSuccessHeaders,
        ];
        yield 'domain config key does not match config name' => [
            200,
            self::configurationJson('xrugc.com', ['name' => 'dev.xrugc.com']),
            $validSuccessHeaders,
        ];
        yield 'domain config key contains an empty label' => [
            200,
            self::configurationJson('dev..xrugc.com', ['name' => 'dev..xrugc.com']),
            $validSuccessHeaders,
        ];
        yield 'domain config key contains a 64-character label' => [
            200,
            self::configurationJson(
                str_repeat('a', 64) . '.xrugc.com',
                ['name' => str_repeat('a', 64) . '.xrugc.com'],
            ),
            $validSuccessHeaders,
        ];
        yield 'domain config key exceeds 253 characters' => [
            200,
            self::configurationJson(
                str_repeat('a', 63)
                    . '.' . str_repeat('b', 63)
                    . '.' . str_repeat('c', 63)
                    . '.' . str_repeat('d', 62),
            ),
            $validSuccessHeaders,
        ];
        yield 'domain config omits a required field' => [
            200,
            self::configurationJson('dev.xrugc.com', removeField: 'configs'),
            $validSuccessHeaders,
        ];
        yield 'domain config active flag is not boolean' => [
            200,
            self::configurationJson('dev.xrugc.com', ['is_active' => 'true']),
            $validSuccessHeaders,
        ];
        yield 'localized domain config is not an object' => [
            200,
            self::configurationJson('dev.xrugc.com', ['configs' => ['zh-CN' => 'invalid']]),
            $validSuccessHeaders,
        ];
        yield 'external fallback has no local Unity config data' => [
            200,
            self::configurationJson('dev.xrugc.com', [
                'fallback_domain' => 'xrugc.com',
                'default_config' => (object) [],
                'configs' => (object) [],
            ]),
            $validSuccessHeaders,
        ];
        yield 'empty success' => [
            200,
            '',
            $validSuccessHeaders,
        ];
        yield 'success body exceeds final size limit' => [
            200,
            str_repeat('x', WhiteLabelGatewayService::MAX_RESPONSE_BYTES + 1),
            $validSuccessHeaders,
        ];
    }

    public function testMapsHttpClientFailureToGatewayException(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new WhiteLabelTestClientException('timeout'));

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($client)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );
    }

    /**
     * @dataProvider invalidConfigurationProvider
     */
    public function testRejectsInvalidFixedConfiguration(
        string $serviceUrl,
        string $internalToken,
    ): void {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('sendRequest');

        $this->expectException(WhiteLabelGatewayException::class);

        $this->createService($client, $serviceUrl, $internalToken)->fetch(
            self::ORGANIZATION_ID,
            self::DOMAIN_ID,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'missing URL' => ['', self::INTERNAL_TOKEN];
        yield 'unsupported scheme' => ['file:///etc/passwd', self::INTERNAL_TOKEN];
        yield 'URL credentials' => ['http://user:pass@plugin-whitelabel:3000', self::INTERNAL_TOKEN];
        yield 'URL query string' => ['http://plugin-whitelabel:3000?target=metadata', self::INTERNAL_TOKEN];
        yield 'URL fragment' => ['http://plugin-whitelabel:3000#internal', self::INTERNAL_TOKEN];
        yield 'missing token' => ['http://plugin-whitelabel:3000', ''];
        yield 'short token' => ['http://plugin-whitelabel:3000', 'too-short'];
        yield 'header injection token' => [
            'http://plugin-whitelabel:3000',
            self::INTERNAL_TOKEN . "\r\nX-Evil: true",
        ];
    }

    /**
     * @param array<string, mixed> $domainConfigOverrides
     */
    private static function configurationJson(
        string $configKey,
        array $domainConfigOverrides = [],
        ?string $removeField = null,
        array $organizationOverrides = [],
        ?string $removeOrganizationField = null,
        array $domainOverrides = [],
        ?string $removeDomainField = null,
        int $version = 1,
        array $topLevelOverrides = [],
    ): string {
        $domainConfig = array_replace([
            'name' => $configKey,
            'description' => 'XR UGC Dev',
            'is_active' => true,
            'fallback_domain' => null,
            'default_config' => (object) [],
            'configs' => ['zh-CN' => (object) []],
        ], $domainConfigOverrides);
        if ($removeField !== null) {
            unset($domainConfig[$removeField]);
        }

        $organization = array_replace([
            'id' => self::ORGANIZATION_ID,
            'name' => 'academy',
            'title' => 'Academy',
            'revision' => 1,
            'schemaVersion' => 1,
            'config' => (object) [],
        ], $organizationOverrides);
        if ($removeOrganizationField !== null) {
            unset($organization[$removeOrganizationField]);
        }

        $domain = array_replace([
            'id' => self::DOMAIN_ID,
            'configKey' => $configKey,
            'revision' => 1,
            'schemaVersion' => 1,
            'config' => $domainConfig,
        ], $domainOverrides);
        if ($removeDomainField !== null) {
            unset($domain[$removeDomainField]);
        }

        return json_encode(array_replace([
            'version' => $version,
            'organization' => $organization,
            'domain' => $domain,
        ], $topLevelOverrides), JSON_THROW_ON_ERROR);
    }

    private function createService(
        ClientInterface $client,
        string $serviceUrl = 'http://plugin-whitelabel:3000',
        string $internalToken = self::INTERNAL_TOKEN,
    ): WhiteLabelGatewayService {
        return new WhiteLabelGatewayService(
            $client,
            new RequestFactory(),
            new NullLogger(),
            $serviceUrl,
            $internalToken,
        );
    }

    private function clientReturning(ResponseInterface $response): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return $client;
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(
        int $statusCode,
        string $body = '',
        array $headers = [],
    ): ResponseInterface {
        $response = (new ResponseFactory())->createResponse($statusCode);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if ($body !== '') {
            $response = $response->withBody((new StreamFactory())->createStream($body));
        }

        return $response;
    }
}

final class WhiteLabelTestClientException extends \RuntimeException implements ClientExceptionInterface
{
}
