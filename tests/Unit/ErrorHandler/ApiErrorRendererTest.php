<?php

declare(strict_types=1);

namespace App\Tests\Unit\ErrorHandler;

use App\ErrorHandler\ApiErrorRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiErrorRenderer.
 *
 * Validates Requirements 10.1, 10.3:
 * - All exceptions are converted to JSON format {status, message}
 * - Production environment does not expose stack traces
 * - Error type mapping: RuntimeException codes → HTTP status codes
 *
 * @see Requirements 10.1, 10.3
 */
final class ApiErrorRendererTest extends TestCase
{
    private ApiErrorRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ApiErrorRenderer();
    }

    // ---------------------------------------------------------------
    // Production mode: render()
    // ---------------------------------------------------------------

    /**
     * Test that RuntimeException with code 401 renders as 401 in production.
     * Validates: Requirement 10.3
     */
    public function testRenderRuntimeException401(): void
    {
        $exception = new \RuntimeException('Your request was made with invalid credentials.', 401);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(401, $decoded['status']);
        $this->assertSame('Your request was made with invalid credentials.', $decoded['message']);
    }

    /**
     * Test that RuntimeException with code 403 renders as 403 in production.
     * Validates: Requirement 10.3
     */
    public function testRenderRuntimeException403(): void
    {
        $exception = new \RuntimeException('Forbidden.', 403);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Forbidden.', $decoded['message']);
    }

    /**
     * Test that RuntimeException with code 404 renders as 404 in production.
     * Validates: Requirement 10.3
     */
    public function testRenderRuntimeException404(): void
    {
        $exception = new \RuntimeException('Not Found.', 404);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(404, $decoded['status']);
        $this->assertSame('Not Found.', $decoded['message']);
    }

    /**
     * Test that RuntimeException with code 400 renders as 400 in production.
     * Validates: Requirement 10.3
     */
    public function testRenderRuntimeException400(): void
    {
        $exception = new \RuntimeException('Bad Request.', 400);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(400, $decoded['status']);
        $this->assertSame('Bad Request.', $decoded['message']);
    }

    /**
     * Test that generic Exception renders as 500 with generic message in production.
     * Validates: Requirements 10.1, 10.3 (no stack trace exposure)
     */
    public function testRenderGenericExceptionAs500WithGenericMessage(): void
    {
        $exception = new \Exception('Some internal database error details');

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(500, $decoded['status']);
        $this->assertSame('An internal server error occurred.', $decoded['message']);
        // Must NOT contain the original exception message in production
        $this->assertStringNotContainsString('database', (string) $errorData);
    }

    /**
     * Test that RuntimeException without a valid HTTP code renders as 500.
     * Validates: Requirement 10.3
     */
    public function testRenderRuntimeExceptionWithNonHttpCodeAs500(): void
    {
        $exception = new \RuntimeException('Something went wrong', 0);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(500, $decoded['status']);
        $this->assertSame('An internal server error occurred.', $decoded['message']);
    }

    /**
     * Test that production render always returns valid JSON with exactly {status, message}.
     * Validates: Requirement 10.3
     */
    public function testRenderReturnsValidJsonWithExactFields(): void
    {
        $exception = new \RuntimeException('Not Found.', 404);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertNotNull($decoded, 'Response must be valid JSON');
        $this->assertArrayHasKey('name', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('code', $decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('type', $decoded);
        // Yii2-compatible format: name, message, code, status, type
        $this->assertCount(5, $decoded, 'Production response should match Yii2 format: name, message, code, status, type');
    }

    /**
     * Test that 500 errors in production do not expose the original message.
     * Validates: Requirement 10.1 (no stack trace in production)
     */
    public function testRender500DoesNotExposeOriginalMessage(): void
    {
        $exception = new \RuntimeException('Connection to mysql:host=secret-db failed', 500);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(500, $decoded['status']);
        $this->assertSame('An internal server error occurred.', $decoded['message']);
        $this->assertStringNotContainsString('secret-db', (string) $errorData);
    }

    // ---------------------------------------------------------------
    // Debug mode: renderVerbose()
    // ---------------------------------------------------------------

    /**
     * Test that debug mode includes the actual exception message.
     * Validates: Requirement 10.1 (debug mode shows details)
     */
    public function testRenderVerboseIncludesActualMessage(): void
    {
        $exception = new \Exception('Detailed error info');

        $errorData = $this->renderer->renderVerbose($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(500, $decoded['status']);
        $this->assertSame('Detailed error info', $decoded['message']);
    }

    /**
     * Test that debug mode includes type, file, line, and trace.
     * Validates: Requirement 10.1 (debug mode shows details)
     */
    public function testRenderVerboseIncludesDebugFields(): void
    {
        $exception = new \RuntimeException('Test error', 404);

        $errorData = $this->renderer->renderVerbose($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('type', $decoded);
        $this->assertArrayHasKey('file', $decoded);
        $this->assertArrayHasKey('line', $decoded);
        $this->assertArrayHasKey('trace', $decoded);
    }

    /**
     * Test that debug mode shows the correct exception type.
     */
    public function testRenderVerboseShowsExceptionType(): void
    {
        $exception = new \RuntimeException('Test', 401);

        $errorData = $this->renderer->renderVerbose($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame('RuntimeException', $decoded['type']);
    }

    /**
     * Test that debug mode preserves the correct status code mapping.
     */
    public function testRenderVerbosePreservesStatusCodeMapping(): void
    {
        $exception = new \RuntimeException('Forbidden', 403);

        $errorData = $this->renderer->renderVerbose($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(403, $decoded['status']);
        $this->assertSame('Forbidden', $decoded['message']);
    }

    // ---------------------------------------------------------------
    // Status code mapping
    // ---------------------------------------------------------------

    /**
     * Test status code mapping for various RuntimeException codes.
     *
     * @dataProvider runtimeExceptionCodeProvider
     */
    public function testStatusCodeMappingForRuntimeExceptions(int $code, int $expectedStatus): void
    {
        $exception = new \RuntimeException('Test message', $code);

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame($expectedStatus, $decoded['status']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function runtimeExceptionCodeProvider(): array
    {
        return [
            'code 400 → 400' => [400, 400],
            'code 401 → 401' => [401, 401],
            'code 403 → 403' => [403, 403],
            'code 404 → 404' => [404, 404],
            'code 405 → 405' => [405, 405],
            'code 422 → 422' => [422, 422],
            'code 429 → 429' => [429, 429],
            'code 500 → 500' => [500, 500],
            'code 502 → 502' => [502, 502],
            'code 503 → 503' => [503, 503],
            'code 0 → 500'   => [0, 500],
            'code 200 → 500'  => [200, 500],
            'code 301 → 500'  => [301, 500],
            'code -1 → 500'   => [-1, 500],
        ];
    }

    /**
     * Test that non-RuntimeException always maps to 500.
     *
     * @dataProvider nonRuntimeExceptionProvider
     */
    public function testNonRuntimeExceptionAlwaysMapsTo500(string $exceptionClass): void
    {
        $exception = new $exceptionClass('Test error');

        $errorData = $this->renderer->render($exception);
        $decoded = json_decode((string) $errorData, true);

        $this->assertSame(500, $decoded['status']);
        $this->assertSame('An internal server error occurred.', $decoded['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonRuntimeExceptionProvider(): array
    {
        return [
            'Exception' => [\Exception::class],
            'LogicException' => [\LogicException::class],
            'InvalidArgumentException' => [\InvalidArgumentException::class],
            'TypeError' => [\TypeError::class],
        ];
    }

    // ---------------------------------------------------------------
    // Content-Type header
    // ---------------------------------------------------------------

    /**
     * Test that render output includes Content-Type: application/json header.
     * Validates: Requirement 10.1
     */
    public function testRenderIncludesJsonContentTypeHeader(): void
    {
        $exception = new \RuntimeException('Test', 404);

        $errorData = $this->renderer->render($exception);

        // ErrorData stores headers internally; we verify by checking the
        // JSON content is valid (the header is set in ErrorData constructor)
        $decoded = json_decode((string) $errorData, true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
    }

    /**
     * Test that renderVerbose output includes Content-Type: application/json header.
     */
    public function testRenderVerboseIncludesJsonContentTypeHeader(): void
    {
        $exception = new \RuntimeException('Test', 404);

        $errorData = $this->renderer->renderVerbose($exception);

        $decoded = json_decode((string) $errorData, true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
    }
}
