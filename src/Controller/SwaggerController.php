<?php

declare(strict_types=1);

namespace App\Controller;

use OpenApi\Attributes as OA;
use OpenApi\Generator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Swagger Documentation Controller.
 *
 * Provides Swagger UI and OpenAPI JSON schema endpoints:
 * - GET /swagger — returns Swagger UI HTML page (protected by HTTP Basic Auth)
 * - GET /swagger/json-schema — generates and returns OpenAPI JSON from code annotations
 *
 * @see Requirements 7.1, 7.2, 7.3
 */
#[OA\Info(
    title: 'MrPP API',
    version: '1.0.0',
    description: 'Mixed Reality Platform REST API',
)]
final class SwaggerController
{
    private string $username;
    private string $password;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $swaggerUsername = '',
        string $swaggerPassword = '',
    ) {
        $this->username = $swaggerUsername !== '' ? $swaggerUsername : (getenv('SWAGGER_USERNAME') ?: 'admin');
        $this->password = $swaggerPassword !== '' ? $swaggerPassword : (getenv('SWAGGER_PASSWORD') ?: 'admin');
    }

    /**
     * GET /swagger
     *
     * Returns Swagger UI HTML page. Protected by HTTP Basic Auth.
     * The UI loads Swagger UI assets from CDN and points to /swagger/json-schema
     * for the OpenAPI specification.
     *
     * @see Requirements 7.1, 7.2
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->authenticate($request)) {
            return $this->createUnauthorizedResponse();
        }

        return $this->createHtmlResponse($this->getSwaggerUiHtml());
    }

    /**
     * GET /swagger/json-schema
     *
     * Uses zircote/swagger-php to scan source code annotations
     * and generate an OpenAPI JSON schema.
     *
     * @see Requirements 7.3
     */
    #[OA\Get(
        path: '/swagger/json-schema',
        summary: 'Get OpenAPI JSON Schema',
        tags: ['Documentation'],
        responses: [
            new OA\Response(response: 200, description: 'OpenAPI JSON Schema'),
        ],
    )]
    public function jsonSchema(ServerRequestInterface $request): ResponseInterface
    {
        $scanPath = dirname(__DIR__);
        $openapi = Generator::scan([$scanPath]);

        $json = $openapi !== null ? $openapi->toJson() : json_encode(['error' => 'Failed to generate OpenAPI schema']);

        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * Authenticate the request using HTTP Basic Auth.
     *
     * Checks the Authorization header for Basic credentials and compares
     * against configured username/password.
     */
    private function authenticate(ServerRequestInterface $request): bool
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '' || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $encodedCredentials = substr($authHeader, 6);
        $decodedCredentials = base64_decode($encodedCredentials, true);

        if ($decodedCredentials === false) {
            return false;
        }

        $parts = explode(':', $decodedCredentials, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$username, $password] = $parts;

        return $username === $this->username && $password === $this->password;
    }

    /**
     * Create a 401 Unauthorized response with WWW-Authenticate header.
     */
    private function createUnauthorizedResponse(): ResponseInterface
    {
        $body = json_encode([
            'name' => 'Unauthorized',
            'message' => 'Your request was made with invalid credentials.',
            'code' => 0,
            'status' => 401,
            'type' => 'yii\\web\\UnauthorizedHttpException',
        ], JSON_THROW_ON_ERROR);

        $stream = $this->streamFactory->createStream($body);

        return $this->responseFactory->createResponse(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="Swagger API Documentation"')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * Create an HTML response.
     */
    private function createHtmlResponse(string $html): ResponseInterface
    {
        $stream = $this->streamFactory->createStream($html);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);
    }

    /**
     * Create a JSON response.
     */
    private function createJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * Get the Swagger UI HTML page content.
     *
     * Loads Swagger UI from CDN (unpkg.com) and configures it to use
     * the /swagger/json-schema endpoint for the OpenAPI specification.
     */
    private function getSwaggerUiHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MrPP API - Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            SwaggerUIBundle({
                url: "/swagger/json-schema",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout"
            });
        };
    </script>
</body>
</html>
HTML;
    }
}
