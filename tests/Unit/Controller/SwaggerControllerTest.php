<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SwaggerController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Unit tests for SwaggerController.
 *
 * Validates Requirements 7.1, 7.2, 7.3:
 * - GET /swagger returns Swagger UI HTML page
 * - GET /swagger is protected by HTTP Basic Auth
 * - GET /swagger/json-schema generates and returns OpenAPI JSON
 */
final class SwaggerControllerTest extends TestCase
{
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    protected function setUp(): void
    {
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
    }

    // ========================================================================
    // GET /swagger — Basic Auth tests
    // ========================================================================

    /**
     * Test that /swagger returns 401 when no Authorization header is provided.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithoutAuthHeader(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('');

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
        $this->assertArrayHasKey('WWW-Authenticate', $capturedHeaders);
        $this->assertStringContainsString('Basic', $capturedHeaders['WWW-Authenticate']);
    }

    /**
     * Test that /swagger returns 401 when invalid credentials are provided.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithInvalidCredentials(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('wrong:credentials'));

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    /**
     * Test that /swagger returns 401 when wrong password is provided.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithWrongPassword(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:wrongpassword'));

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    /**
     * Test that /swagger returns 401 with malformed Authorization header.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithMalformedAuthHeader(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Bearer some-token');

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    /**
     * Test that /swagger returns 401 with invalid base64 in Authorization header.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithInvalidBase64(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        // Use a string that is not valid strict base64
        $request = $this->createRequestWithAuth('Basic !!!invalid-base64!!!');

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    /**
     * Test that /swagger returns 401 when base64 decodes to string without colon.
     * Validates: Requirement 7.2
     */
    public function testIndexReturns401WithNoColonInCredentials(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('nocolonhere'));

        $controller->index($request);

