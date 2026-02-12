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

        $request = $this->createRequestWithAuth('');

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

        $request = $this->createRequestWithAuth('');

        $controller->jsonSchema($request);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertNotNull($decoded, 'Response body should be valid JSON');
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
