<?php

declare(strict_types=1);

namespace App\Controller\V2;

use App\Service\SnapshotQueryService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * V2 Tag Controller.
 *
 * Provides a read-only endpoint for retrieving tags:
 * - GET /v2/tags — returns the list of tags (type='Classify' by default)
 *
 * @see Requirement 5.6
 */
final class TagController
{
    public function __construct(
        private readonly SnapshotQueryService $snapshotQueryService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * GET /v2/tags
     *
     * Returns the list of tags. Delegates to SnapshotQueryService::findTags().
     *
     * @see Requirement 5.6
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $tags = $this->snapshotQueryService->findTags();

        return $this->createJsonResponse($tags);
    }

    /**
     * Create a JSON success response with 200 status code.
     *
     * @param mixed $data       The data to encode as JSON.
     * @param int   $statusCode HTTP status code (default 200).
     */
    private function createJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