        $this->assertSame(401, $capturedStatusCode);
    }

    // ========================================================================
    // GET /swagger — Successful auth tests
    // ========================================================================

    /**
     * Test that /swagger returns 200 with valid credentials.
     * Validates: Requirement 7.1
     */
    public function testIndexReturns200WithValidCredentials(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->index($request);

        $this->assertSame(200, $capturedStatusCode);
    }

    /**
     * Test that /swagger returns HTML content type with valid credentials.
     * Validates: Requirement 7.1
     */
    public function testIndexReturnsHtmlContentType(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->index($request);

        $this->assertArrayHasKey('Content-Type', $capturedHeaders);
        $this->assertStringContainsString('text/html', $capturedHeaders['Content-Type']);
    }

    /**
     * Test that /swagger returns HTML containing Swagger UI elements.
     * Validates: Requirement 7.1
     */
    public function testIndexReturnsSwaggerUiHtml(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->index($request);

        $this->assertStringContainsString('swagger-ui', $capturedBody);
        $this->assertStringContainsString('SwaggerUIBundle', $capturedBody);
        $this->assertStringContainsString('/swagger/json-schema', $capturedBody);
    }

    /**
     * Test that 401 response body contains status and message fields.
     * Validates: Requirement 7.2
     */
    public function testUnauthorizedResponseContainsStatusAndMessage(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('');

        $controller->index($request);

        $decoded = json_decode($capturedBody, true);
        $this->assertIsArray($decoded);
        $this->assertSame(401, $decoded['status']);
        $this->assertArrayHasKey('message', $decoded);
    }

    // ========================================================================
    // GET /swagger/json-schema
    // ========================================================================

    public function testJsonSchemaReturns401WithoutAuthHeader(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('');

        $controller->jsonSchema($request);

        $this->assertSame(401, $capturedStatusCode);
        $this->assertArrayHasKey('WWW-Authenticate', $capturedHeaders);
        $this->assertStringContainsString('Basic', $capturedHeaders['WWW-Authenticate']);
    }

    /**
     * Test that /swagger/json-schema returns JSON content type.
     * Validates: Requirement 7.3
     */
    public function testJsonSchemaReturnsJsonContentType(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCapture($capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->jsonSchema($request);

        $this->assertSame(200, $capturedStatusCode);
        $this->assertArrayHasKey('Content-Type', $capturedHeaders);
        $this->assertSame('application/json', $capturedHeaders['Content-Type']);
    }

    /**
     * Test that /swagger/json-schema returns valid JSON.
     * Validates: Requirement 7.3
     */
    public function testJsonSchemaReturnsValidJson(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->jsonSchema($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertNotNull($decoded, 'Response body should be valid JSON');
    }

    /**
     * Test that /swagger/json-schema exposes all public API routes.
     * Validates: Requirement 7.3
     */
    public function testJsonSchemaIncludesApplicationRoutes(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->jsonSchema($request);

        $decoded = json_decode((string) $capturedBody, true);
        $paths = array_keys($decoded['paths'] ?? []);

        $this->assertContains('/v1/auth/login', $paths);
        $this->assertContains('/v1/auth/refresh', $paths);
        $this->assertContains('/v1/auth/key-to-token', $paths);
        $this->assertContains('/v1/server/test', $paths);
        $this->assertContains('/v1/server/public', $paths);
        $this->assertContains('/v1/server/checkin', $paths);
        $this->assertContains('/v1/server/private', $paths);
        $this->assertContains('/v1/server/group', $paths);
        $this->assertContains('/v1/server/tags', $paths);
        $this->assertContains('/v1/server/snapshot', $paths);
        $this->assertContains('/v1/white-label-configs', $paths);
        $this->assertContains('/v2/snapshots', $paths);
        $this->assertContains('/v2/snapshots/{id}', $paths);
        $this->assertContains('/v2/tags', $paths);
        $this->assertContains('/v2/system', $paths);
        $this->assertContains('/health', $paths);
        $this->assertContains('/swagger', $paths);
        $this->assertContains('/swagger/json-schema', $paths);
    }

    public function testJsonSchemaDocumentsWhiteLabelDualIdContract(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $controller->jsonSchema(
            $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret')),
        );

        $decoded = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $operation = $decoded['paths']['/v1/white-label-configs']['get'];
        $parameters = [];
        foreach ($operation['parameters'] as $parameter) {
            $parameters[$parameter['name']] = $parameter;
        }

        foreach (['o', 'd'] as $name) {
            $this->assertSame('query', $parameters[$name]['in']);
            $this->assertTrue($parameters[$name]['required']);
            $this->assertSame('integer', $parameters[$name]['schema']['type']);
            $this->assertSame(1, $parameters[$name]['schema']['minimum']);
            $this->assertSame(9_007_199_254_740_991, $parameters[$name]['schema']['maximum']);
        }

        $schema = $operation['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['version', 'organization', 'domain'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        $organization = $schema['properties']['organization'];
        $this->assertSame(
            ['id', 'name', 'title', 'revision', 'schemaVersion', 'config'],
            $organization['required'],
        );
        $this->assertSame('string', $organization['properties']['name']['type']);
        $this->assertSame(1, $organization['properties']['name']['minLength']);
        $this->assertSame('string', $organization['properties']['title']['type']);
        $this->assertSame(1, $organization['properties']['title']['minLength']);

        $domain = $schema['properties']['domain'];
        $this->assertSame(
            ['id', 'host', 'revision', 'schemaVersion', 'config'],
            $domain['required'],
        );
        $this->assertSame('string', $domain['properties']['host']['type']);
        $this->assertSame(1, $domain['properties']['host']['minLength']);

        foreach (['200', '304'] as $statusCode) {
            $headers = $operation['responses'][$statusCode]['headers'];
            $this->assertTrue($headers['ETag']['required']);
            $this->assertSame('string', $headers['ETag']['schema']['type']);
            $this->assertSame(256, $headers['ETag']['schema']['maxLength']);
            $this->assertArrayHasKey('pattern', $headers['ETag']['schema']);
            $this->assertTrue($headers['Cache-Control']['required']);
            $this->assertSame('string', $headers['Cache-Control']['schema']['type']);
            $this->assertSame(1, $headers['Cache-Control']['schema']['minLength']);
            $this->assertSame(512, $headers['Cache-Control']['schema']['maxLength']);
            $this->assertArrayHasKey('pattern', $headers['Cache-Control']['schema']);
        }
    }

    /**
     * Test that /v2/snapshots documents bearer auth for protected scopes.
     * Validates: Requirement 7.3, 9.1
     */
    public function testJsonSchemaMarksV2SnapshotsWithBearerAuth(): void
    {
        $controller = new SwaggerController(
            $this->responseFactory,
            $this->streamFactory,
            'admin',
            'secret',
        );

        $capturedBody = null;
        $capturedStatusCode = null;
        $capturedHeaders = [];
        $this->setupResponseCaptureWithBody($capturedBody, $capturedStatusCode, $capturedHeaders);

        $request = $this->createRequestWithAuth('Basic ' . base64_encode('admin:secret'));

        $controller->jsonSchema($request);

        $decoded = json_decode((string) $capturedBody, true);
        $operation = $decoded['paths']['/v2/snapshots']['get'] ?? [];

        $this->assertSame([['bearerAuth' => []]], $operation['security'] ?? null);
        $this->assertArrayHasKey('401', $operation['responses'] ?? []);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createRequestWithAuth(string $authHeader): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn($authHeader);

        return $request;
    }

    /**
     * Setup response capture for status code and headers (no body capture).
     */
    private function setupResponseCapture(?int &$capturedStatusCode, array &$capturedHeaders): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$capturedHeaders) {
                $capturedHeaders[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturnCallback(function (int $statusCode) use ($response, &$capturedStatusCode) {
                $capturedStatusCode = $statusCode;
                return $response;
            });
    }

    /**
     * Setup response capture for status code, headers, and body content.
     */
    private function setupResponseCaptureWithBody(?string &$capturedBody, ?int &$capturedStatusCode, array &$capturedHeaders): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$capturedHeaders) {
                $capturedHeaders[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturnCallback(function (int $statusCode) use ($response, &$capturedStatusCode) {
                $capturedStatusCode = $statusCode;
                return $response;
            });
    }
}
