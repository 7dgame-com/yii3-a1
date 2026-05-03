<?php

declare(strict_types=1);

namespace App\Controller\V2;

use App\Service\SnapshotQueryService;
use App\Service\Yii2RestResponseFactory;
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
        private readonly Yii2RestResponseFactory $restResponseFactory,
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

        return $this->restResponseFactory->create($request, $tags);
    }
}
