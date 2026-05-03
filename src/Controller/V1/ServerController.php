<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Model\Snapshot;
use App\Service\PaginatedResult;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * V1 Server Controller.
 *
 * Handles scene/snapshot query endpoints:
 * - GET /v1/server/test: Test response
 * - GET /v1/server/public: Public snapshots (with pagination and tag filtering)
 * - GET /v1/server/checkin: Checkin snapshots (with pagination and tag filtering)
 * - GET /v1/server/private: Private snapshots for authenticated user
 * - GET /v1/server/group: Group snapshots for authenticated user
 * - GET /v1/server/tags: Tags list (type=Classify)
 * - GET /v1/server/snapshot: Single snapshot by id or verse_id
 *
 * All list endpoints return paginated results with X-Pagination-* headers.
 * Error responses use Yii2-compatible {status, message} format.
 *
 * @see Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9
 */
final class ServerController
{
    public function __construct(
        private readonly SnapshotQueryService $snapshotQueryService,
        private readonly PaginationService $paginationService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Yii2RestResponseFactory $restResponseFactory,
    ) {
    }

    /**
     * GET /v1/server/test
     *
     * Returns a simple test response to verify the endpoint is working.
     *
     * @see Requirement 4.1
     */
    public function test(?ServerRequestInterface $request = null): ResponseInterface
    {
        if ($request !== null) {
            return $this->restResponseFactory->create($request, 'test');
        }

        $stream = $this->streamFactory->createStream('"test"');

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withBody($stream);
    }

    /**
     * GET /v1/server/public
     *
     * Returns paginated list of public snapshots.
     * Supports query parameters: pageSize, page, tags (comma-separated tag IDs).
     *
     * @see Requirements 4.2, 4.8, 4.9
     */
    public function listPublic(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $result = $this->snapshotQueryService->findPublic($params);
        $expandFields = $this->parseExpand($request);

        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * GET /v1/server/checkin
     *
     * Returns paginated list of checkin snapshots.
     * Supports query parameters: pageSize, page, tags (comma-separated tag IDs).
     *
     * @see Requirements 4.3, 4.8, 4.9
     */
    public function checkin(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $result = $this->snapshotQueryService->findCheckin($params);
        $expandFields = $this->parseExpand($request);

        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * GET /v1/server/private
     *
     * Returns paginated list of private snapshots for the authenticated user.
     * Requires JWT authentication (user data from request attribute).
     * Supports query parameters: pageSize, page, tags (comma-separated tag IDs).
     *
     * @see Requirements 4.4, 4.8, 4.9
     */
    public function listPrivate(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || !isset($user['user_id'])) {
            return $this->createErrorResponse($request, 401, 'Your request was made with invalid credentials.');
        }

        $params = $request->getQueryParams();
        $result = $this->snapshotQueryService->findPrivate((int) $user['user_id'], $params);
        $expandFields = $this->parseExpand($request);

        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * GET /v1/server/group
     *
     * Returns paginated list of group snapshots for the authenticated user.
     * Requires JWT authentication (user data from request attribute).
     * Supports query parameters: pageSize, page, tags (comma-separated tag IDs).
     *
     * @see Requirements 4.5, 4.8, 4.9
     */
    public function group(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || !isset($user['user_id'])) {
            return $this->createErrorResponse($request, 401, 'Your request was made with invalid credentials.');
        }

        $params = $request->getQueryParams();
        $result = $this->snapshotQueryService->findGroup((int) $user['user_id'], $params);
        $expandFields = $this->parseExpand($request);

        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * GET /v1/server/tags
     *
     * Returns list of tags with type='Classify'.
     *
     * @see Requirement 4.6
     */
    public function tags(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $type = $params['type'] ?? 'Classify';
        $tags = $this->snapshotQueryService->findTags((string) $type);

        return $this->createResponse($request, $tags);
    }

    /**
     * GET /v1/server/snapshot
     *
     * Returns a single snapshot by id or verse_id query parameter.
     * If both are provided, id takes precedence.
     * Returns 404 if no snapshot is found.
     *
     * @see Requirement 4.7
     */
    public function snapshot(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $expandFields = $this->parseExpand($request);

        if (isset($params['id'])) {
            $snapshot = $this->snapshotQueryService->findSnapshotModel((int) $params['id']);
        } elseif (isset($params['verse_id'])) {
            $snapshot = $this->snapshotQueryService->findSnapshotModelByVerseId((int) $params['verse_id']);
        } else {
            return $this->createErrorResponse($request, 400, 'id or verse_id is required.');
        }

        if ($snapshot === null) {
            return $this->createErrorResponse($request, 400, 'Snapshot not found.');
        }

        if (empty($expandFields)) {
            return $this->createResponse($request, $snapshot->jsonSerialize());
        }
        return $this->createResponse($request, $snapshot->toExpandedArray($expandFields));
    }

    /**
     * Create a JSON response with pagination headers for list endpoints.
     *
     * @param PaginatedResult $result The paginated result.
     */
    private function createPaginatedJsonResponse(ServerRequestInterface $request, PaginatedResult $result): ResponseInterface
    {
        $response = $this->createResponse($request, $result->items);

        return $this->paginationService->applyHeaders($response, $result);
    }

    /**
     * Create a paginated JSON response for snapshot items, handling expand.
     * Matches Yii2 REST serializer behavior: fields()=[] by default, expand adds extraFields.
     */
    private function createPaginatedSnapshotResponse(
        ServerRequestInterface $request,
        PaginatedResult $result,
        array $expandFields,
    ): ResponseInterface
    {
        $items = $this->serializeSnapshots($result->items, $expandFields);
        $response = $this->createResponse($request, $items);

        return $this->paginationService->applyHeaders($response, $result);
    }

    /**
     * Serialize snapshot items respecting expand parameter.
     * Without expand: each snapshot is [] (empty array, matching Yii2 fields()=[]).
     * With expand: only requested extraFields are included.
     */
    private function serializeSnapshots(array $items, array $expandFields): array
    {
        return array_map(function ($item) use ($expandFields) {
            if ($item instanceof Snapshot) {
                if (empty($expandFields)) {
                    return $item->jsonSerialize(); // returns []
                }
                return $item->toExpandedArray($expandFields);
            }
            return $item;
        }, $items);
    }

    /**
     * Parse the 'expand' query parameter into an array of field names.
     * Matches Yii2 REST serializer behavior.
     */
    private function parseExpand(ServerRequestInterface $request): array
    {
        $expand = $request->getQueryParams()['expand'] ?? '';
        if ($expand === '') {
            return [];
        }
        return array_map('trim', explode(',', (string) $expand));
    }

    /**
     * Create a JSON success response with 200 status code.
     *
     * @param mixed $data The data to encode as JSON.
     * @param int   $statusCode HTTP status code (default 200).
     */
    private function createResponse(ServerRequestInterface $request, mixed $data, int $statusCode = 200): ResponseInterface
    {
        return $this->restResponseFactory->create($request, $data, $statusCode);
    }

    /**
     * Create a JSON error response matching Yii2 format: {status, message}.
     *
     * @param int    $statusCode The HTTP status code.
     * @param string $message    The error message.
     *
     * @see Requirement 10.3
     */
    private function createErrorResponse(ServerRequestInterface $request, int $statusCode, string $message): ResponseInterface
    {
        return $this->restResponseFactory->createError($request, $statusCode, $message);
    }
}
