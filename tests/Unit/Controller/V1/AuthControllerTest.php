<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V1;

use App\Controller\V1\AuthController;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\RefreshTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;
use Yiisoft\Db\Schema\Column\ColumnInterface;

final class AuthControllerTest extends TestCase
{
    private AuthService $authService;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private AuthController $controller;
    private RefreshTokenService $refreshTokenService;

    protected function setUp(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willReturn(['id' => 42, 'nickname' => 'testuser']);
        $command->method('queryAll')->willReturn([['id' => 42, 'nickname' => 'testuser']]);

        $quoter = $this->createMock(QuoterInterface::class);
        $quoter->method('quoteTableName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteColumnName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteSql')->willReturnCallback(fn($s) => $s);
        $quoter->method('getRawTableName')->willReturnCallback(fn($n) => trim($n, '{}%`'));

        $col = $this->createMock(ColumnInterface::class);
        $col->method('phpTypecast')->willReturnCallback(fn($v) => $v);

        $tableSchema = $this->createMock(TableSchemaInterface::class);
        $tableSchema->method('getColumns')->willReturn(['id' => $col, 'nickname' => $col]);
        $tableSchema->method('getColumn')->willReturn($col);

        $schema = $this->createMock(SchemaInterface::class);
        $schema->method('getTableSchema')->willReturn($tableSchema);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $queryBuilder->method('build')->willReturn(['SELECT * FROM `user`', []]);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('createCommand')->willReturn($command);
        $connection->method('getQueryBuilder')->willReturn($queryBuilder);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getQuoter')->willReturn($quoter);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('getTableSchema')->willReturn($tableSchema);

        ConnectionProvider::set($connection);

        $kf = tempnam(sys_get_temp_dir(), 'jwt_ctrl_test_');
        file_put_contents($kf, 'test-secret-key-for-controller-testing-minimum-len');

        $jwtService = new JwtService($kf);

        $rh = getenv('REDIS_HOST') ?: '127.0.0.1';
        $rp = (int) (getenv('REDIS_PORT') ?: 6379);
        $rd = (int) (getenv('REDIS_DB') ?: 1);
        $redis = new \Predis\Client(['scheme' => 'tcp', 'host' => $rh, 'port' => $rp, 'database' => $rd]);

        $this->refreshTokenService = new RefreshTokenService($redis);
        $this->authService = new AuthService($jwtService, $this->refreshTokenService);

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->controller = new AuthController($this->authService, $this->responseFactory, $this->streamFactory);
    }

    public function testRefreshReturnsTokenPairOnSuccess(): void
    {
        $t = $this->refreshTokenService->create(42);
        $b = null;
        $this->okResp($b);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $d = json_decode($b, true);
        $this->assertTrue($d['success']);
        $this->assertSame('refresh', $d['message']);
        $this->assertNotEmpty($d['token']['accessToken']);
        $this->assertNotEmpty($d['token']['refreshToken']);
        $this->refreshTokenService->delete($d['token']['refreshToken']);
    }

