<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Service\WhiteLabelGatewayException;
use App\Service\WhiteLabelGatewayInterface;
use App\Service\WhiteLabelGatewayResponse;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Public, read-only Unity gateway for enabled white-label configurations.
 */
final class WhiteLabelController
{
    private const MAX_SAFE_ID = 9_007_199_254_740_991;
    private const MAX_ETAG_BYTES = 256;
    private const MAX_CACHE_CONTROL_BYTES = 512;

    public function __construct(
        private readonly WhiteLabelGatewayInterface $gateway,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    #[OA\Get(
        path: '/v1/white-label-configs',
        operationId: 'v1WhiteLabelConfigResolve',
        summary: 'Resolve enabled organization and domain white-label configurations',
        description: 'Read-only gateway keyed by positive organization and domain IDs. ETag and Cache-Control are preserved.',
        tags: ['White Label'],
        parameters: [
            new OA\QueryParameter(
                name: 'o',
                description: 'Organization ID',
                required: true,
                schema: new OA\Schema(
                    type: 'integer',
                    format: 'int64',
                    minimum: 1,
                    maximum: self::MAX_SAFE_ID,
                    example: 12,
                ),
            ),
            new OA\QueryParameter(
                name: 'd',
                description: 'Domain configuration ID',
                required: true,
                schema: new OA\Schema(
                    type: 'integer',
                    format: 'int64',
                    minimum: 1,
                    maximum: self::MAX_SAFE_ID,
                    example: 34,
                ),
            ),
            new OA\HeaderParameter(
                name: 'If-None-Match',
                description: 'Previously returned white-label ETag',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Independent organization and domain JSON namespaces; includes ETag and Cache-Control',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Validator for the exact organization/domain configuration pair',
                        required: true,
                        schema: new OA\Schema(
                            type: 'string',
                            maxLength: self::MAX_ETAG_BYTES,
                            pattern: '^(?:W/)?"[\x21\x23-\x7E]*"$',
                        ),
                    ),
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Caching policy supplied by the white-label service',
                        required: true,
                        schema: new OA\Schema(
                            type: 'string',
                            minLength: 1,
                            maxLength: self::MAX_CACHE_CONTROL_BYTES,
                            pattern: '^[\x20-\x7E]+$',
                        ),
                    ),
                ],
                content: new OA\JsonContent(
                    required: ['version', 'organization', 'domain'],
                    properties: [
                        new OA\Property(property: 'version', type: 'integer', enum: [1]),
                        new OA\Property(
                            property: 'organization',
                            required: ['id', 'name', 'title', 'revision', 'schemaVersion', 'config'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1),
                                new OA\Property(property: 'name', type: 'string', minLength: 1),
                                new OA\Property(property: 'title', type: 'string', minLength: 1),
                                new OA\Property(property: 'revision', type: 'integer', minimum: 1),
                                new OA\Property(property: 'schemaVersion', type: 'integer', minimum: 1),
                                new OA\Property(property: 'config', type: 'object'),
                            ],
                            type: 'object',
                        ),
                        new OA\Property(
                            property: 'domain',
                            required: ['id', 'configKey', 'revision', 'schemaVersion', 'config'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1),
                                new OA\Property(
                                    property: 'configKey',
                                    description: 'Main-frontend static domain configuration key, not an exact request hostname',
                                    type: 'string',
                                    minLength: 1,
                                    maxLength: 253,
                                    pattern: '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$',
                                ),
                                new OA\Property(property: 'revision', type: 'integer', minimum: 1),
                                new OA\Property(property: 'schemaVersion', type: 'integer', minimum: 1),
                                new OA\Property(
                                    property: 'config',
                                    description: 'Independent main-frontend StaticDomainConfig snapshot; name must equal domain.configKey',
                                    required: [
                                        'name',
                                        'description',
                                        'is_active',
                                        'fallback_domain',
                                        'default_config',
                                        'configs',
                                    ],
                                    properties: [
                                        new OA\Property(
                                            property: 'name',
                                            description: 'Must exactly equal the containing domain.configKey',
                                            type: 'string',
                                            minLength: 1,
                                            maxLength: 253,
                                            pattern: '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$',
                                        ),
                                        new OA\Property(
                                            property: 'description',
                                            type: 'string',
                                        ),
                                        new OA\Property(property: 'is_active', type: 'boolean'),
                                        new OA\Property(
                                            property: 'fallback_domain',
                                            description: 'Format-compatible metadata only; A1 does not dereference it and external fallbacks require local config data',
                                            type: 'string',
                                            maxLength: 253,
                                            pattern: '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$',
                                            nullable: true,
                                        ),
                                        new OA\Property(
                                            property: 'default_config',
                                            type: 'object',
                                            additionalProperties: true,
                                        ),
                                        new OA\Property(
                                            property: 'configs',
                                            type: 'object',
                                            additionalProperties: new OA\AdditionalProperties(
                                                type: 'object',
                                            ),
                                        ),
                                    ],
                                    type: 'object',
                                    additionalProperties: true,
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                    additionalProperties: false,
                ),
            ),
            new OA\Response(
                response: 304,
                description: 'The client cache is current',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Validator that matched If-None-Match',
                        required: true,
                        schema: new OA\Schema(
                            type: 'string',
                            maxLength: self::MAX_ETAG_BYTES,
                            pattern: '^(?:W/)?"[\x21\x23-\x7E]*"$',
                        ),
                    ),
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Caching policy supplied by the white-label service',
                        required: true,
                        schema: new OA\Schema(
                            type: 'string',
                            minLength: 1,
                            maxLength: self::MAX_CACHE_CONTROL_BYTES,
                            pattern: '^[\x20-\x7E]+$',
                        ),
                    ),
                ],
            ),
            new OA\Response(response: 400, description: 'o and d must both be positive integers'),
            new OA\Response(response: 404, description: 'Configuration pair not found, mismatched, or disabled'),
            new OA\Response(response: 503, description: 'White-label service unavailable'),
        ],
    )]
    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $organizationId = $this->parsePositiveInteger($query['o'] ?? null);
        $domainId = $this->parsePositiveInteger($query['d'] ?? null);

        if ($organizationId === null || $domainId === null) {
            return $this->createErrorResponse(
                400,
                'Query parameters o and d must be positive integers.',
            );
        }

        try {
            $upstream = $this->gateway->fetch(
                $organizationId,
                $domainId,
                $request->getHeaderLine('If-None-Match'),
            );
        } catch (WhiteLabelGatewayException) {
            return $this->createErrorResponse(
                503,
                'White-label configuration service is unavailable.',
            );
        }

        return match ($upstream->statusCode) {
            200 => $this->createSuccessResponse($upstream),
            304 => $this->createNotModifiedResponse($upstream),
            404 => $this->createErrorResponse(404, 'White-label configuration not found.'),
            default => $this->createErrorResponse(
                503,
                'White-label configuration service is unavailable.',
            ),
        };
    }

    private function createSuccessResponse(WhiteLabelGatewayResponse $upstream): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $upstream->contentType ?? 'application/json')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream($upstream->body));

        return $this->applyCacheHeaders($response, $upstream);
    }

    private function createNotModifiedResponse(WhiteLabelGatewayResponse $upstream): ResponseInterface
    {
        return $this->applyCacheHeaders(
            $this->responseFactory->createResponse(304),
            $upstream,
        );
    }

    private function applyCacheHeaders(
        ResponseInterface $response,
        WhiteLabelGatewayResponse $upstream,
    ): ResponseInterface {
        if ($upstream->etag !== null) {
            $response = $response->withHeader('ETag', $upstream->etag);
        }

        if ($upstream->cacheControl !== null) {
            $response = $response->withHeader('Cache-Control', $upstream->cacheControl);
        }

        return $response;
    }

    private function createErrorResponse(int $statusCode, string $message): ResponseInterface
    {
        $body = json_encode([
            'name' => match ($statusCode) {
                400 => 'Bad Request',
                404 => 'Not Found',
                default => 'Service Unavailable',
            },
            'message' => $message,
            'code' => 0,
            'status' => $statusCode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream($body));
    }

    private function parsePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= self::MAX_SAFE_ID ? $value : null;
        }

        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $maximum = (string) self::MAX_SAFE_ID;
        if (
            strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $value;
    }
}
