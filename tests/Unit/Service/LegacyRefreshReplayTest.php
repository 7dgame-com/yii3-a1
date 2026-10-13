<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LegacyRefreshAccountRepository;
use App\Service\LegacyRefreshMetadataBackfill;
use App\Service\LegacyRefreshReplayService;
use App\Service\LegacyRefreshReplaySettings;
use App\Service\LegacyRefreshReplayStore;
use App\Service\RefreshTokenService;
use App\Tests\Support\RedisTestClientFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Command\CommandInterface;

final class ReplayFixtureAccounts extends LegacyRefreshAccountRepository
{
    public array $user = ['id' => 42, 'username' => 'fixture-user', 'nickname' => 'fixture',
        'password_hash' => 'fixture-password-hash', 'auth_key' => 'fixture-auth-key', 'status' => 10, '_identity_account_epoch' => '1'];
    public ?\Closure $beforeLock = null;
    public bool $locked = false;
    public function __construct() {}
    public function withLockedUser(int $userId, callable $operation, bool $requireActive = true): mixed
    {
        if ($this->beforeLock) { $hook = $this->beforeLock; $this->beforeLock = null; $hook(); }
        if ($requireActive && ($userId !== $this->user['id'] || $this->user['status'] !== 10)) throw new \RuntimeException('Invalid.', 401);
        $this->locked = true;
        try { return $operation($this->user); } finally { $this->locked = false; }
    }
}

final class ReplayFaultRedis extends Client
{
    public ?string $fault = null;
    public function executeCommand(CommandInterface $command)
    {
        $arguments = $command->getArguments();
        $fault = $this->fault;
        $target = $command->getId() === 'EVAL' && ($arguments[2] ?? null) === 'rotate';
        if ($target && $fault !== null) $this->fault = null;
        if ($target && $fault === 'before') throw new \RuntimeException('Fixture transport failure.');
        $result = parent::executeCommand($command);
        if ($target && $fault === 'after') throw new \RuntimeException('Fixture response was lost.');
        return $result;
    }
}

/** Uses only explicitly configured disposable Redis in this task/CI. */
final class LegacyRefreshReplayTest extends TestCase
{
    private Client $redis;
    private LegacyRefreshReplaySettings $settings;
    private LegacyRefreshReplayStore $store;
    private ReplayFixtureAccounts $accounts;
    private LegacyRefreshReplayService $replay;
    private RefreshTokenService $refresh;

