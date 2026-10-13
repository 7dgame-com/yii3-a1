<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AuthService;
use App\Service\IdentityAuthorityClient;
use App\Service\JwtService;
use App\Service\LoginCodeStore;
use App\Service\LoginCodeSettings;
use App\Service\RefreshTokenService;
use App\Middleware\JwtAuthMiddleware;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Predis\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;

final class IdentityAuthorityClientTest extends TestCase
{
    private array $calls = [];
    private int $status = 200;
    private array $account = ['id' => 42, 'username' => 'private-user', 'nickname' => 'private-nickname', 'status' => 10, 'roles' => ['user']];
    private array $token = ['accessToken' => 'identity-access', 'refreshToken' => 'identity-refresh', 'expires' => '2026-10-12T12:00:00.000Z'];
    private ?string $domain = 'd.dev.xrugc.com';
    private ?array $legacyResponse = null;

    private function authority(): IdentityAuthorityClient
    {
        return new IdentityAuthorityClient('identity', 'http://identity.invalid:8086', 'auth-scoped-key', 'iam-scoped-key', 1000, true,
            function (string $path, array $payload, array $headers, int $timeout, string $method): array {
                $this->calls[] = compact('path', 'payload', 'headers', 'timeout', 'method');
                if ($this->status !== 200) return ['status' => $this->status, 'body' => ['message' => 'must not disclose remote exception']];
                $body = match ($path) {
                    '/v1/auth/login', '/internal/auth/issue-user-token' => ['success' => true, 'token' => $this->token],
                    '/v1/auth/logout' => ['success' => true],
                    '/internal/auth/verify-access-token', '/internal/identity/subjects/42' => ['success' => true, 'data' => $this->account],
                    default => ($payload['operation'] ?? null) === 'context'
                        ? ['frontendDomain' => $this->domain]
                        : ['authTokens' => $this->token, 'subject' => $this->account, 'frontendDomain' => $this->domain]
                            + ($this->legacyResponse === null ? [] : ['legacyResponse' => $this->legacyResponse]),
                };
                return ['status' => 200, 'body' => $body];
            });
    }

    private function auth(IdentityAuthorityClient $authority): AuthService
    {
        // Deliberately unusable legacy stores. Any attempted fallback fails;
        // the query assertion also prevents a successful local account read.
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('createCommand');
        ConnectionProvider::set($db);
        $redis = new Client('tcp://127.0.0.1:1');
        return new AuthService(new JwtService('/does-not-exist', null, null, $authority),
            new RefreshTokenService($redis), new LoginCodeStore($redis, new LoginCodeSettings()), null, null, $authority);
    }

    public function testDefaultIsLegacyAndInvalidOrUnreadySwitchIsRejected(): void
    {
        self::assertFalse((new IdentityAuthorityClient())->enabled());
        foreach ([['unknown', true], ['identity', false]] as [$source, $ready]) {
            try {
                new IdentityAuthorityClient($source, 'http://identity.invalid', 'auth', 'iam', 1000, $ready);
                self::fail('Invalid configuration must fail closed.');
            } catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        }
    }

    public function testIndependentModeRejectsTheEnabledLegacySyntheticIssuer(): void
    {
        self::assertFalse((new IdentityAuthorityClient(legacyFixtureEnabled: true))->enabled());
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        new IdentityAuthorityClient('identity', 'http://identity.invalid', 'auth', 'iam', 1000, true, legacyFixtureEnabled: true);
    }

    public function testLoginUsesPrivateAuthorityAndPreservesV1AndV2ClientShapes(): void
    {
        $authority = $this->authority(); $auth = $this->auth($authority);
        $this->account['password_hash'] = 'never-return';
        $v1 = $auth->login('submitted-user', 'submitted-password');
        $v2 = $auth->loginV2('submitted-user', 'submitted-password');
        self::assertSame('login', $v1['message']); self::assertSame('keyToTokenWithUrl', $v2['message']);
        self::assertSame(['id' => 42, 'username' => 'private-user', 'nickname' => 'private-nickname', 'fixture' => false], $v1['user']);
        self::assertSame(['accessToken' => 'identity-access', 'expires' => '2026-10-12 20:00:00', 'refreshToken' => 'identity-refresh'], $v1['token']);
        self::assertArrayNotHasKey('url', $v2);
        self::assertStringNotContainsString('never-return', json_encode($v1));
        self::assertSame(['username' => 'submitted-user', 'password' => 'submitted-password'], $this->calls[0]['payload']);
        self::assertSame('iam-scoped-key', $this->calls[1]['headers']['X-Identity-Internal-Token']);
    }