    public function testRefreshReturns401OnInvalidToken(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);
        $this->controller->refresh($this->req(['refreshToken' => 'invalid_token']));
        $this->assertSame(401, $sc);
        $d = json_decode($b, true);
        $this->assertSame(401, $d['status']);
        $this->assertSame('Refresh token is invalid.', $d['message']);
    }

    public function testRefreshHandlesMissingToken(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);
        $this->controller->refresh($this->req([]));
        $this->assertSame(400, $sc);
    }

    public function testRefreshSuccessResponseFormat(): void
    {
        $t = $this->refreshTokenService->create(99);
        $b = null;
        $this->okResp($b);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $d = json_decode($b, true);
        $this->assertArrayHasKey('success', $d);
        $this->assertArrayHasKey('message', $d);
        $this->assertArrayHasKey('nickname', $d);
        $this->assertArrayHasKey('token', $d);
        $this->assertArrayHasKey('accessToken', $d['token']);
        $this->assertArrayHasKey('refreshToken', $d['token']);
        $this->assertArrayHasKey('expires', $d['token']);
        $this->refreshTokenService->delete($d['token']['refreshToken']);
    }

    public function testRefreshSuccessResponseHasJsonContentType(): void
    {
        $t = $this->refreshTokenService->create(55);
        $h = [];
        $b = null;
        $this->hdrBody($h, $b);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $this->assertSame('application/json', $h['Content-Type']);
        if ($b) {
            $d = json_decode($b, true);
            if (isset($d['token']['refreshToken'])) {
                $this->refreshTokenService->delete($d['token']['refreshToken']);
            }
        }
    }

    public function testRefreshErrorResponseHasJsonContentType(): void
    {
        $h = [];
        $this->hdr($h);
        $this->controller->refresh($this->req(['refreshToken' => 'bad']));
        $this->assertSame('application/json', $h['Content-Type']);
    }

    public function testRefreshErrorResponseContainsStatusAndMessageFields(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);
        $this->controller->refresh($this->req(['refreshToken' => 'x']));
        $d = json_decode($b, true);
        $this->assertArrayHasKey('status', $d);
        $this->assertArrayHasKey('message', $d);
        $this->assertCount(5, $d);
    }

    public function testRefreshErrorResponseUses401StatusForInvalidToken(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);
        $this->controller->refresh($this->req(['refreshToken' => 'x']));
        $this->assertSame(401, $sc);
        $d = json_decode($b, true);
        $this->assertSame(401, $d['status']);
    }

    public function testRefreshDeletesOldToken(): void
    {
        $t = $this->refreshTokenService->create(77);
        $b = null;
        $this->okResp($b);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $this->assertNull($this->refreshTokenService->validate($t));
        $d = json_decode($b, true);
        $this->refreshTokenService->delete($d['token']['refreshToken']);
    }

    public function testRefreshWithAlreadyUsedTokenReturns401(): void
    {
        $t = $this->refreshTokenService->create(88);
        $b = null;
        $this->okResp($b);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $d = json_decode($b, true);
        $nt = $d['token']['refreshToken'];

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->controller = new AuthController($this->authService, $this->responseFactory, $this->streamFactory);

        $b2 = null;
        $sc2 = null;
        $this->errResp($b2, $sc2);
        $this->controller->refresh($this->req(['refreshToken' => $t]));
        $this->assertSame(401, $sc2);
        $this->refreshTokenService->delete($nt);
    }

    private function req(array $body): ServerRequestInterface
    {
        $r = $this->createMock(ServerRequestInterface::class);
        $r->method('getParsedBody')->willReturn($body);
        return $r;
    }

    private function okResp(?string &$b): void
    {
        $s = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(function (string $x) use ($s, &$b) {
            $b = $x;
            return $s;
        });
        $r = $this->createMock(ResponseInterface::class);
        $r->method('withHeader')->willReturnSelf();
        $r->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->with(200)->willReturn($r);
    }

    private function errResp(?string &$b, ?int &$sc): void
    {
        $s = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(function (string $x) use ($s, &$b) {
            $b = $x;
            return $s;
        });
        $r = $this->createMock(ResponseInterface::class);
        $r->method('withHeader')->willReturnSelf();
        $r->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->willReturnCallback(function (int $c) use ($r, &$sc) {
            $sc = $c;
            return $r;
        });
    }

    private function hdr(array &$h): void
    {
        $s = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($s);
        $r = $this->createMock(ResponseInterface::class);
        $r->method('withHeader')->willReturnCallback(function (string $n, string $v) use ($r, &$h) {
            $h[$n] = $v;
            return $r;
        });
        $r->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->willReturn($r);
    }

    private function hdrBody(array &$h, ?string &$b): void
    {
        $s = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(function (string $x) use ($s, &$b) {
            $b = $x;
            return $s;
        });
        $r = $this->createMock(ResponseInterface::class);
        $r->method('withHeader')->willReturnCallback(function (string $n, string $v) use ($r, &$h) {
            $h[$n] = $v;
            return $r;
        });
        $r->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->willReturn($r);
    }
}
