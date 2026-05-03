<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V1;

use App\Controller\V1\PhototypeController;
use App\Service\PhototypeQueryService;
use App\Service\Yii2RestResponseFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Unit tests for the A1-compatible V1 phototype route.
 */
final class PhototypeControllerTest extends TestCase
{
    private PhototypeQueryService&MockObject $phototypeQueryService;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private PhototypeController $controller;

    protected function setUp(): void
    {
        $this->phototypeQueryService = $this->createMock(PhototypeQueryService::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $this->controller = new PhototypeController(
            $this->phototypeQueryService,
            $this->responseFactory,
            $this->streamFactory,
            new Yii2RestResponseFactory($this->responseFactory, $this->streamFactory),
        );
    }

    public function testInfoReturnsA1PhototypeShape(): void
    {
        $this->phototypeQueryService->expects($this->once())
            ->method('findInfoByType')
            ->with('polygen')
            ->willReturn([
                'id' => 1,
                'data' => '{"schema":true}',
                'title' => 'Polygen',
                'resource' => [
                    'id' => 2,
                    'info' => '{}',
                    'uuid' => 'resource-uuid',
                    'type' => 'polygen',
                    'file' => ['md5' => 'abc', 'type' => 'model/gltf-binary', 'url' => 'https://example.test/model.glb', 'key' => 'model.glb'],
                ],
            ]);

        $capturedBody = null;
        $this->setupResponse(200, $capturedBody);

        $this->controller->info($this->createRequest(['type' => 'polygen']));

        $decoded = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'data', 'title', 'resource'], array_keys($decoded));
        $this->assertSame('Polygen', $decoded['title']);
        $this->assertSame('polygen', $decoded['resource']['type']);
    }

    public function testInfoReturnsBadRequestWhenPhototypeIsMissing(): void
    {
        $this->phototypeQueryService->expects($this->once())
            ->method('findInfoByType')
            ->with('missing')
            ->willReturn(null);

        $capturedBody = null;
        $this->setupResponse(400, $capturedBody);

        $this->controller->info($this->createRequest(['type' => 'missing']));

        $decoded = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $decoded['name']);
        $this->assertSame('model not found.', $decoded['message']);
        $this->assertSame(400, $decoded['status']);
        $this->assertArrayNotHasKey('type', $decoded);
    }

    public function testInfoReturnsBadRequestWhenTypeIsMissing(): void
    {
        $this->phototypeQueryService->expects($this->never())
            ->method('findInfoByType');

        $capturedBody = null;
        $this->setupResponse(400, $capturedBody);

        $this->controller->info($this->createRequest([]));

        $decoded = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $decoded['name']);
        $this->assertSame('Missing required parameters: type', $decoded['message']);
        $this->assertSame(400, $decoded['status']);
    }

    public function testInfoHonorsApplicationXmlAccept(): void
    {
        $this->phototypeQueryService->expects($this->once())
            ->method('findInfoByType')
            ->with('polygen')
            ->willReturn([
                'id' => 1,
                'data' => '{"schema":true}',
                'title' => 'Polygen',
                'resource' => null,
            ]);

        $capturedBody = null;
        $headers = [];
        $this->setupResponseWithHeaders(200, $capturedBody, $headers);

        $this->controller->info($this->createRequest(['type' => 'polygen'], 'application/xml'));

        $this->assertSame('application/xml; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><id>1</id><data>{\"schema\":true}</data><title>Polygen</title><resource/></response>\n",
            $capturedBody,
        );
    }

    private function createRequest(array $queryParams, string $accept = '*/*'): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn($accept);

        return $request;
    }

    private function setupResponse(int $statusCode, ?string &$capturedBody): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->with($statusCode)
            ->willReturn($response);
    }

    private function setupResponseWithHeaders(int $statusCode, ?string &$capturedBody, array &$headers): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $body) use ($stream, &$capturedBody) {
                $capturedBody = $body;
                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->with($statusCode)
            ->willReturn($response);
    }
}
