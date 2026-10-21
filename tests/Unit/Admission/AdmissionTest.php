<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admission;

use App\Admission\{AdmissionControlClient, AdmissionDenied, AdmissionEventDispatcher, AdmissionHttpLifecycle, AdmissionLeaseScope, AdmissionSettings};
use App\Middleware\AdmissionMiddleware;
use HttpSoft\Message\{Response, ServerRequest};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class AdmissionTest extends TestCase
{
    private array $calls = [];
    private static function identity(): array { return ['id' => 'y1-old', 'bootId' => 'y1-boot-fixture', 'revision' => str_repeat('a', 40), 'routerHash' => str_repeat('b', 64), 'generation' => 'legacy', 'requiresSessionClose' => true, 'allowedChildren' => ['identity-old']]; }
    private const ROOT_ID = '11111111-1111-4111-8111-111111111111';
    private const CHILD_ID = '22222222-2222-4222-8222-222222222222';
    private static function cap(string $instanceId = 'y1-old'): string
    {
        $body = ['operationId' => $instanceId === 'y1-old' ? self::ROOT_ID : self::CHILD_ID, 'rootOperationId' => self::ROOT_ID, 'instanceId' => $instanceId, 'epoch' => 1, 'nonce' => str_repeat('a', 32)];
        return rtrim(strtr(base64_encode(json_encode($body, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . str_repeat('a', 43);
    }
    private static function controlStatus(int $end = 0, string $phase = 'legacy_open', int $epoch = 1): array
    {
        return ['phase' => $phase, 'epoch' => $epoch, 'unknownSealed' => false, 'active' => 0, 'unknown' => 0, 'instances' => [[
            'id' => 'y1-old', 'bootId' => 'y1-boot-fixture', 'generation' => 'legacy', 'registered' => true, 'lost' => false, 'lastBegin' => $end, 'lastEnd' => $end, 'closeAcknowledged' => $phase === 'closing',
        ]]];
    }
    private function scope(?\Closure $override = null, bool $enabled = true, int $childWaitMs = 1500): AdmissionLeaseScope
    {
        $settings = $enabled ? new AdmissionSettings(true, 'http://127.0.0.1:12345/', str_repeat('dummy-only', 4), self::identity(), 1000, $childWaitMs) : new AdmissionSettings();
        return new AdmissionLeaseScope($settings, new AdmissionControlClient($settings, function ($op, $payload, $headers, $timeout) use ($override): array {
            $this->calls[] = [$op, $payload];
            self::assertSame('Bearer ' . rtrim(strtr(base64_encode(str_repeat('dummy-only', 4)), '+/', '-_'), '='), $headers['Authorization']);
            self::assertGreaterThanOrEqual(1, $timeout); self::assertLessThanOrEqual(1000, $timeout);
            if ($override !== null && ($answer = $override($op, $payload)) !== null) return $answer;
            return ['status' => 200, 'body' => match ($op) {
                'begin' => ['capability' => self::cap(), 'operationId' => self::ROOT_ID, 'epoch' => 1],
                'continue' => ['capability' => self::cap('identity-old'), 'operationId' => self::CHILD_ID, 'epoch' => 1],
                'complete' => self::controlStatus(1),
                'unknown', 'lost' => ['accepted' => true],
                'close-ack' => self::controlStatus($payload['lastEnd'], 'closing', $payload['epoch']),
                'validate' => ['operationId' => self::ROOT_ID, 'rootOperationId' => self::ROOT_ID, 'epoch' => 1, 'unknown' => false, 'childrenActive' => 0],
                'enter' => ['operationId' => self::ROOT_ID, 'rootOperationId' => self::ROOT_ID, 'epoch' => 1],
                default => self::controlStatus(),
            }];
        }));
    }
    protected function tearDown(): void { AdmissionLeaseScope::install(null); unset($_ENV['Y1_ADMISSION_ENABLED']); }
    private static function handler(\Closure $fn): RequestHandlerInterface { return new class($fn) implements RequestHandlerInterface { public function __construct(private \Closure $fn) {} public function handle(ServerRequestInterface $r): ResponseInterface { return ($this->fn)($r); } }; }
    public function testDefaultOffNeverCallsControlAndStripsMarkers(): void
    {
        $scope = $this->scope(enabled: false);
        (new AdmissionHttpLifecycle($scope))->execute(function (): void {
            $r = (new ServerRequest())->withHeader('X-Identity-Admission-Lease', 'forged')->withHeader('X-Continuation', 'forged')->withHeader('X-Identity-Instance-Id', 'forged')->withHeader('Authorization', 'Bearer public');
            (new AdmissionMiddleware())->process($r, self::handler(function ($r): ResponseInterface {
                self::assertFalse($r->hasHeader('X-Identity-Admission-Lease')); self::assertFalse($r->hasHeader('X-Continuation')); self::assertFalse($r->hasHeader('X-Identity-Instance-Id'));
                self::assertSame('Bearer public', $r->getHeaderLine('Authorization')); AdmissionLeaseScope::assertWriter(); return new Response(200);
            }));
        }, static fn () => throw new \LogicException('Disabled cleanup must not run.'));
        self::assertSame([], $this->calls);
    }
    public function testCompletionIsAfterRunnerAndCleanup(): void
    {
        $scope = $this->scope(); $events = [];
        (new AdmissionHttpLifecycle($scope))->execute(function () use (&$events): void {
            (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(function () use (&$events): ResponseInterface { AdmissionLeaseScope::assertWriter(); $events[] = 'handler'; return new Response(401); }));
            self::assertSame(['register', 'begin', 'enter'], array_column($this->calls, 0));
            $events[] = 'after-request'; $events[] = 'after-emit'; $events[] = 'shutdown';
        }, function () use (&$events): bool { $events[] = 'sql-session-settled'; self::assertCount(3, $this->calls); return true; });
        self::assertSame(['handler', 'after-request', 'after-emit', 'shutdown', 'sql-session-settled'], $events);
        self::assertSame(['register', 'begin', 'enter', 'validate', 'complete'], array_column($this->calls, 0));
        self::assertSame(['handlerFinished' => true, 'sideEffectsFinished' => true, 'sessionWriteClosed' => true], $this->calls[4][1]['evidence']);
        self::assertNull(AdmissionLeaseScope::current());
    }
    #[DataProvider('unknownBoundaries')]
    public function testUnknownNeverCompletes(string $mode): void
    {
        $scope = $this->scope();
        try { (new AdmissionHttpLifecycle($scope))->execute(function () use ($mode): void {
            if ($mode === 'runner') throw new \RuntimeException('dummy');
            (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(fn () => new Response($mode === '500' ? 500 : 200)));
            if ($mode === 'event') (new AdmissionEventDispatcher(new class implements EventDispatcherInterface { public function dispatch(object $event): object { throw new \RuntimeException('event'); } }))->dispatch(new \stdClass());
        }, static fn (): bool => $mode !== 'transaction'); } catch (\RuntimeException) {}
        self::assertNotContains('complete', array_column($this->calls, 0)); self::assertContains('unknown', array_column($this->calls, 0));
    }
    public static function unknownBoundaries(): array { return [['runner'], ['500'], ['event'], ['transaction']]; }
    #[DataProvider('deniedIngress')]
    public function testKnownIngressDenialDoesNotMarkLegitimateLeaseUnknown(string $capability): void
    {
        $scope = $this->scope(fn ($op) => $op === 'enter' ? ['status' => 409, 'body' => ['code' => 'HANDLER_CAPABILITY_ALREADY_ENTERED_OR_WRONG_INSTANCE']] : null);
        try { (new AdmissionHttpLifecycle($scope))->execute(static fn () => self::fail('Handler must not run'), incoming: $capability); self::fail(); }
        catch (AdmissionDenied $e) { self::assertSame(503, $e->getCode()); }
        self::assertNotContains('unknown', array_column($this->calls, 0)); self::assertNotContains('lost', array_column($this->calls, 0));
    }
    public static function deniedIngress(): array { return [[''], ['forged'], [self::cap()], [self::cap() . ',' . self::cap()], [str_repeat('a', 2049)]]; }
    public function testClosedBeginDoesNotSealInstance(): void
    {
        $scope = $this->scope(fn ($op) => $op === 'begin' ? ['status' => 409, 'body' => ['code' => 'ROOT_ADMISSION_CLOSED']] : null);
        try { (new AdmissionHttpLifecycle($scope))->execute(static fn () => self::fail()); } catch (AdmissionDenied) {}
        self::assertSame(['register', 'begin'], array_column($this->calls, 0));
    }
    public function testLostBeginAndCompletionAcknowledgementsRemainUnknownWithoutRetry(): void
    {
        foreach (['begin', 'complete'] as $lost) {
            $this->calls = []; $scope = $this->scope(function ($op) use ($lost) { if ($op === $lost) throw new \RuntimeException('dummy capability must never appear'); return null; });
            try { (new AdmissionHttpLifecycle($scope))->execute(fn () => (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(fn () => new Response(200)))); } catch (\RuntimeException $e) { self::assertStringNotContainsString('dummy', $e->getMessage()); }
            self::assertSame(1, count(array_filter(array_column($this->calls, 0), fn ($op) => $op === $lost))); self::assertContains('lost', array_column($this->calls, 0));
        }
    }
    public function testCloseAckUsesActualCoordinatorSequences(): void
    {
        $scope = $this->scope(fn ($op) => $op === 'complete' ? ['status' => 200, 'body' => self::controlStatus(19, 'closing', 7)] : null);
        (new AdmissionHttpLifecycle($scope))->execute(fn () => (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(fn () => new Response(200))));
        self::assertSame(['close-ack', ['epoch' => 7, 'lastBegin' => 19, 'lastEnd' => 19]], $this->calls[5]);
    }
    public function testProtectedContinuationContractRequiresEnteredParentAndReviewedTarget(): void
    {
        $scope = $this->scope(); AdmissionLeaseScope::install($scope); $scope->begin();
        self::assertSame(['X-Identity-Admission-Lease' => self::cap('identity-old'), 'X-Identity-Internal-Token' => 'existing-private-auth'], $scope->continuationHeaders('identity-old', 'existing-private-auth'));
        foreach ([['unreviewed', 'auth'], ['identity-old', ''], ['identity-old', "auth\r\nInjected: yes"]] as $args) {
            try { $scope->continuationHeaders(...$args); self::fail(); } catch (\RuntimeException $e) { self::assertSame(503, $e->getCode()); }
        }
        self::assertSame(1, count(array_filter(array_column($this->calls, 0), fn ($op) => $op === 'continue')));
    }
    public function testExpiredParentCannotStartAnyContinuation(): void
    {
        $scope = $this->scope(childWaitMs: 100); AdmissionLeaseScope::install($scope); $scope->begin(); usleep(120000);
        try { $scope->continuationHeaders('identity-old', 'existing-private-auth'); self::fail('Expired parent returned headers.'); } catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        self::assertNotContains('continue', array_column($this->calls, 0));
        $this->expectException(\RuntimeException::class); AdmissionLeaseScope::assertWriter();
    }
    public function testDelayedContinuationAckCannotReturnHeadersOrRestoreWriterScope(): void
    {
        $scope = $this->scope(function ($op) { if ($op === 'continue') usleep(120000); return null; }, childWaitMs: 100);
        AdmissionLeaseScope::install($scope); $scope->begin();
        try { $scope->continuationHeaders('identity-old', 'existing-private-auth'); self::fail('Late ACK returned headers.'); } catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        self::assertSame(1, count(array_filter(array_column($this->calls, 0), static fn ($op) => $op === 'continue')));
        $this->expectException(\RuntimeException::class); AdmissionLeaseScope::assertWriter();
    }
    public function testContinuationAckCannotReviveAnUnknownScope(): void
    {
        $scope = $this->scope(function ($op) { if ($op === 'continue') AdmissionLeaseScope::unknownCurrent(); return null; });
        AdmissionLeaseScope::install($scope); $scope->begin();
        try { $scope->continuationHeaders('identity-old', 'existing-private-auth'); self::fail('Unknown scope returned headers.'); } catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        $this->expectException(\RuntimeException::class); AdmissionLeaseScope::assertWriter();
    }
    #[DataProvider('uncertainAcks')]
    public function testUncertainControlAckNeverCompletesOrRevivesWriters(string $faultOperation, int $status, array $body): void
    {
        $handlerRan = false;
        $scope = $this->scope(function ($op) use ($faultOperation, $status, $body) {
            if ($faultOperation === 'lost' && $op === 'unknown') throw new \RuntimeException('Synthetic unknown ACK loss.');
            return $op === $faultOperation ? ['status' => $status, 'body' => $body] : null;
        });
        try { (new AdmissionHttpLifecycle($scope))->execute(function () use (&$handlerRan, $faultOperation): void {
            (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(function () use (&$handlerRan, $faultOperation): ResponseInterface { $handlerRan = true; return new Response(in_array($faultOperation, ['unknown', 'lost'], true) ? 500 : 200); }));
        }); } catch (\RuntimeException $error) { self::assertNotInstanceOf(AdmissionDenied::class, $error); }
        self::assertSame(!in_array($faultOperation, ['register', 'begin', 'enter'], true), $handlerRan);
        self::assertSame($faultOperation === 'complete' ? 1 : 0, count(array_filter(array_column($this->calls, 0), static fn ($op) => $op === 'complete')));
        $scope->abandon(true);
        self::assertSame(1, count(array_filter(array_column($this->calls, 0), static fn ($op) => $op === 'lost')));
        AdmissionLeaseScope::install($scope); $this->expectException(\RuntimeException::class); AdmissionLeaseScope::assertWriter();
    }
    public static function uncertainAcks(): array
    {
        $wrongBoot = self::controlStatus(); $wrongBoot['instances'][0]['bootId'] = 'wrong-reviewed-boot';
        $sealed = self::controlStatus(); $sealed['unknownSealed'] = true;
        return [
            ['register', 200, []], ['register', 200, $wrongBoot], ['register', 200, $sealed], ['begin', 200, []],
            ['enter', 200, []], ['enter', 200, ['operationId' => self::ROOT_ID, 'rootOperationId' => self::CHILD_ID, 'epoch' => 1]],
            ['enter', 500, ['code' => 'INVALID_OPERATION_CAPABILITY']],
            ['enter', 409, ['code' => 'JOURNAL_RESULT_UNKNOWN_SEALED']],
            ['enter', 409, ['code' => 'INVALID_OPERATION_CAPABILITY', 'extra' => true]],
            ['validate', 200, ['operationId' => self::ROOT_ID, 'rootOperationId' => self::ROOT_ID, 'epoch' => 2, 'unknown' => false, 'childrenActive' => 0]],
            ['complete', 200, []], ['complete', 409, ['code' => 'ROOT_ADMISSION_CLOSED']], ['complete', 500, ['code' => 'ROOT_ADMISSION_CLOSED']],
            ['unknown', 200, []], ['lost', 200, []],
        ];
    }
    public function testStatusRequiresAnExactOwnBootAndValidCounters(): void
    {
        $settings = new AdmissionSettings(true, 'http://127.0.0.1:12345/', str_repeat('dummy-only', 4), self::identity());
        $status = self::controlStatus(); $status['instances'][0]['lastEnd'] = 1;
        $client = new AdmissionControlClient($settings, static fn () => ['status' => 200, 'body' => $status]);
        $this->expectException(\RuntimeException::class); $client->call('status');
    }
    public function testMiddlewareFailsBeforeHandlerWithoutEnteredScope(): void
    {
        $_ENV['Y1_ADMISSION_ENABLED'] = 'true'; $this->expectException(\RuntimeException::class);
        (new AdmissionMiddleware())->process(new ServerRequest(), self::handler(static fn () => self::fail()));
    }
    public function testActualConfigurationPutsAdmissionFirst(): void
    {
        $root = dirname(__DIR__, 3); $params = require $root . '/config/web/params.php';
        self::assertSame(AdmissionMiddleware::class, $params['middlewares'][0]);
        $defs = require $root . '/config/web/di/middleware.php'; self::assertSame(AdmissionMiddleware::class, $defs[AdmissionMiddleware::class]);
        self::assertStringContainsString('AdmissionEventDispatcher', file_get_contents($root . '/config/web/di/application.php'));
    }
    public function testGlobalProductionEventFactoryObservesFailuresAfterMiddlewareReturns(): void
    {
        $params = require dirname(__DIR__, 3) . '/config/web/params.php';
        $definitions = require dirname(__DIR__, 3) . '/config/web/di/application.php';
        $provider = $this->createMock(\Psr\EventDispatcher\ListenerProviderInterface::class);
        $provider->method('getListenersForEvent')->willReturn([static fn () => throw new \RuntimeException('fixture event')]);
        $events = $definitions[EventDispatcherInterface::class](new \Yiisoft\EventDispatcher\Dispatcher\Dispatcher($provider));
        self::assertInstanceOf(AdmissionEventDispatcher::class, $events);
        $scope = $this->scope(); AdmissionLeaseScope::install($scope); $scope->begin(); $scope->handlerFinished(true);
        try { $events->dispatch(new \stdClass()); } catch (\RuntimeException) {}
        $scope->finish(true); self::assertNotContains('complete', array_column($this->calls, 0));
    }
    public function testFinishedScopeCannotAuthorizeMoreWriters(): void
    {
        $scope = $this->scope(); AdmissionLeaseScope::install($scope); $scope->begin(); $scope->handlerFinished(true); $scope->finish(true);
        $this->expectException(\RuntimeException::class); AdmissionLeaseScope::assertWriter();
    }
    public function testEnabledConfigurationRequiresActualReadOnlyRevisionAndPrivateKey(): void
    {
        $dir = sys_get_temp_dir() . '/y1-admission-settings-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
        try {
            file_put_contents($dir . '/GIT_COMMIT', str_repeat('a', 40) . "\n"); chmod($dir . '/GIT_COMMIT', 0444);
            file_put_contents($dir . '/control-key', str_repeat('dummy-only', 4)); chmod($dir . '/control-key', 0600);
            $_ENV += ['Y1_ADMISSION_ENABLED' => 'true', 'Y1_ADMISSION_KEY_FILE' => $dir . '/control-key', 'Y1_ADMISSION_CONTROL_URL' => 'http://127.0.0.1:12345/', 'Y1_ADMISSION_INSTANCE_ID' => 'y1-old', 'Y1_ADMISSION_BOOT_ID' => 'y1-boot-fixture', 'Y1_ADMISSION_ROUTER_SHA256' => str_repeat('b', 64), 'Y1_ADMISSION_GENERATION' => 'legacy'];
            self::assertSame(str_repeat('a', 40), AdmissionSettings::fromEnvironment($dir)->identity['revision']);
            chmod($dir . '/control-key', 0644); clearstatcache();
            $this->expectException(\RuntimeException::class); AdmissionSettings::fromEnvironment($dir);
        } finally { chmod($dir . '/GIT_COMMIT', 0600); unlink($dir . '/GIT_COMMIT'); unlink($dir . '/control-key'); rmdir($dir); foreach (array_keys($_ENV) as $k) if (str_starts_with($k, 'Y1_ADMISSION_')) unset($_ENV[$k]); }
    }
    #[DataProvider('writers')]
    public function testServiceWriterCannotBypassRootLifecycle(string $class, string $method, array $args): void
    {
        $_ENV['Y1_ADMISSION_ENABLED'] = 'true'; $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('An entered request lease is required'); $service->$method(...$args);
    }
    public static function writers(): array
    {
        $s = 'App\\Service\\'; return [[$s.'AuthService', 'login', ['dummy', 'dummy']], [$s.'AuthService', 'refresh', ['dummy']], [$s.'AuthService', 'loginDeviceSn', ['dummy', 'dummy', false]], [$s.'AuthService', 'logout', ['dummy']], [$s.'AuthService', 'keyToToken', ['dummy']], [$s.'RefreshTokenService', 'create', [1]], [$s.'RefreshTokenService', 'consumeSession', ['dummy']], [$s.'RefreshTokenService', 'delete', ['dummy']], [$s.'RefreshTokenService', 'deleteByUserId', [1]], [$s.'JwtService', 'generateToken', [1]], [$s.'DeviceSnService', 'authenticate', ['dummy', 'dummy', true]], [$s.'DeviceSnService', 'recordLogin', [1]], [$s.'DeviceSnRateLimiter', 'consumeIp', ['127.0.0.1']], [$s.'LoginCodeStore', 'resolve', ['dummy']], [$s.'LegacyRefreshReplayService', 'rotate', ['dummy', [], fn () => null, fn () => null]]];
    }
}
