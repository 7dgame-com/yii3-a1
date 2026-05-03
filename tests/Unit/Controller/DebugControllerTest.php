<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DebugController;
use App\Service\SnapshotDiagnosticsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Unit tests for DebugController.
 *
 * Validates /debug/snapshot:
 * - renders an HTML diagnostic page by default
 * - renders JSON when format=json is requested
 * - always returns 200 so diagnostics remain visible when a check fails
 */
final class DebugControllerTest extends TestCase
{
    private SnapshotDiagnosticsService $diagnosticsService;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private DebugController $controller;

    protected function setUp(): void
    {
        $this->diagnosticsService = new SnapshotDiagnosticsService($this->createDbMock());
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $this->controller = new DebugController(
            $this->diagnosticsService,
            $this->responseFactory,
            $this->streamFactory,
        );
    }

    public function testSnapshotRendersHtmlByDefault(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $headers = [];
        $this->setupResponseCapture($capturedBody, $capturedStatusCode, $headers);

        $this->controller->snapshot($this->createRequest([]));

        $this->assertSame(200, $capturedStatusCode);
        $this->assertSame('text/html; charset=UTF-8', $headers['Content-Type']);
        $this->assertStringContainsString('<title>Snapshot Diagnostics</title>', $capturedBody);
        $this->assertStringContainsString('space', $capturedBody);
    }

    public function testSnapshotRendersJsonWhenRequested(): void
    {
        $capturedBody = null;
        $capturedStatusCode = null;
        $headers = [];
        $this->setupResponseCapture($capturedBody, $capturedStatusCode, $headers);

        $this->controller->snapshot($this->createRequest(['format' => 'json']));

        $this->assertSame(200, $capturedStatusCode);
        $this->assertSame('application/json', $headers['Content-Type']);

        $decoded = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame([], $decoded['checks']['snapshot_schema']['missing_model_properties']);
    }

    private function createRequest(array $queryParams): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);

        return $request;
    }

    private function createDbMock(): ConnectionInterface
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')
            ->willReturnCallback(function (string $sql): CommandInterface {
                $command = $this->createMock(CommandInterface::class);

                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    $command->method('queryAll')->willReturn([
                        ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
                        ['Field' => 'verse_id', 'Type' => 'int', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'uuid', 'Type' => 'varchar(64)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'code', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'data', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'metas', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'resources', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'managers', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'created_by', 'Type' => 'int', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'created_at', 'Type' => 'timestamp', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'space', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                    ]);
                    return $command;
                }

                if (str_starts_with($sql, 'SELECT COUNT(*)')) {
                    $command->method('queryScalar')->willReturn(8);
                    return $command;
                }

                throw new \RuntimeException('Unexpected SQL: ' . $sql);
            });

        return $db;
    }

    private function setupResponseCapture(?string &$capturedBody, ?int &$capturedStatusCode, array &$headers): void
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
            ->willReturnCallback(function (int $statusCode) use ($response, &$capturedStatusCode) {
                $capturedStatusCode = $statusCode;
                return $response;
            });
    }
}
