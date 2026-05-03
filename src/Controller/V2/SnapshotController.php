<?php

declare(strict_types=1);

namespace App\Controller\V2;

use App\Model\Snapshot;
use App\Service\PaginatedResult;
use App\Service\PaginationService;
use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * V2 Snapshot Controller.
 *
 * Provides a unified snapshot query interface using a `scope` parameter:
 * - GET /v2/snapshots?scope=public  — public snapshots (default)
 * - GET /v2/snapshots?scope=checkin — checkin snapshots
 * - GET /v2/snapshots?scope=group   — group snapshots (requires authentication)
 * - GET /v2/snapshots?scope=private — private snapshots (requires authentication)
 * - GET /v2/snapshots/{id}          — single snapshot by ID
 *
 * scope=group and scope=private require JWT authentication.
 * The authenticated user is injected as request attribute 'user' by JwtAuthMiddleware.
 *
 * @see Requirements 5.1, 5.2, 5.3, 5.4, 5.5
 */
final class SnapshotController
{
    public function __construct(
        private readonly SnapshotQueryService $snapshotQueryService,
        private readonly PaginationService $paginationService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CurrentRoute $currentRoute,
        private readonly Yii2RestResponseFactory $restResponseFactory,
    ) {
    }

    /**
     * GET /v2/snapshots
     *
     * Returns paginated list of snapshots filtered by scope.
     * Default scope is 'public' if not specified.
     *
     * Supported scopes:
     * - public: Public snapshots (no auth required)
     * - checkin: Checkin snapshots (no auth required)
     * - group: Group snapshots (auth required)
     * - private: Private snapshots (auth required)
     *
     * Supports query parameters: scope, pageSize, page, tags.
     *
     * @see Requirements 5.1, 5.2, 5.3, 5.4
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $scope = (string) ($params['scope'] ?? 'public');
        $expandFields = $this->parseExpand($request);

        return match ($scope) {
            'public' => $this->handlePublic($request, $params, $expandFields),
            'checkin' => $this->handleCheckin($request, $params, $expandFields),
            'group' => $this->handleGroup($request, $params, $expandFields),
            'private' => $this->handlePrivate($request, $params, $expandFields),
            default => $this->createErrorResponse($request, 400, "Invalid scope: {$scope}. Allowed values: public, checkin, group, private."),
        };
    }

    /**
     * GET /v2/snapshots/{id}
     *
     * Returns a single snapshot by its ID.
     * The {id} route parameter is extracted from request attributes.
     *
     * @see Requirement 5.5
     */
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->currentRoute->getArgument('id');
        $expandFields = $this->parseExpand($request);

        if ($id === null) {
            return $this->createErrorResponse($request, 400, 'Missing required parameter: id.');
        }

        $snapshot = $this->snapshotQueryService->findSnapshotModel((int) $id);

        if ($snapshot === null) {
            return $this->createErrorResponse($request, 404, "Object not found: {$id}");
        }

        if (empty($expandFields)) {
            return $this->createResponse($request, $snapshot->jsonSerialize());
        }
        return $this->createResponse($request, $snapshot->toExpandedArray($expandFields));
    }

    /**
     * Handle scope=public query.
     *
     * @see Requirement 5.1
     */
    private function handlePublic(ServerRequestInterface $request, array $params, array $expandFields): ResponseInterface
    {
        $result = $this->snapshotQueryService->findPublic($params);
        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * Handle scope=checkin query.
     */
    private function handleCheckin(ServerRequestInterface $request, array $params, array $expandFields): ResponseInterface
    {
        $result = $this->snapshotQueryService->findCheckin($params);
        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * Handle scope=group query (requires authentication).
     */
    private function handleGroup(ServerRequestInterface $request, array $params, array $expandFields): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || !isset($user['user_id'])) {
            return $this->createErrorResponse($request, 403, 'Login required.');
        }

        $result = $this->snapshotQueryService->findGroup((int) $user['user_id'], $params);
        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * Handle scope=private query (requires authentication).
     */
    private function handlePrivate(ServerRequestInterface $request, array $params, array $expandFields): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null || !isset($user['user_id'])) {
            return $this->createErrorResponse($request, 403, 'Login required.');
        }

        $result = $this->snapshotQueryService->findPrivate((int) $user['user_id'], $params);
        return $this->createPaginatedSnapshotResponse($request, $result, $expandFields);
    }

    /**
     * Create a paginated JSON response for snapshot items, handling expand.
     */
    private function createPaginatedSnapshotResponse(
        ServerRequestInterface $request,
        PaginatedResult $result,
        array $expandFields,
    ): ResponseInterface
    {
        $items = array_map(function ($item) use ($expandFields) {
            if ($item instanceof Snapshot) {
                if (empty($expandFields)) {
                    return $item->jsonSerialize();
                }
                return $item->toExpandedArray($expandFields);
            }
            return $item;
        }, $result->items);

        $response = $this->createResponse($request, $items);
        return $this->paginationService->applyHeaders($response, $result);
    }

    /**
     * Parse the 'expand' query parameter into an array of field names.
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
     * @param mixed $data       The data to encode as JSON.
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
