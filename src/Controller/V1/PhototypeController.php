<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Service\PhototypeQueryService;
use App\Service\Yii2RestResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * V1 Phototype Controller.
 *
 * Matches A1 Yii2 endpoint:
 * - GET /v1/phototype/info?type={type}
 */
final class PhototypeController
{
    public function __construct(
        private readonly PhototypeQueryService $phototypeQueryService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Yii2RestResponseFactory $restResponseFactory,
    ) {
    }

    public function info(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        if (!isset($params['type']) || $params['type'] === '') {
            return $this->createErrorResponse($request, 400, 'Missing required parameters: type');
        }

        $type = (string) $params['type'];
        $phototype = $this->phototypeQueryService->findInfoByType($type);

        if ($phototype === null) {
            return $this->createErrorResponse($request, 400, 'model not found.');
        }

        return $this->restResponseFactory->create($request, $phototype);
    }

    /**
     * @param mixed $data
     */
    private function createErrorResponse(ServerRequestInterface $request, int $statusCode, string $message): ResponseInterface
    {
        return $this->restResponseFactory->createError($request, $statusCode, $message);
    }
}
