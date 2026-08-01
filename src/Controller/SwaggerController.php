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
#[OA\Tag(name: 'Authentication', description: 'Authentication')]
#[OA\Tag(name: 'V1 Server', description: 'Yii2-compatible V1 server endpoints')]
#[OA\Tag(name: 'V2', description: 'V2 snapshot, tag, and system endpoints')]
#[OA\Tag(name: 'System', description: 'Service health and status endpoints')]
#[OA\Tag(name: 'Documentation', description: 'Documentation')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'JWT access token returned by the authentication endpoints.',
    bearerFormat: 'JWT',
    scheme: 'bearer',
)]
#[OA\Post(
    path: '/v1/auth/login',
    operationId: 'v1AuthLogin',
    summary: 'Authenticate with username and password',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['username', 'password'],
            properties: [
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(response: 200, description: 'Authenticated'),
        new OA\Response(response: 400, description: 'Invalid request'),
    ],
)]
#[OA\Post(
    path: '/v1/auth/refresh',
    operationId: 'v1AuthRefresh',
    summary: 'Refresh an access token',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refreshToken'],
            properties: [
                new OA\Property(property: 'refreshToken', type: 'string'),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(response: 200, description: 'Token refreshed'),
        new OA\Response(response: 400, description: 'Invalid request'),
    ],
)]
#[OA\Post(
    path: '/v1/auth/key-to-token',
    operationId: 'v1AuthKeyToToken',
    summary: 'Exchange a linked key for tokens',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['key'],
            properties: [
                new OA\Property(property: 'key', type: 'string'),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(response: 200, description: 'Authenticated'),
        new OA\Response(response: 400, description: 'Invalid request'),
    ],
)]
#[OA\Get(
    path: '/v1/server/test',
    operationId: 'v1ServerTest',
    summary: 'Test response',
    tags: ['V1 Server'],
    responses: [
        new OA\Response(response: 200, description: 'Test response'),
    ],
)]
#[OA\Get(
    path: '/v1/server/public',
    operationId: 'v1ServerPublic',
    summary: 'List public snapshots',
    tags: ['V1 Server'],
    parameters: [
        new OA\QueryParameter(name: 'pageSize', description: 'Page size', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'page', description: 'Page number', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'tags', description: 'Comma-separated tag IDs', schema: new OA\Schema(type: 'string')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Public snapshots'),
    ],
)]
#[OA\Get(
    path: '/v1/server/checkin',
    operationId: 'v1ServerCheckin',
    summary: 'List checkin snapshots',
    tags: ['V1 Server'],
    parameters: [
        new OA\QueryParameter(name: 'pageSize', description: 'Page size', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'page', description: 'Page number', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'tags', description: 'Comma-separated tag IDs', schema: new OA\Schema(type: 'string')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Checkin snapshots'),
    ],
)]
#[OA\Get(
    path: '/v1/server/private',
    operationId: 'v1ServerPrivate',
    summary: 'List private snapshots for the authenticated user',
    tags: ['V1 Server'],
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\QueryParameter(name: 'pageSize', description: 'Page size', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'page', description: 'Page number', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'tags', description: 'Comma-separated tag IDs', schema: new OA\Schema(type: 'string')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Private snapshots'),
        new OA\Response(response: 401, description: 'Unauthorized'),
    ],
)]
#[OA\Get(
    path: '/v1/server/group',
    operationId: 'v1ServerGroup',
    summary: 'List group snapshots for the authenticated user',
    tags: ['V1 Server'],
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\QueryParameter(name: 'pageSize', description: 'Page size', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'page', description: 'Page number', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'tags', description: 'Comma-separated tag IDs', schema: new OA\Schema(type: 'string')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Group snapshots'),
        new OA\Response(response: 401, description: 'Unauthorized'),
    ],
)]
#[OA\Get(
    path: '/v1/server/tags',
    operationId: 'v1ServerTags',
    summary: 'List tags',
    tags: ['V1 Server'],
    parameters: [
        new OA\QueryParameter(name: 'type', description: 'Tag type, defaults to Classify', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Tags'),
    ],
)]
#[OA\Get(
    path: '/v1/server/snapshot',
    operationId: 'v1ServerSnapshot',
    summary: 'Get one snapshot by id or verse_id',
    tags: ['V1 Server'],
    parameters: [
        new OA\QueryParameter(name: 'id', description: 'Snapshot ID', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'verse_id', description: 'Verse ID', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Snapshot'),
        new OA\Response(response: 400, description: 'Invalid request'),
    ],
)]
#[OA\Get(
    path: '/v2/snapshots',
    operationId: 'v2SnapshotsIndex',
    summary: 'List snapshots by scope',
    description: 'scope=group and scope=private require a JWT bearer token.',
    tags: ['V2'],
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\QueryParameter(name: 'scope', description: 'Snapshot scope', schema: new OA\Schema(type: 'string', enum: ['public', 'checkin', 'group', 'private'])),
        new OA\QueryParameter(name: 'pageSize', description: 'Page size', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'page', description: 'Page number', schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'tags', description: 'Comma-separated tag IDs', schema: new OA\Schema(type: 'string')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Snapshots'),
        new OA\Response(response: 400, description: 'Invalid scope'),
        new OA\Response(response: 401, description: 'Unauthorized'),
        new OA\Response(response: 403, description: 'Login required'),
    ],
)]
#[OA\Get(
    path: '/v2/snapshots/{id}',
    operationId: 'v2SnapshotsView',
    summary: 'Get one snapshot by ID',
    tags: ['V2'],
    parameters: [
        new OA\PathParameter(name: 'id', description: 'Snapshot ID', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\QueryParameter(name: 'expand', description: 'Comma-separated expansion fields', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Snapshot'),
        new OA\Response(response: 404, description: 'Snapshot not found'),
    ],
)]
#[OA\Get(
    path: '/v2/tags',
    operationId: 'v2TagsIndex',
    summary: 'List tags',
    tags: ['V2'],
    responses: [
        new OA\Response(response: 200, description: 'Tags'),
    ],
)]
#[OA\Get(
    path: '/v2/system',
    operationId: 'v2SystemIndex',
    summary: 'Get system status',
    tags: ['System'],
    responses: [
        new OA\Response(response: 200, description: 'System status'),
    ],
)]
#[OA\Head(
    path: '/v2/system',
    operationId: 'v2SystemHead',
    summary: 'Get system status headers',
    tags: ['System'],
    responses: [
        new OA\Response(response: 200, description: 'System status'),
    ],
)]
#[OA\Get(
    path: '/health',
    operationId: 'health',
    summary: 'Check database and Redis health',
    tags: ['System'],
    responses: [
        new OA\Response(response: 200, description: 'Healthy'),
        new OA\Response(response: 503, description: 'Unhealthy'),
    ],
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
    #[OA\Get(
        path: '/swagger',
        summary: 'Get Swagger UI',
        tags: ['Documentation'],
        responses: [
            new OA\Response(response: 200, description: 'Swagger UI'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
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
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    public function jsonSchema(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->authenticate($request)) {
            return $this->createUnauthorizedResponse();
        }

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