    public function testV1RefreshKeepsBareAndWrappedCredentialsAtOneAuthorityAndV2IsStrict(): void
    {
        $auth = $this->auth($this->authority());
        foreach (['bare-old-code', 'web_old-code', 'https://dev.xrugc.com/?web_old-code', 'old-30-day-refresh', 'dsn_old-refresh'] as $value) {
            $result = $auth->refresh($value);
            self::assertSame('refresh', $result['message']); self::assertArrayNotHasKey('user', $result);
            self::assertSame(['refreshToken' => $value, 'allowLoginCode' => true, 'sourceEndpoint' => '/v1/auth/refresh'], end($this->calls)['payload']);
        }
        $strict = $auth->refreshTokenOnly('web_old-code');
        self::assertSame('keyToTokenWithUrl', $strict['message']); self::assertArrayHasKey('user', $strict);
        self::assertSame(false, end($this->calls)['payload']['allowLoginCode']);
        self::assertSame('/v2/auth/refresh-token', end($this->calls)['payload']['sourceEndpoint']);
    }

    public function testImportedPairPreservesTheSharedOriginalBodyAcrossV1AndV2(): void
    {
        $body = ['token' => ['accessToken' => 'identity-access', 'expires' => '2026-10-12 07:32:11', 'refreshToken' => 'identity-refresh'],
            'nickname' => '原昵称', 'user' => ['id' => 42, 'username' => str_repeat('名', 255), 'nickname' => '旧资料', 'fixture' => false]];
        $this->legacyResponse = ['version' => 'legacy-refresh-response-v1', 'consumer' => 'yii3', 'endpoint' => 'y1-refresh-pair', 'body' => $body];
        $auth = $this->auth($this->authority());
        $v1 = $auth->refresh('old', ['sourceEndpoint' => '/v2/auth/refresh-token', 'consumer' => 'a1']);
        $v2 = $auth->refreshTokenOnly('old');
        self::assertSame($body['token'], $v1['token']); self::assertSame($body['nickname'], $v1['nickname']);
        self::assertArrayNotHasKey('user', $v1); self::assertSame('refresh', $v1['message']);
        self::assertSame($body['token'], $v2['token']); self::assertSame($body['user'], $v2['user']);
        self::assertSame('keyToTokenWithUrl', $v2['message']);
        self::assertSame('/v1/auth/refresh', $this->calls[0]['payload']['sourceEndpoint']);
        self::assertArrayNotHasKey('consumer', $this->calls[0]['payload']);
        self::assertSame('/v2/auth/refresh-token', $this->calls[1]['payload']['sourceEndpoint']);
    }

    public function testImportedPairRejectsWrongScopeOwnerOrTokenWithoutAnyLegacyFallback(): void
    {
        $valid = ['version' => 'legacy-refresh-response-v1', 'consumer' => 'yii3', 'endpoint' => 'y1-refresh-pair',
            'body' => ['token' => ['accessToken' => 'identity-access', 'expires' => '2026-10-12 20:00:00', 'refreshToken' => 'identity-refresh'],
                'nickname' => 'old', 'user' => ['id' => 42, 'username' => 'old', 'nickname' => 'old', 'fixture' => false]]];
        $variants = [];
        foreach (['consumer' => 'a1', 'endpoint' => '/v1/auth/refresh', 'version' => 'unknown', 'extra' => true] as $key => $value) {
            $candidate = $valid; $candidate[$key] = $value; $variants[] = $candidate;
        }
        $candidate = $valid; $candidate['body']['user']['id'] = 99; $variants[] = $candidate;
        $candidate = $valid; $candidate['body']['token']['accessToken'] = 'other-jwt'; $variants[] = $candidate;
        $candidate = $valid; $candidate['body']['token']['refreshToken'] = 'other-successor'; $variants[] = $candidate;
        $candidate = $valid; $candidate['body']['token']['expires'] = '2026-02-31 20:00:00'; $variants[] = $candidate;
        $candidate = $valid; $candidate['body']['user']['password_hash'] = 'must-not-return'; $variants[] = $candidate;
        foreach ($variants as $candidate) {
            $this->legacyResponse = $candidate; $auth = $this->auth($this->authority());
            try { $auth->refreshTokenOnly('old'); self::fail('Invalid original response accepted.'); }
            catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        }
        self::assertCount(count($variants), $this->calls);
    }

    public function testImportedResponseCannotEnterTheLoginCodePath(): void
    {
        $this->legacyResponse = ['version' => 'legacy-refresh-response-v1'];
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $this->auth($this->authority())->loginCodeOnly('code');
    }

    public function testAll256OpaqueRequestBytesSurviveTheBridge(): void
    {
        $key = str_repeat('x', 255) . ' ';
        $this->auth($this->authority())->refresh('old', ['idempotencyKey' => $key]);
        self::assertSame($key, end($this->calls)['headers']['Idempotency-Key']);
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(400);
        $this->auth($this->authority())->refresh('old', ['idempotencyKey' => "opaque\x01key"]);
    }

