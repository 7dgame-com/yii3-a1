<?php

declare(strict_types=1);

namespace App\Controller\V1;

use App\Service\PhototypeQueryService;
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
    ) {
    }

    public function info(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $type = (string) ($params['type'] ?? '');
        $phototype = $this->phototypeQueryService->findInfoByType($type);

        if ($phototype === null) {
            return $this->createErrorResponse(400, 'model not found.');
        }

        return $this->createJsonResponse($phototype);
    }

    /**
     * @param mixed $data
     */
    private function createJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    private function createErrorResponse(int $statusCode, string $message): ResponseInterface
    {
        return $this->createJsonResponse([
            'name' => 'Bad Request',
            'message' => $message,
            'code' => 0,
            'status' => $statusCode,
            'type' => 'yii\\web\\BadRequestHttpException',
        ], $statusCode);
    }
}
