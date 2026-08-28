<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\V2;

use App\Controller\V2\AuthController;
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
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\Column\ColumnInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;

final class AuthControllerTest extends TestCase
{
    private AuthService $authService;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private AuthController $controller;
    private RefreshTokenService $refreshTokenService;
    private JwtService $jwtService;
    private LoginCodeStore $loginCodeStore;
    private string $jwtKeyFile;

    protected function setUp(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('queryOne')->willReturn([
            'id' => 42,
            'username' => 'testuser',
            'nickname' => 'Test User',
        ]);
        $command->method('queryAll')->willReturn([[
            'id' => 42,
            'username' => 'testuser',
            'nickname' => 'Test User',
        ]]);

        $quoter = $this->createMock(QuoterInterface::class);
        $quoter->method('quoteTableName')->willReturnCallback(fn ($name) => "`$name`");
        $quoter->method('quoteColumnName')->willReturnCallback(fn ($name) => "`$name`");
        $quoter->method('quoteSql')->willReturnCallback(fn ($sql) => $sql);
        $quoter->method('getRawTableName')->willReturnCallback(fn ($name) => trim($name, '{}%`'));

        $column = $this->createMock(ColumnInterface::class);
        $column->method('phpTypecast')->willReturnCallback(fn ($value) => $value);

        $tableSchema = $this->createMock(TableSchemaInterface::class);
        $tableSchema->method('getColumns')->willReturn([
            'id' => $column,
            'username' => $column,
            'nickname' => $column,
        ]);
        $tableSchema->method('getColumn')->willReturn($column);

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

        $jwtKeyFile = tempnam(sys_get_temp_dir(), 'jwt_v2_auth_ctrl_');
        self::assertNotFalse($jwtKeyFile);
        $this->jwtKeyFile = $jwtKeyFile;
        file_put_contents($this->jwtKeyFile, 'test-secret-key-for-v2-auth-controller');

        $this->jwtService = new JwtService($this->jwtKeyFile);
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
        $this->controller = new AuthController(
            $this->authService,
            $this->responseFactory,
            $this->streamFactory,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->jwtKeyFile) && is_file($this->jwtKeyFile)) {
            unlink($this->jwtKeyFile);
        }
    }

    public function testRefreshTokenReturnsStrictSuccessContractWithoutUrl(): void
    {
        $oldRefreshToken = $this->refreshTokenService->create(42);
        $body = null;
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        try {
            $this->controller->refreshToken($this->request(['refreshToken' => $oldRefreshToken]));
            $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(200, $statusCode);
            $this->assertSame('application/json', $headers['Content-Type']);
            $this->assertSame(
                ['success', 'message', 'nickname', 'token', 'user'],
                array_keys($decoded),
            );
            $this->assertTrue($decoded['success']);
            $this->assertSame('keyToTokenWithUrl', $decoded['message']);
            $this->assertSame('Test User', $decoded['nickname']);
            $this->assertSame(
                ['accessToken', 'expires', 'refreshToken'],
                array_keys($decoded['token']),
            );
            $this->assertSame([
                'id' => 42,
                'username' => 'testuser',
                'nickname' => 'Test User',
                'fixture' => false,
            ], $decoded['user']);
            $this->assertArrayNotHasKey('url', $decoded);
            $this->assertNotSame($oldRefreshToken, $decoded['token']['refreshToken']);
            $this->assertNull($this->refreshTokenService->validate($oldRefreshToken));
        } finally {
            $this->refreshTokenService->delete($oldRefreshToken);
            if (isset($decoded['token']['refreshToken']) && is_string($decoded['token']['refreshToken'])) {
                $this->refreshTokenService->delete($decoded['token']['refreshToken']);
            }
        }
    }

    public function testLoginCodeReturnsStrictSuccessContractWithUrl(): void
    {
        $rawKey = 'v2-unity-controller-test-key-00000000000000001';
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
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        $this->controller->loginCode($this->request(['loginCode' => 'web_' . $rawKey]));
        $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $statusCode);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame(
            ['success', 'message', 'nickname', 'token', 'user', 'url'],
            array_keys($decoded),
        );
        $this->assertTrue($decoded['success']);
        $this->assertSame('keyToTokenWithUrl', $decoded['message']);
        $this->assertSame('Unity Dev Fixture', $decoded['nickname']);
        $this->assertSame(
            ['accessToken', 'expires', 'refreshToken'],
            array_keys($decoded['token']),
        );
        $this->assertSame([
            'id' => UnityDevLoginFixture::USER_ID,
            'username' => 'unity_dev_fixture',
            'nickname' => 'Unity Dev Fixture',
            'fixture' => true,
        ], $decoded['user']);
        $this->assertSame('https://d.dev.xrugc.com', $decoded['url']);
    }

    public function testRefreshTokenReturns401ForInvalidCredential(): void
    {
        $body = null;
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        $this->controller->refreshToken($this->request([
            'refreshToken' => str_repeat('a', 64),
        ]));

        $this->assertSame(401, $statusCode);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame([
            'name' => 'Unauthorized',
            'message' => 'Refresh token is invalid.',
            'code' => 0,
            'status' => 401,
            'type' => 'yii\\web\\UnauthorizedHttpException',
        ], json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testLoginCodeReturns401ForInvalidCredential(): void
    {
        $body = null;
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        $this->controller->loginCode($this->request([
            'loginCode' => 'invalid-v2-login-code',
        ]));

        $this->assertSame(401, $statusCode);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame([
            'name' => 'Unauthorized',
            'message' => 'Login code is invalid or expired.',
            'code' => 0,
            'status' => 401,
            'type' => 'yii\\web\\UnauthorizedHttpException',
        ], json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidRefreshTokenRequestBodies')]
    public function testRefreshTokenValidatesItsOwnField(mixed $requestBody): void
    {
        $body = null;
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        $this->controller->refreshToken($this->request($requestBody));

        $this->assertSame(400, $statusCode);
        $this->assertSame([
            'name' => 'Bad Request',
            'message' => 'refreshToken is required',
            'code' => 0,
            'status' => 400,
            'type' => 'yii\\web\\BadRequestHttpException',
        ], json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidLoginCodeRequestBodies')]
    public function testLoginCodeValidatesItsOwnField(mixed $requestBody): void
    {
        $body = null;
        $statusCode = null;
        $headers = [];
        $this->captureResponse($body, $statusCode, $headers);

        $this->controller->loginCode($this->request($requestBody));

        $this->assertSame(400, $statusCode);
        $this->assertSame([
            'name' => 'Bad Request',
            'message' => 'loginCode is required',
            'code' => 0,
            'status' => 400,
            'type' => 'yii\\web\\BadRequestHttpException',
        ], json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function invalidRefreshTokenRequestBodies(): array
    {
        return [
            'null body' => [null],
            'object body' => [(object) []],
            'missing field' => [[]],
            'wrong credential field' => [['loginCode' => str_repeat('a', 64)]],
            'empty string' => [['refreshToken' => '']],
            'whitespace only' => [['refreshToken' => '   ']],
            'integer' => [['refreshToken' => 123]],
            'array' => [['refreshToken' => ['not-a-string']]],
        ];
    }

    public static function invalidLoginCodeRequestBodies(): array
    {
        return [
            'null body' => [null],
            'object body' => [(object) []],
            'missing field' => [[]],
            'wrong credential field' => [['refreshToken' => str_repeat('a', 64)]],
            'empty string' => [['loginCode' => '']],
            'whitespace only' => [['loginCode' => '   ']],
            'integer' => [['loginCode' => 123]],
            'array' => [['loginCode' => ['not-a-string']]],
        ];
    }

    private function request(mixed $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    /**
     * @param array<string, string> $headers
     */
    private function captureResponse(?string &$body, ?int &$statusCode, array &$headers): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $json) use ($stream, &$body) {
                $body = $json;

                return $stream;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;

                return $response;
            });
        $response->method('withBody')->willReturnSelf();

        $this->responseFactory
            ->method('createResponse')
            ->willReturnCallback(function (int $code) use ($response, &$statusCode) {
                $statusCode = $code;

                return $response;
            });
    }
}
