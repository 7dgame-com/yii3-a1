<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\ErrorHandler\ApiErrorRenderer;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for error response format consistency.
 *
 * Feature: yii2-to-yii3-migration, Property 18: 错误响应格式一致性
 *
 * **Validates: Requirements 10.3**
 *
 * Property 18: For any HTTP error status code (4xx, 5xx), the API JSON response
 * body must contain "status" and "message" fields.
 */
final class ErrorResponsePropertyTest extends TestCase
{
    use TestTrait;

    private ApiErrorRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ApiErrorRenderer();
    }

    /**
     * Property 18a: render() always produces valid JSON with "status" and "message" fields
     * for any HTTP error code (400–599).
     *
     * Generates random error codes in the 400–599 range with random messages,
     * creates RuntimeException instances, and verifies the rendered output.
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testRenderAlwaysContainsStatusAndMessageFields(): void
    {
        $this->forAll(
            Generator\choose(400, 599),
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (int $code, string $message): void {
                $exception = new \RuntimeException($message, $code);
                $errorData = $this->renderer->render($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertNotNull($decoded, 'render() output must be valid JSON');
                $this->assertArrayHasKey('status', $decoded, 'JSON must contain "status" field');
                $this->assertArrayHasKey('message', $decoded, 'JSON must contain "message" field');
            });
    }

    /**
     * Property 18b: render() status field matches the expected HTTP status code.
     *
     * For any RuntimeException with code in 400–599, the rendered "status" field
     * must equal that code.
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testRenderStatusMatchesExceptionCode(): void
    {
        $this->forAll(
            Generator\choose(400, 599),
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (int $code, string $message): void {
                $exception = new \RuntimeException($message, $code);
                $errorData = $this->renderer->render($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertSame(
                    $code,
                    $decoded['status'],
                    sprintf('Expected status %d but got %d', $code, $decoded['status'])
                );
            });
    }

    /**
     * Property 18c: render() hides original message for 5xx errors (generic message).
     *
     * For any RuntimeException with code in 500–599, the rendered "message" must
     * be the generic error message, not the original exception message.
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testRender5xxUsesGenericMessage(): void
    {
        $this->forAll(
            Generator\choose(500, 599),
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (int $code, string $message): void {
                $exception = new \RuntimeException($message, $code);
                $errorData = $this->renderer->render($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertSame(
                    ApiErrorRenderer::DEFAULT_ERROR_MESSAGE,
                    $decoded['message'],
                    '5xx errors in production must use the generic error message'
                );
            });
    }

    /**
     * Property 18d: render() preserves original message for 4xx errors.
     *
     * For any RuntimeException with code in 400–499, the rendered "message"
     * must be the original exception message.
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testRender4xxPreservesOriginalMessage(): void
    {
        $this->forAll(
            Generator\choose(400, 499),
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (int $code, string $message): void {
                $exception = new \RuntimeException($message, $code);
                $errorData = $this->renderer->render($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertSame(
                    $message,
                    $decoded['message'],
                    '4xx errors must preserve the original exception message'
                );
            });
    }

    /**
     * Property 18e: renderVerbose() always contains "status" and "message" fields
     * for any HTTP error code (400–599).
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testRenderVerboseAlwaysContainsStatusAndMessage(): void
    {
        $this->forAll(
            Generator\choose(400, 599),
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (int $code, string $message): void {
                $exception = new \RuntimeException($message, $code);
                $errorData = $this->renderer->renderVerbose($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertNotNull($decoded, 'renderVerbose() output must be valid JSON');
                $this->assertArrayHasKey('status', $decoded, 'JSON must contain "status" field');
                $this->assertArrayHasKey('message', $decoded, 'JSON must contain "message" field');
                $this->assertSame($code, $decoded['status']);
                $this->assertSame($message, $decoded['message']);
            });
    }

    /**
     * Property 18f: Non-RuntimeException always maps to status 500 with generic message.
     *
     * For any random message, a plain Exception (non-RuntimeException) should
     * always render as status 500 with the generic error message in production mode.
     *
     * **Validates: Requirements 10.3**
     *
     * @eris-repeat 100
     */
    public function testNonRuntimeExceptionAlwaysMapsTo500(): void
    {
        $this->forAll(
            Generator\suchThat(
                static fn (string $s): bool => $s !== '',
                Generator\string()
            )
        )
            ->then(function (string $message): void {
                $exception = new \Exception($message);
                $errorData = $this->renderer->render($exception);
                $decoded = json_decode((string) $errorData, true);

                $this->assertNotNull($decoded, 'Output must be valid JSON');
                $this->assertArrayHasKey('status', $decoded);
                $this->assertArrayHasKey('message', $decoded);
                $this->assertSame(500, $decoded['status']);
                $this->assertSame(
                    ApiErrorRenderer::DEFAULT_ERROR_MESSAGE,
                    $decoded['message'],
                    'Non-RuntimeException must use generic message in production'
                );
            });
    }
}