    protected function setUp(): void
    {
        $fixture = getenv('REDIS_HOST') === 'codex-y1-refresh-redis' && getenv('REDIS_DB') === '0';
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('REDIS_HOST') === '127.0.0.1' && getenv('REDIS_DB') === '0';
        if (!$fixture && !$ci) throw new \RuntimeException('Explicit disposable Redis or GitHub CI Redis is required.');
        $this->redis = RedisTestClientFactory::create();
        $this->cleanup();
        $this->settings = new LegacyRefreshReplaySettings(true, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
        $this->store = new LegacyRefreshReplayStore($this->redis, $this->settings);
        $this->accounts = new ReplayFixtureAccounts();
        $this->replay = new LegacyRefreshReplayService($this->store, $this->accounts);
        $this->refresh = new RefreshTokenService($this->redis, null, $this->replay);
    }

    protected function tearDown(): void { $this->cleanup(); }

    private function cleanup(): void
    {
        foreach (['refresh_token:*', LegacyRefreshReplayStore::PREFIX . '*'] as $pattern) {
            $cursor = '0';
            do {
                [$cursor, $keys] = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                if ($keys !== []) $this->redis->del($keys);
            } while ($cursor !== '0');
        }
    }

    private function scanKeys(string $pattern): array
    {
        $cursor = '0'; $keys = [];
        do {
            [$cursor, $batch] = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            foreach ($batch as $key) $keys[$key] = true;
        } while ($cursor !== '0');
        $names = array_keys($keys); sort($names, SORT_STRING); return $names;
    }

    private function rotate(string $raw, string $key = '', ?callable $prepare = null): ?array
    {
        return $this->replay->rotate($raw, ['idempotencyKey' => $key], function (): void {
            self::assertTrue($this->accounts->locked);
        }, $prepare ?? function (array $user, array $context, string $successor): array {
            self::assertTrue($this->accounts->locked);
            return self::response($user, $successor);
        });
    }

    private static function response(array $user, string $successor): array
    {
        return ['token' => ['accessToken' => 'fixture.access.token', 'expires' => '2026-10-13 12:00:00', 'refreshToken' => $successor],
            'nickname' => $user['nickname'], 'user' => ['id' => $user['id'], 'username' => $user['username'], 'nickname' => $user['nickname'], 'fixture' => false]];
    }

    private function expectStatus(int $status, callable $operation): void
    {
        try { $operation(); self::fail('Expected failure.'); }
        catch (\RuntimeException $error) { self::assertSame($status, $error->getCode()); }
    }

    public function testFeatureOffNeedsNoKeyAndPreservesConsumeOnce(): void
    {
        $settings = new LegacyRefreshReplaySettings();
        self::assertFalse($settings->enabled); self::assertNull($settings->key);
        $legacy = new RefreshTokenService($this->redis);
        $token = $legacy->create(42);
        self::assertSame(42, $legacy->consume($token));
        self::assertNull($legacy->consume($token));
        self::assertSame([], $this->scanKeys(LegacyRefreshReplayStore::PREFIX . '*'));
    }

    public function testEnabledRequiresExplicitCanonicalStableKey(): void
    {
        foreach (['', 'not-a-key', str_repeat('a', 43) . '='] as $invalid) {
            try { new LegacyRefreshReplaySettings(true, $invalid); self::fail('Invalid key accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testLostResponseReplaysSamePairAndEnvelopeAcrossServiceRestart(): void
    {
        $old = $this->refresh->create(42);
        $first = $this->rotate($old, 'stable-request');
        $store = new LegacyRefreshReplayStore($this->redis, $this->settings);
        $restarted = new LegacyRefreshReplayService($store, $this->accounts);
        $again = $restarted->rotate($old, ['idempotencyKey' => 'stable-request'], fn () => null,
            function () { self::fail('Replay must not mint.'); });
        self::assertSame($first, $again);
        self::assertNull($this->redis->get('refresh_token:' . $old));
        self::assertSame('42', $this->redis->get('refresh_token:' . $first['token']['refreshToken']));
        self::assertLessThanOrEqual(2592000, $this->redis->ttl('refresh_token:' . $first['token']['refreshToken']));
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testNoKeyGetsBoundedReplayAndSameKeySurvivesFiveSecondGrace(): void
    {
        $old = $this->refresh->create(42);
        $first = $this->rotate($old, 'stable-request');
        self::assertSame($first, $this->rotate($old));
        $key = LegacyRefreshReplayStore::PREFIX . 'response:' . hash('sha256', $old);
        $cached = json_decode($this->redis->get($key), true, 32, JSON_THROW_ON_ERROR);
        $cached['created_at_ms'] -= 6000;
        $cached['expires_at_ms'] -= 6000;
        $this->redis->setex($key, 20, json_encode($cached, JSON_THROW_ON_ERROR));
        $this->expectStatus(401, fn () => $this->rotate($old));
        $this->expectStatus(401, fn () => $this->rotate($old, 'different-request'));
        self::assertSame($first, $this->rotate($old, 'stable-request'));
        $this->redis->del($key);
        $this->expectStatus(401, fn () => $this->rotate($old, 'stable-request'));
    }

    public function testLogoutPredecessorOrSuccessorCannotReplayOrRefresh(): void
    {
        foreach ([true, false] as $usePredecessor) {
            $old = $this->refresh->create(42);
            $first = $this->rotate($old, 'stable-request');
            $next = $first['token']['refreshToken'];
            $this->refresh->delete($usePredecessor ? $old : $next);
            $this->expectStatus(401, fn () => $this->rotate($old, 'stable-request'));
            $this->expectStatus(401, fn () => $this->rotate($next));
            self::assertNull($this->redis->get('refresh_token:' . $next));
        }
    }

    public function testLogoutDuringPreparePreventsSuccessorCommit(): void
    {
        $old = $this->refresh->create(42);
        $this->expectStatus(401, fn () => $this->rotate($old, '', function (array $user, array $context, string $next) use ($old): array {
            $this->refresh->delete($old);
            return self::response($user, $next);
        }));
        self::assertSame([], $this->scanKeys('refresh_token:*'));
    }

    public function testLogoutDuringReplayAuthorizationCannotReturnAStaleRead(): void
    {
        $old = $this->refresh->create(42); $this->rotate($old, 'stable-request');
        $this->expectStatus(401, fn () => $this->replay->rotate($old, ['idempotencyKey' => 'stable-request'],
            fn () => $this->refresh->delete($old), fn () => self::fail('Replay cannot mint.')));
    }

    public function testExpiredCredentialKeepsClassificationAndDoesNotBecomeQrMiss(): void
    {
        $old = $this->refresh->create(42);
        $key = LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $old);
        $meta = json_decode($this->redis->get($key), true, 32, JSON_THROW_ON_ERROR);
        $meta['expires_at_ms'] -= 2592000001;
        $meta['issued_at_ms'] -= 2592000001;
        $this->redis->set($key, json_encode($meta, JSON_THROW_ON_ERROR));
        $this->redis->del('refresh_token:' . $old);
        self::assertSame(-1, $this->redis->ttl($key));
        $this->expectStatus(401, fn () => $this->rotate($old));
    }

    public function testMalformedMetadataFailsBeforeAnyRotationWrites(): void
    {
        $old = $this->refresh->create(42);
        $key = LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $old);
        $meta = json_decode($this->redis->get($key), true, 32, JSON_THROW_ON_ERROR);
        $meta['unexpected'] = true;
        $this->redis->set($key, json_encode($meta, JSON_THROW_ON_ERROR));
        $this->expectStatus(503, fn () => $this->rotate($old));
        self::assertSame('42', $this->redis->get('refresh_token:' . $old));
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testUserEpochInvalidatesPreparedRotationAndExistingReplay(): void
    {
        $old = $this->refresh->create(42);
        $this->expectStatus(401, fn () => $this->rotate($old, '', function (array $user, array $context, string $next): array {
            $this->store->advanceEpoch(42);
            return self::response($user, $next);
        }));
        $new = $this->refresh->create(42);
        $pair = $this->rotate($new, 'stable-request');
        $this->refresh->deleteByUserId(42);
        $this->expectStatus(401, fn () => $this->rotate($new, 'stable-request'));
        self::assertNull($this->refresh->validate($pair['token']['refreshToken']));
    }

    public function testPasswordChangeBeforeLockAndDisableBeforeReplayRejectWithoutMutation(): void
    {
        $old = $this->refresh->create(42);
        $this->accounts->beforeLock = function (): void { $this->accounts->user['password_hash'] = 'changed'; };
        $this->expectStatus(401, fn () => $this->rotate($old));
        self::assertSame('42', $this->redis->get('refresh_token:' . $old));
        $new = $this->refresh->create(42);
        $this->rotate($new, 'stable-request');
        $this->accounts->user['status'] = 0;
        $this->expectStatus(401, fn () => $this->rotate($new, 'stable-request'));
    }

    public function testIssuanceCannotBindAStalePasswordProof(): void
    {
        $before = LegacyRefreshAccountRepository::fingerprint($this->accounts->user);
        $this->accounts->user['password_hash'] = 'changed';
        $this->expectStatus(401, fn () => $this->refresh->create(42, [], $before));
        self::assertSame([], $this->scanKeys('refresh_token:*'));
    }

    public function testSecurityEpochRejectsAbaWithUnchangedFingerprint(): void
    {
        $old = $this->refresh->create(42); $pair = $this->rotate($old, 'stable-request');
        $this->accounts->user['_identity_account_epoch'] = '3';
        $this->expectStatus(401, fn () => $this->rotate($old, 'stable-request'));
        $this->expectStatus(401, fn () => $this->rotate($pair['token']['refreshToken']));
        self::assertNull($this->refresh->validate($pair['token']['refreshToken']));
    }

    public function testDeviceProvenanceIsUnchangedAndIdentityOnlyConsumeIsBlocked(): void
    {
        $context = ['auth_method' => 'device_sn', 'device_sn_id' => 19];
        $old = $this->refresh->create(42, $context);
        $seen = [];
        $pair = $this->replay->rotate($old, [], function (int $id, array $source) use (&$seen): void { $seen = [$id, $source]; },
            fn (array $user, array $source, string $next) => self::response($user, $next));
        self::assertSame([42, $context], $seen);
        self::assertStringStartsWith('dsn_', $pair['token']['refreshToken']);
        self::assertSame(['user_id' => 42] + $context, LegacyRefreshReplayStore::decodeSession($this->redis->get('refresh_token:' . $pair['token']['refreshToken']), $pair['token']['refreshToken']));
        self::assertNull($this->refresh->validate($pair['token']['refreshToken']));
        $this->expectStatus(503, fn () => $this->refresh->consumeSession($pair['token']['refreshToken']));
    }

    public function testUnknownAndUnannotatedCredentialsHaveDistinctClassification(): void
    {
        self::assertNull($this->rotate(bin2hex(random_bytes(32))));
        $raw = bin2hex(random_bytes(32)); $this->redis->setex('refresh_token:' . $raw, 60, '42');
        $this->expectStatus(503, fn () => $this->rotate($raw));
        self::assertSame('42', $this->redis->get('refresh_token:' . $raw));
    }

    public function testResponseEncryptionBindsTheSuccessorAndRejectsTamper(): void
    {
        $old = $this->refresh->create(42); $this->rotate($old, 'stable-request');
        $key = LegacyRefreshReplayStore::PREFIX . 'response:' . hash('sha256', $old);
        $cached = json_decode($this->redis->get($key), true, 32, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('fixture.access.token', $this->redis->get($key));
        $cached['encrypted_response'][10] = $cached['encrypted_response'][10] === 'A' ? 'B' : 'A';
        $this->redis->setex($key, 30, json_encode($cached, JSON_THROW_ON_ERROR));
        $this->expectStatus(503, fn () => $this->rotate($old, 'stable-request'));
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testFailureBeforeCommitPreservesPredecessorAndLostCommitResponseReplays(): void
    {
        $connection = $this->redis->getConnection()->getParameters();
        $faultRedis = new ReplayFaultRedis(['host' => $connection->host, 'port' => $connection->port, 'database' => $connection->database ?? 0]);
        $faultStore = new LegacyRefreshReplayStore($faultRedis, $this->settings);
        $replay = new LegacyRefreshReplayService($faultStore, $this->accounts);
        $old = $this->refresh->create(42);
        $operation = fn () => $replay->rotate($old, ['idempotencyKey' => 'stable-request'], fn () => null,
            fn (array $user, array $source, string $next) => self::response($user, $next));
        $faultRedis->fault = 'before'; $this->expectStatus(503, $operation);
        self::assertSame('42', $this->redis->get('refresh_token:' . $old));
        $faultRedis->fault = 'after'; $this->expectStatus(503, $operation);
        self::assertNull($this->redis->get('refresh_token:' . $old));
        $result = $operation();
        self::assertSame('42', $this->redis->get('refresh_token:' . $result['token']['refreshToken']));
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testMetadataBackfillIsExplicitAndNeverExtendsCredentialTtl(): void
    {
        $legacy = new RefreshTokenService($this->redis); $raw = $legacy->create(42);
        $before = $this->redis->executeRaw(['PEXPIRETIME', 'refresh_token:' . $raw]);
        $backfill = new LegacyRefreshMetadataBackfill($this->redis, $this->store, $this->accounts, $this->scope());
        $review = $backfill->review();
        self::assertNull($this->store->capture($raw)['metadata']);
        $report = $backfill->apply(json_decode(json_encode($review, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(1, $report['applied']); self::assertFalse($report['independentReady']);
        self::assertSame($before, $this->redis->executeRaw(['PEXPIRETIME', 'refresh_token:' . $raw]));
        self::assertSame('active', $this->store->inspect($raw)['status']);
        self::assertSame(0, $backfill->apply($backfill->review())['applied']);
    }

    public function testBackfillRejectsChangedNamespaceAccountEpochAndValue(): void
    {
        foreach (['namespace', 'account', 'account-epoch', 'epoch', 'value', 'source'] as $change) {
            $this->cleanup(); $this->accounts->user['password_hash'] = 'fixture-password-hash'; $this->accounts->user['_identity_account_epoch'] = '1';
            $raw = (new RefreshTokenService($this->redis))->create(42);
            $backfill = new LegacyRefreshMetadataBackfill($this->redis, $this->store, $this->accounts, $this->scope());
            $review = $backfill->review();
            match ($change) {
                'namespace' => (new RefreshTokenService($this->redis))->create(42),
                'account' => $this->accounts->user['password_hash'] = 'changed',
                'account-epoch' => $this->accounts->user['_identity_account_epoch'] = '2',
                'source' => $review['source']['redisDatabase'] = 1,
                'epoch' => $this->store->advanceEpoch(42),
                'value' => $this->redis->setex('refresh_token:' . $raw, 30, '43'),
            };
            $this->expectStatus($change === 'source' ? 400 : 409, fn () => $backfill->apply($review));
            self::assertNull($this->store->capture($raw)['metadata']);
        }
    }

    private function scope(): array
    {
        return ['mysqlDsnHash' => hash('sha256', 'fixture-dsn'), 'mysqlHost' => 'codex-y1-refresh-mysql',
            'mysqlDatabase' => 'y1_refresh_test', 'redisHost' => 'codex-y1-refresh-redis', 'redisPort' => 6379, 'redisDatabase' => 0];
    }
}
