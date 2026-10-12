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
#[OA\Schema(
    schema: 'DeviceSnCredentials',
    required: ['sn', 'uuid'],
    properties: [
        new OA\Property(
            property: 'sn',
            description: 'Exactly 16 Crockford Base32 characters after removing ASCII whitespace and hyphens and converting to uppercase. Historical 32-character codes are rejected.',
            type: 'string',
            maxLength: 128,
            minLength: 16,
            writeOnly: true,
        ),
        new OA\Property(
            property: 'uuid',
            description: 'Stable device identifier, trimmed and lowercased to [a-z0-9][a-z0-9._:-]{0,254}. Does not require RFC 4122 format.',
            type: 'string',
            maxLength: 255,
            minLength: 1,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DeviceSnAuthResult',
    required: ['success', 'message', 'nickname', 'token', 'user'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'login'),
        new OA\Property(property: 'nickname', type: 'string'),
        new OA\Property(
            property: 'token',
            required: ['accessToken', 'expires', 'refreshToken'],
            properties: [
                new OA\Property(property: 'accessToken', description: 'Existing y1 HS256 JWT carrying uid, auth_method=device_sn and device_sn_id; maximum lifetime 10800 seconds.', type: 'string'),
                new OA\Property(property: 'expires', description: 'Access expiry in Asia/Shanghai, formatted yyyy-MM-dd HH:mm:ss.', type: 'string', example: '2026-09-29 16:00:00'),
                new OA\Property(property: 'refreshToken', description: 'Opaque y1 refresh credential. Both refresh routes preserve and revalidate the SN source.', type: 'string'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'user',
            required: ['id', 'username', 'nickname', 'fixture'],
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'nickname', type: 'string'),
                new OA\Property(property: 'fixture', type: 'boolean', example: false),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DeviceSnAuthError',
    required: ['name', 'message', 'code', 'status', 'type'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'code', type: 'integer', example: 0),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'type', type: 'string'),
    ],
    type: 'object',
)]
#[OA\Post(
    path: '/v1/auth/sn-activate',
    operationId: 'v1AuthSnActivate',
    summary: 'Activate a device SN and issue y1 tokens',
    description: 'Binds the stable UUID in the shared device_sn table. The same pair is idempotent; other bindings cannot be replaced. Binding survives a later token-issuance failure. Only eligible ordinary accounts are accepted. Responses use Cache-Control: no-store and Pragma: no-cache. No previous Bearer token is required.',
    security: [],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnCredentials')),
    responses: [
        new OA\Response(response: 200, description: 'Authenticated with the device SN source', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthResult')),
        new OA\Response(response: 400, description: 'SN or UUID format is invalid', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(response: 401, description: 'SN, account or device authorization is invalid', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(response: 409, description: 'Activation is required, a binding conflicts, or a concurrent binding must be retried with the same pair', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(
            response: 429,
            description: 'Device authentication rate limit exceeded',
            headers: [new OA\Header(header: 'Retry-After', description: 'Delay in seconds before retrying.', schema: new OA\Schema(type: 'integer', example: 60))],
            content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError'),
        ),
        new OA\Response(response: 503, description: 'Authoritative storage, rate limiting or token issuance is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
    ],
)]
#[OA\Post(
    path: '/v1/auth/sn-login',
    operationId: 'v1AuthSnLogin',
    summary: 'Log in an activated device using SN and UUID',
    description: 'Requires the existing SN and UUID binding; does not implicitly activate. Disabled or account-deleted SNs cannot log in. Uses the same y1 HS256 issuer as username/password login. Responses use Cache-Control: no-store and Pragma: no-cache. No previous Bearer token is required.',
    security: [],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnCredentials')),
    responses: [
        new OA\Response(response: 200, description: 'Authenticated with the device SN source', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthResult')),
        new OA\Response(response: 400, description: 'SN or UUID format is invalid', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(response: 401, description: 'SN, account or device authorization is invalid', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(response: 409, description: 'Activation is required, a binding conflicts, or a concurrent binding must be retried with the same pair', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(
            response: 429,
            description: 'Device authentication rate limit exceeded',
            headers: [new OA\Header(header: 'Retry-After', description: 'Delay in seconds before retrying.', schema: new OA\Schema(type: 'integer', example: 60))],
            content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError'),
        ),
        new OA\Response(response: 503, description: 'Authoritative storage, rate limiting or token issuance is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
    ],
)]
#[OA\Post(
    path: '/v1/auth/logout',
    operationId: 'v1AuthLogout',
    summary: 'Revoke a y1 refresh credential',
    description: 'Deletes only the supplied refresh credential. SN and UUID binding remain intact; existing access tokens retain their normal expiry. Repeated logout succeeds. No Bearer token is required. Responses use Cache-Control: no-store and Pragma: no-cache.',
    security: [],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refreshToken'],
            properties: [new OA\Property(property: 'refreshToken', description: 'Non-blank y1 refresh credential to revoke.', type: 'string', maxLength: 256, minLength: 1, writeOnly: true)],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Refresh credential revoked or already absent',
            content: new OA\JsonContent(
                required: ['success', 'message'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'logout'),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'refreshToken is missing, blank, not a string or longer than 256 bytes', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
        new OA\Response(response: 503, description: 'Refresh-token storage is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/DeviceSnAuthError')),
    ],
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
    description: 'Compatibility endpoint for y1 refresh tokens and existing login codes. Device SN refresh credentials preserve auth_method and device_sn_id and revalidate current authorization; they never fall back to login-code exchange. Responses use Cache-Control: no-store.',
    security: [],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refreshToken'],
            properties: [
                new OA\Property(property: 'refreshToken', type: 'string', maxLength: 2048, minLength: 1),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(response: 200, description: 'Token refreshed'),
        new OA\Response(response: 400, description: 'Invalid request'),
        new OA\Response(response: 401, description: 'Refresh credential, account or device SN authorization is invalid'),
        new OA\Response(response: 503, description: 'Refresh-token storage or device SN authorization storage is unavailable'),
    ],
)]
#[OA\Post(
    path: '/v2/auth/login',
    operationId: 'v2AuthLogin',
    summary: 'Authenticate with username and password using the V2 response contract',
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
        new OA\Response(
            response: 200,
            description: 'Authenticated',
            content: new OA\JsonContent(
                required: ['success', 'message', 'nickname', 'token', 'user'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'keyToTokenWithUrl'),
                    new OA\Property(property: 'nickname', type: 'string'),
                    new OA\Property(
                        property: 'token',
                        required: ['accessToken', 'expires', 'refreshToken'],
                        properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'expires', type: 'string', example: '2026-08-28 12:00:00'),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(
                        property: 'user',
                        required: ['id', 'username', 'nickname', 'fixture'],
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'username', type: 'string'),
                            new OA\Property(property: 'nickname', type: 'string'),
                            new OA\Property(property: 'fixture', type: 'boolean', example: false),
                        ],
                        type: 'object',
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'username or password is missing or is not a non-empty string'),
        new OA\Response(response: 401, description: 'Invalid username or password'),
    ],
)]
#[OA\Post(
    path: '/v2/auth/refresh-token',
    operationId: 'v2AuthRefreshToken',
    summary: 'Rotate a genuine refresh token',
    description: 'Accepts only a refresh token issued by this service. Login codes and QR transport wrappers are never resolved by this endpoint. Device SN sessions preserve auth_method and device_sn_id and revalidate current authorization. Responses use Cache-Control: no-store.',
    security: [],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refreshToken'],
            properties: [
                new OA\Property(
                    property: 'refreshToken',
                    description: 'Refresh token returned by a previous successful authentication or refresh.',
                    type: 'string',
                    maxLength: 256,
                    minLength: 1,
                ),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Refresh token rotated',
            content: new OA\JsonContent(
                required: ['success', 'message', 'nickname', 'token', 'user'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'keyToTokenWithUrl'),
                    new OA\Property(property: 'nickname', type: 'string'),
                    new OA\Property(
                        property: 'token',
                        required: ['accessToken', 'expires', 'refreshToken'],
                        properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'expires', type: 'string', example: '2026-08-28 12:00:00'),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(
                        property: 'user',
                        required: ['id', 'username', 'nickname', 'fixture'],
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'username', type: 'string'),
                            new OA\Property(property: 'nickname', type: 'string'),
                            new OA\Property(property: 'fixture', type: 'boolean', example: false),
                        ],
                        type: 'object',
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'refreshToken is missing or is not a non-empty string'),
        new OA\Response(response: 401, description: 'Refresh token is invalid, expired, already consumed, or its device SN authorization is no longer valid'),
        new OA\Response(response: 503, description: 'Refresh-token storage or device SN authorization storage is unavailable'),
    ],
)]
#[OA\Post(
    path: '/v2/auth/login-code',
    operationId: 'v2AuthLoginCode',
    summary: 'Authenticate with a short-lived login code',
    description: 'Accepts only a login code. Bare codes are canonical; existing web_ and QR URL transport forms remain accepted. Refresh-token storage is never queried by this endpoint.',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['loginCode'],
            properties: [
                new OA\Property(
                    property: 'loginCode',
                    description: 'Short-lived QR login code, optionally wrapped in an existing web_ transport form.',
                    type: 'string',
                ),
            ],
            type: 'object',
        ),
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Authenticated with a login code',
            content: new OA\JsonContent(
                required: ['success', 'message', 'nickname', 'token', 'user', 'url'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'keyToTokenWithUrl'),
                    new OA\Property(property: 'nickname', type: 'string'),
                    new OA\Property(
                        property: 'token',
                        required: ['accessToken', 'expires', 'refreshToken'],
                        properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'expires', type: 'string', example: '2026-08-28 12:00:00'),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(
                        property: 'user',
                        required: ['id', 'username', 'nickname', 'fixture'],
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'username', type: 'string'),
                            new OA\Property(property: 'nickname', type: 'string'),
                            new OA\Property(property: 'fixture', type: 'boolean', example: false),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(
                        property: 'url',
                        description: 'Always present. HTTPS URL derived from the trusted login-code domain, or null when the code has no domain context.',
                        type: 'string',
                        format: 'uri',
                        nullable: true,
                        example: 'https://d.dev.xrugc.com',
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'loginCode is missing or is not a non-empty string'),
        new OA\Response(response: 401, description: 'Login code is invalid or expired'),
        new OA\Response(response: 503, description: 'Login-code storage is unavailable'),
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
#[OA\Post(
    path: '/v1/auth/key-to-token-with-url',
    operationId: 'v1AuthKeyToTokenWithUrl',
    summary: 'Exchange a login code for tokens and its frontend URL',
    description: 'Returns a token payload and, when available, an HTTPS URL derived from the trusted frontend domain captured at issue time. Existing token endpoints are unchanged.',
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
        new OA\Response(
            response: 200,
            description: 'Authenticated with frontend URL',
            content: new OA\JsonContent(
                required: ['success', 'message', 'nickname', 'token', 'user'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'keyToTokenWithUrl'),
                    new OA\Property(property: 'nickname', type: 'string'),
                    new OA\Property(
                        property: 'token',
                        required: ['accessToken', 'expires', 'refreshToken'],
                        properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'expires', type: 'string'),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(property: 'user', type: 'object'),
                    new OA\Property(
                        property: 'url',
                        description: 'Present only when the login code contains a trusted frontend domain.',
                        type: 'string',
                        format: 'uri',
                        example: 'https://d.dev.xrugc.com',
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'Login code is invalid or expired'),
        new OA\Response(response: 503, description: 'Login-code storage is unavailable'),
    ],
)]
#[OA\Post(
    path: '/v1/auth/login-code-context',
    operationId: 'v1AuthLoginCodeContext',
    summary: 'Read frontend metadata for an active login code',
    description: 'Returns metadata only. The frontend domain is not an authorization decision.',
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
        new OA\Response(
            response: 200,
            description: 'Login-code context resolved',
            content: new OA\JsonContent(
                required: ['success', 'message', 'frontendDomain'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'loginCodeContext'),
                    new OA\Property(
                        property: 'frontendDomain',
                        description: 'Originating frontend host; null for compatible legacy records.',
                        type: 'string',
                        nullable: true,
                        example: 'd.dev.xrugc.com',
                    ),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 400, description: 'Login code is invalid or expired'),
        new OA\Response(response: 503, description: 'Login-code storage is unavailable'),
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
