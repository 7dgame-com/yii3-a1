<?php

declare(strict_types=1);

namespace App\ErrorHandler;

use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\ErrorHandler\ErrorData;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;

/**
 * Custom JSON error renderer for the API.
 *
 * Renders all exceptions as JSON responses matching the Yii2 error format:
 * {"status": <HTTP status code>, "message": "<error message>"}
 *
 * In production mode (render), the message is generic for 500 errors to avoid
 * exposing internal details. In debug mode (renderVerbose), the actual exception
 * message, file, line, and trace are included for development convenience.
 *
 * Error type mapping:
 * - RuntimeException with code 401 → 401
 * - RuntimeException with code 403 → 403
 * - RuntimeException with code 404 → 404
 * - RuntimeException with code 400 → 400
 * - Other exceptions → 500
 *
 * @see Requirements 10.1, 10.3
 */
final class ApiErrorRenderer implements ThrowableRendererInterface
{
    private const CONTENT_TYPE = 'application/json';
    public const DEFAULT_ERROR_MESSAGE = 'An internal server error occurred.';

    /**
     * Render error for production environment.
     *
     * Returns a JSON response matching Yii2 format:
     * {name, message, code, status, type}
     */
    public function render(\Throwable $t, ?ServerRequestInterface $request = null): ErrorData
    {
        $statusCode = $this->getStatusCode($t);
        $message = $statusCode >= 500
            ? self::DEFAULT_ERROR_MESSAGE
            : $t->getMessage();

        return new ErrorData(
            json_encode(
                [
                    'name' => $this->getStatusName($statusCode),
                    'message' => $message,
                    'code' => 0,
                    'status' => $statusCode,
                    'type' => $this->getYii2ExceptionType($statusCode),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            ['Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * Render error for development environment (debug mode).
     *
     * Returns a JSON response with {status, message} plus additional debug
     * information including the exception type, file, line, and stack trace.
     */
    public function renderVerbose(\Throwable $t, ?ServerRequestInterface $request = null): ErrorData
    {
        $statusCode = $this->getStatusCode($t);

        return new ErrorData(
            json_encode(
                [
                    'status' => $statusCode,
                    'message' => $t->getMessage(),
                    'type' => $t::class,
                    'file' => $t->getFile(),
                    'line' => $t->getLine(),
                    'trace' => $t->getTrace(),
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            ['Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * Determine the HTTP status code from the exception.
     *
     * RuntimeException codes in the 4xx range are used directly.
     * All other exceptions default to 500.
     */
    private function getStatusCode(\Throwable $t): int
    {
        $code = $t->getCode();

        if ($t instanceof \RuntimeException && is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Get human-readable status name matching Yii2 format.
     */
    private function getStatusName(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Data Validation Failed.',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Error',
        };
    }

    /**
     * Get Yii2-compatible exception type string.
     */
    private function getYii2ExceptionType(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'yii\\web\\BadRequestHttpException',
            401 => 'yii\\web\\UnauthorizedHttpException',
            403 => 'yii\\web\\ForbiddenHttpException',
            404 => 'yii\\web\\NotFoundHttpException',
            405 => 'yii\\web\\MethodNotAllowedHttpException',
            429 => 'yii\\web\\TooManyRequestsHttpException',
            500 => 'yii\\web\\ServerErrorHttpException',
            default => 'yii\\web\\HttpException',
        };
    }
}