    public function testEveryQrEntryDelegatesAndContextNeverRequestsTokens(): void
    {
        $auth = $this->auth($this->authority());
        foreach (['keyToToken', 'keyToTokenWithUrl', 'loginCodeOnly'] as $method) {
            $response = $auth->$method('web_old-code');
            self::assertSame('/internal/yii3/login-code', end($this->calls)['path']);
            self::assertSame(['key' => 'web_old-code', 'operation' => 'redeem'], end($this->calls)['payload']);
            if ($method !== 'keyToToken') self::assertSame('https://d.dev.xrugc.com', $response['url']);
        }
        self::assertSame(['success' => true, 'message' => 'loginCodeContext', 'frontendDomain' => 'd.dev.xrugc.com'], $auth->loginCodeContext('raw-code'));
        self::assertSame('context', end($this->calls)['payload']['operation']);
        $this->domain = null;
        self::assertArrayNotHasKey('url', $auth->keyToTokenWithUrl('raw-code'));
        self::assertNull($auth->loginCodeOnly('raw-code')['url']);
        $this->domain = 'customer-one.dev.xrugc.com';
        self::assertSame('https://customer-one.dev.xrugc.com', $auth->keyToTokenWithUrl('code')['url']);
        $this->domain = '::1';
        self::assertSame('https://[::1]', $auth->keyToTokenWithUrl('code')['url']);
    }

    public function testLogoutRevokesIdentityOnly(): void
    {
        $result = $this->auth($this->authority())->logout('old-or-native-refresh');
        self::assertSame(['success' => true, 'message' => 'logout'], $result);
        self::assertSame('/v1/auth/logout', $this->calls[0]['path']);
    }

    public function testFailuresNeverCallLegacyStoresOrSecondWriter(): void
    {
        foreach ([400, 401, 404, 503] as $status) {
            $this->status = $status; $this->calls = [];
            $auth = $this->auth($this->authority());
            try { $auth->refresh('old-refresh'); self::fail('Request must fail.'); }
            catch (RuntimeException $error) {
                self::assertSame(in_array($status, [400, 401], true) ? $status : 503, $error->getCode());
                self::assertStringNotContainsString('remote exception', $error->getMessage());
            }
            self::assertCount(1, $this->calls);
        }
    }

    public function testNativeAccessVerificationIsLiveAndDoesNotReadLegacySigningKey(): void
    {
        $authority = $this->authority(); $jwt = new JwtService('/unreadable-legacy-key', null, null, $authority);
        self::assertSame(['user_id' => 42], $jwt->parseToken('new-or-verified-old-jwt'));
        $this->account['status'] = 0;
        self::assertNull($jwt->parseToken('same-jwt-after-account-disable'));
        $this->status = 503;
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $jwt->parseToken('same-jwt-during-outage');
    }

    public function testIndependentModeCannotGenerateLocalJwt(): void
    {
        $jwt = new JwtService('/unreadable-legacy-key', null, null, $this->authority());
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $jwt->generateToken(42);
    }

    public function testDeviceSubjectUsesPrivateCurrentRolesAndExactId(): void
    {
        $authority = $this->authority();
        self::assertSame(42, $authority->deviceAccount(42)['id']);
        self::assertSame('GET', end($this->calls)['method']);
        self::assertSame('iam-scoped-key', end($this->calls)['headers']['X-Identity-Internal-Token']);
        $this->account['roles'] = ['user', 'inherited-role', 'manager'];
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(401);
        $authority->deviceAccount(42);
    }

    public function testDeviceIssuancePreservesImmutableSourceAtIdentity(): void
    {
        $this->account += ['auth_method' => 'device_sn', 'device_sn_id' => 17];
        $this->authority()->issueDevice(42, 17);
        self::assertSame(['legacyUserId' => 42, 'auth_method' => 'device_sn', 'device_sn_id' => 17], $this->calls[0]['payload']);
        self::assertSame('auth-scoped-key', $this->calls[0]['headers']['X-Identity-Internal-Token']);
    }

    public function testDeviceIssuanceCannotShedOrReplaceItsSource(): void
    {
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $this->authority()->issueDevice(42, 17);
    }

    public function testRefreshPassesOneIdempotencyKeyWithoutPermittingHeaderInjection(): void
    {
        $auth = $this->auth($this->authority());
        $auth->refresh('current-refresh', ['idempotencyKey' => 'same-request', 'userAgent' => 'unity-test']);
        self::assertSame('same-request', end($this->calls)['headers']['Idempotency-Key']);
        self::assertSame('unity-test', end($this->calls)['headers']['User-Agent']);
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(400);
        $auth->refresh('current-refresh', ['idempotencyKey' => "evil\r\nInjected: yes"]);
    }

    public function testMiddlewareOutageIs503AndDoesNotCallBusinessHandler(): void
    {
        $this->status = 503;
        $middleware = new JwtAuthMiddleware(new JwtService('/missing', null, null, $this->authority()), new ResponseFactory(), new StreamFactory());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { throw new RuntimeException('Handler must not run.'); }
        };
        $response = $middleware->process((new ServerRequest())->withHeader('Authorization', 'Bearer example-access'), $handler);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('example-access', (string)$response->getBody());
    }

    public function testUnsafeContextAndIncompleteSourceFailClosed(): void
    {
        $this->domain = 'user:pass@evil.invalid/path';
        try { $this->authority()->loginCode('code', true); self::fail('Unsafe context must fail.'); }
        catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        $this->account['auth_method'] = 'device_sn';
        self::assertNull($this->authority()->verifyAccessToken('invalid-source'));
    }
}
