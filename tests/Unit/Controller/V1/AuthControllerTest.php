<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V1;

use App\Controller\V1\AuthController;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\LoginCodeSettings;
use App\Service\LoginCodeStore;
use App\Service\RefreshTokenService;
use App\Service\UnityDevLoginFixture;
use App\Tests\Support\RedisTestClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private JwtService $jwtService;
    private LoginCodeStore $loginCodeStore;

    protected function setUp(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willReturn([
            'id' => 42,
            'username' => 'testuser',
            'nickname' => 'testuser',
        ]);
        $command->method('queryAll')->willReturn([[
            'id' => 42,
            'username' => 'testuser',
            'nickname' => 'testuser',
        ]]);

        $quoter = $this->createMock(QuoterInterface::class);
        $quoter->method('quoteTableName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteColumnName')->willReturnCallback(fn($n) => "`$n`");
        $quoter->method('quoteSql')->willReturnCallback(fn($s) => $s);
        $quoter->method('getRawTableName')->willReturnCallback(fn($n) => trim($n, '{}%`'));

        $col = $this->createMock(ColumnInterface::class);
        $col->method('phpTypecast')->willReturnCallback(fn($v) => $v);

        $tableSchema = $this->createMock(TableSchemaInterface::class);
        $tableSchema->method('getColumns')->willReturn([
            'id' => $col,
            'username' => $col,
            'nickname' => $col,
        ]);
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

        $this->jwtService = new JwtService($kf);

        $redis = RedisTestClientFactory::create();

        $this->refreshTokenService = new RefreshTokenService($redis);
        $this->loginCodeStore = new LoginCodeStore($redis, new LoginCodeSettings());
        $this->authService = new AuthService(
            $this->jwtService,
            $this->refreshTokenService,
            $this->loginCodeStore,
        );

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
        $this->assertSame(['success', 'message', 'nickname', 'token'], array_keys($d));
        $this->assertSame('refresh', $d['message']);
        $this->assertSame(['accessToken', 'expires', 'refreshToken'], array_keys($d['token']));
        $this->assertArrayNotHasKey('user', $d);
        $this->assertArrayNotHasKey('url', $d);
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

    public function testRefreshTokenReturnsRotatedTokenPair(): void
    {
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $body = null;
        $this->okResp($body);

        try {
            $this->controller->refreshToken($this->req(['refreshToken' => $oldRefreshToken]));
            $decoded = json_decode((string) $body, true);

            $this->assertTrue($decoded['success']);
            $this->assertSame(
                ['success', 'message', 'nickname', 'token', 'user'],
                array_keys($decoded),
            );
            $this->assertSame('keyToTokenWithUrl', $decoded['message']);
            $this->assertSame(['accessToken', 'expires', 'refreshToken'], array_keys($decoded['token']));
            $this->assertSame(
                ['id', 'username', 'nickname', 'fixture'],
                array_keys($decoded['user']),
            );
            $this->assertSame([
                'id' => 42,
                'username' => 'testuser',
                'nickname' => 'testuser',
                'fixture' => false,
            ], $decoded['user']);
            $this->assertArrayNotHasKey('url', $decoded);
            $this->assertNotSame($oldRefreshToken, $decoded['token']['refreshToken']);
            $this->assertNull($this->refreshTokenService->validate($oldRefreshToken));
            $this->assertSame(42, $this->refreshTokenService->validate($decoded['token']['refreshToken']));
        } finally {
            $this->refreshTokenService->delete($oldRefreshToken);
            if (isset($decoded['token']['refreshToken']) && is_string($decoded['token']['refreshToken'])) {
                $this->refreshTokenService->delete($decoded['token']['refreshToken']);
            }
        }
    }

    public function testRefreshTokenReturns401ForInvalidCredential(): void
    {
        $body = null;
        $statusCode = null;
        $this->errResp($body, $statusCode);

        $this->controller->refreshToken($this->req(['refreshToken' => str_repeat('a', 64)]));

        $this->assertSame(401, $statusCode);
        $this->assertSame('Refresh token is invalid.', json_decode((string) $body, true)['message']);
    }

    #[DataProvider('invalidRefreshTokenRequestBodies')]
    public function testRefreshTokenRequiresOnlyANonEmptyStringRefreshToken(array $requestBody): void
    {
        $body = null;
        $statusCode = null;
        $this->errResp($body, $statusCode);

        $this->controller->refreshToken($this->req($requestBody));

        $this->assertSame(400, $statusCode);
        $this->assertSame(400, json_decode((string) $body, true)['status']);
    }

    public function testLoginCodeReturnsUnityWhiteLabelResponse(): void
    {
        $rawKey = 'unity-controller-test-key-000000000000000000001';
        $fixture = new UnityDevLoginFixture(
            enabled: true,
            environment: 'dev',
            keySha256: hash('sha256', $rawKey),
        );
        $this->authService = new AuthService(
            $this->jwtService,
            $this->refreshTokenService,
            $this->loginCodeStore,
            $fixture,
        );
        $this->controller = new AuthController(
            $this->authService,
            $this->responseFactory,
            $this->streamFactory,
        );
        $body = null;
        $this->okResp($body);

        $this->controller->loginCode($this->req(['loginCode' => 'web_' . $rawKey]));
        $decoded = json_decode((string) $body, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame(
            ['success', 'message', 'nickname', 'token', 'user', 'url'],
            array_keys($decoded),
        );
        $this->assertSame('keyToTokenWithUrl', $decoded['message']);
        $this->assertSame(['accessToken', 'expires', 'refreshToken'], array_keys($decoded['token']));
        $this->assertSame(
            ['id', 'username', 'nickname', 'fixture'],
            array_keys($decoded['user']),
        );
        $this->assertSame([
            'id' => UnityDevLoginFixture::USER_ID,
            'username' => 'unity_dev_fixture',
            'nickname' => 'Unity Dev Fixture',
            'fixture' => true,
        ], $decoded['user']);
        $this->assertSame('https://d.dev.xrugc.com', $decoded['url']);
    }

    public function testLoginCodeReturns401ForInvalidCredential(): void
    {
        $body = null;
        $statusCode = null;
        $this->errResp($body, $statusCode);

        $this->controller->loginCode($this->req(['loginCode' => 'invalid-login-code']));

        $this->assertSame(401, $statusCode);
        $this->assertSame('Login code is invalid or expired.', json_decode((string) $body, true)['message']);
    }

    #[DataProvider('invalidLoginCodeRequestBodies')]
    public function testLoginCodeRequiresOnlyANonEmptyStringLoginCode(array $requestBody): void
    {
        $body = null;
        $statusCode = null;
        $this->errResp($body, $statusCode);

        $this->controller->loginCode($this->req($requestBody));

        $this->assertSame(400, $statusCode);
        $this->assertSame(400, json_decode((string) $body, true)['status']);
    }

    public function testLoginCodeContextRequiresKey(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);

        $this->controller->loginCodeContext($this->req([]));

        $this->assertSame(400, $sc);
        $this->assertSame('key is required', json_decode((string) $b, true)['message']);
    }

    public function testKeyToTokenWithUrlRequiresKey(): void
    {
        $b = null;
        $sc = null;
        $this->errResp($b, $sc);

        $this->controller->keyToTokenWithUrl($this->req([]));

        $this->assertSame(400, $sc);
        $this->assertSame('key is required', json_decode((string) $b, true)['message']);
    }

    public static function invalidRefreshTokenRequestBodies(): array
    {
        return [
            'missing field' => [[]],
            'wrong credential field' => [['loginCode' => str_repeat('a', 64)]],
            'empty string' => [['refreshToken' => '']],
            'whitespace only' => [['refreshToken' => '   ']],
            'integer' => [['refreshToken' => 123]],
            'non-empty array' => [['refreshToken' => ['not-a-string']]],
        ];
    }

    public static function invalidLoginCodeRequestBodies(): array
    {
        return [
            'missing field' => [[]],
            'wrong credential field' => [['refreshToken' => str_repeat('a', 64)]],
            'empty string' => [['loginCode' => '']],
            'whitespace only' => [['loginCode' => '   ']],
            'integer' => [['loginCode' => 123]],
            'non-empty array' => [['loginCode' => ['not-a-string']]],
        ];
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
