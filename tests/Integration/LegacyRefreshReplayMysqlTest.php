<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\LegacyRefreshAccountRepository;
use App\Service\LegacyRefreshReplayService;
use App\Service\LegacyRefreshReplaySettings;
use App\Service\LegacyRefreshReplayStore;
use App\Service\RefreshTokenService;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Command\CommandInterface;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

final class RefreshNamespaceSwapRedis extends Client
{
    public ?string $replace = null;
    public function executeCommand(CommandInterface $command)
    {
        $arguments = $command->getArguments();
        if ($this->replace !== null && $command->getId() === 'EVAL' && str_contains($arguments[0] ?? '', '-- y1-namespace-compare-delete-v1')) {
            $key = $this->replace; $this->replace = null; $this->set($key, '99');
        }
        return parent::executeCommand($command);
    }
}

/** Real, separate processes/SQL transactions. Hard guard prevents business DB use. */
final class LegacyRefreshReplayMysqlTest extends TestCase
{
    private const DSN = 'mysql:host=codex-y1-refresh-mysql;dbname=y1_refresh_test;charset=utf8mb4';
    private const MARKER = 'y1_refresh_continuity_fixture_v1';
    private const SECURITY_SCHEMA_SHA256 = '362e600839b22890305a9ef074e6e25869c5f19888843fb1bb34b86b8c26ac10';
    private static bool $createdUser = false;
    private static bool $createdLedger = false;
    private \PDO $pdo;
    private Client $redis;
    private string $key;
    private LegacyRefreshReplayService $service;
    private RefreshTokenService $refresh;
    private array $files = [];
    private array $workers = [];

    public static function setUpBeforeClass(): void
    {
        if (getenv('Y1_REFRESH_FIXTURE') !== '1' || getenv('MYSQL_HOST') !== 'codex-y1-refresh-mysql'
            || getenv('MYSQL_DB') !== 'y1_refresh_test' || getenv('REDIS_HOST') !== 'codex-y1-refresh-redis' || getenv('REDIS_DB') !== '0') {
            throw new \RuntimeException('Explicit disposable refresh fixture is required.');
        }
        $pdo = new \PDO(self::DSN, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $check = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('user','identity_legacy_security_versions')")->fetchAll();
        if ($check !== []) throw new \RuntimeException('Fixture refuses an existing user table.');
        $sql = file_get_contents(dirname(__DIR__) . '/Fixtures/legacy-refresh-security-schema.sql');
        if (!is_string($sql) || hash('sha256', $sql) !== self::SECURITY_SCHEMA_SHA256) throw new \RuntimeException('Reviewed fixture security SQL changed.');
        $statements = preg_split('/^-- statement\R/m', $sql);
        if (!is_array($statements) || count($statements) !== 6) throw new \RuntimeException('Reviewed fixture security SQL is incomplete.');
        $pdo->exec("CREATE TABLE user (id INT PRIMARY KEY, username VARCHAR(255), nickname VARCHAR(255), password_hash VARCHAR(255), auth_key VARCHAR(255), status INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='" . self::MARKER . "'");
        self::$createdUser = true;
        foreach (array_slice($statements, 1) as $index => $statement) {
            $pdo->exec($statement);
            if ($index === 0) self::$createdLedger = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$createdUser) return;
        $pdo = new \PDO(self::DSN, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $comment = $pdo->query("SELECT TABLE_COMMENT FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user'")->fetchColumn();
        if ($comment !== self::MARKER) throw new \RuntimeException('Fixture ownership changed; no cleanup was attempted.');
        $pdo->exec('DROP TABLE user'); self::$createdUser = false;
        if (self::$createdLedger) { $pdo->exec('DROP TABLE identity_legacy_security_versions'); self::$createdLedger = false; }
    }

    protected function setUp(): void
    {
        $this->pdo = new \PDO(self::DSN, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("DELETE FROM user");
        $this->pdo->exec("DELETE FROM identity_legacy_security_versions");
        $this->pdo->exec("INSERT INTO user VALUES (42,'fixture-user','fixture','fixture-original','fixture-auth-key',10)");
        $this->redis = new Client(['host' => 'codex-y1-refresh-redis', 'port' => 6379, 'database' => 0]);
        // Only the hard-guarded, newly created disposable Redis is ever flushed.
        $this->redis->flushdb();
        $this->key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = new Connection(new Driver(self::DSN, 'root', ''), new SchemaCache(new ArrayCache()));
        $store = new LegacyRefreshReplayStore($this->redis, new LegacyRefreshReplaySettings(true, $this->key));
        $this->service = new LegacyRefreshReplayService($store, new LegacyRefreshAccountRepository($db));
        $this->refresh = new RefreshTokenService($this->redis, null, $this->service);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        foreach ($this->workers as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
        }
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
    }

    private function marker(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'y1-refresh-marker-'); unlink($path); $this->files[] = $path; return $path;
    }

    private function worker(string $operation, string $raw, array $extra = []): array
    {
        $path = tempnam(sys_get_temp_dir(), 'y1-refresh-state-'); chmod($path, 0600); $this->files[] = $path;
        file_put_contents($path, json_encode(['operation' => $operation, 'raw' => $raw, 'key' => $this->key] + $extra, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/manual/refresh_replay_worker.php', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start fixture worker.');
        fclose($pipes[0]); unset($pipes[0]);
        $worker = [$process, $pipes]; $this->workers[] = $worker; return $worker;
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        self::assertSame(0, $exit); self::assertSame('', $error);
        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }

    private function awaitMarker(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($path)) {
            if (microtime(true) > $deadline) self::fail('Fixture worker did not reach barrier.');
            usleep(1000);
        }
    }

    private function assertRejected(string $raw): void
    {
        try { $this->service->rotate($raw, ['idempotencyKey' => 'fixture-stable-request'], fn () => null,
            fn () => throw new \RuntimeException('Invalid replay minted.', 500)); self::fail('Invalid refresh succeeded.'); }
        catch (\RuntimeException $error) { self::assertSame(401, $error->getCode()); }
    }

    public function testTwoConcurrentWorkersProduceOneIdenticalResponse(): void
    {
        $old = $this->refresh->create(42); $start = microtime(true) + 0.2;
        $one = $this->worker('rotate', $old, ['startAt' => $start]);
        $two = $this->worker('rotate', $old, ['startAt' => $start]);
        $a = $this->finish($one); $b = $this->finish($two);
        self::assertSame(200, $a['status']); self::assertSame($a, $b);
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testLogoutWhileSqlPrepareIsHeldPreventsCommit(): void
    {
        $old = $this->refresh->create(42); $prepared = $this->marker(); $release = $this->marker();
        $worker = $this->worker('rotate', $old, ['preparedMarker' => $prepared, 'releaseMarker' => $release]);
        $this->awaitMarker($prepared); $this->refresh->delete($old); touch($release);
        self::assertSame(401, $this->finish($worker)['status']);
        self::assertSame([], $this->scanKeys('refresh_token:*'));
    }

    public function testPasswordAndDisableWaitForPrepareThenRejectAllReplay(): void
    {
        foreach (['password', 'disable', 'revoke-user'] as $operation) {
            $this->pdo->exec("UPDATE user SET password_hash='fixture-original',status=10 WHERE id=42");
            $old = $this->refresh->create(42); $prepared = $this->marker(); $release = $this->marker(); $started = $this->marker();
            $refresh = $this->worker('rotate', $old, ['preparedMarker' => $prepared, 'releaseMarker' => $release]);
            $this->awaitMarker($prepared);
            $mutation = $this->worker($operation, $old, ['startedMarker' => $started]);
            $this->awaitMarker($started); usleep(200000); touch($release);
            $rotated = $this->finish($refresh); $changed = $this->finish($mutation);
            self::assertSame(200, $rotated['status']); self::assertSame(200, $changed['status']);
            self::assertGreaterThanOrEqual(100, $changed['elapsedMs']);
            $this->assertRejected($old); $this->assertRejected($rotated['response']['token']['refreshToken']);
        }
    }

    public function testAccountMutationCommittedBeforeLockNeverConsumesOldToken(): void
    {
        $old = $this->refresh->create(42); $started = $this->marker();
        $this->pdo->beginTransaction(); $this->pdo->exec("UPDATE user SET password_hash='fixture-changed' WHERE id=42");
        $worker = $this->worker('rotate', $old, ['startedMarker' => $started]);
        $this->awaitMarker($started); usleep(100000); $this->pdo->commit();
        self::assertSame(401, $this->finish($worker)['status']);
        self::assertSame('42', $this->redis->get('refresh_token:' . $old));
        self::assertSame(1, count($this->scanKeys('refresh_token:*')));
    }

    public function testRawSqlAbaCannotRestoreActiveCredentialsOrReplay(): void
    {
        foreach (['status', 'password_hash', 'auth_key', 'delete-reinsert'] as $change) {
            $active = $this->refresh->create(42);
            $old = $this->refresh->create(42);
            $rotated = $this->finish($this->worker('rotate', $old));
            self::assertSame(200, $rotated['status']);
            $before = $this->pdo->query('SELECT CAST(epoch AS CHAR) FROM identity_legacy_security_versions WHERE user_id=42')->fetchColumn();
            if ($change === 'delete-reinsert') {
                $this->pdo->exec('DELETE FROM user WHERE id=42');
                $this->pdo->exec("INSERT INTO user VALUES (42,'fixture-user','fixture','fixture-original','fixture-auth-key',10)");
            } else {
                $original = ['status' => '10', 'password_hash' => "'fixture-original'", 'auth_key' => "'fixture-auth-key'"][$change];
                $temporary = $change === 'status' ? '0' : "'temporary-raw-sql-value'";
                $this->pdo->exec("UPDATE user SET $change=$temporary WHERE id=42");
                $this->pdo->exec("UPDATE user SET $change=$original WHERE id=42");
            }
            $after = $this->pdo->query('SELECT CAST(epoch AS CHAR) FROM identity_legacy_security_versions WHERE user_id=42')->fetchColumn();
            self::assertGreaterThan((int) $before, (int) $after);
            $this->assertRejected($active); $this->assertRejected($old);
            $this->assertRejected($rotated['response']['token']['refreshToken']);
            self::assertNull($this->refresh->validate($active));
            // A fresh post-change login may issue against the new ledger epoch.
            self::assertSame(42, $this->refresh->validate($this->refresh->create(42)));
        }
    }

    public function testUnicodeCiCaseAccentAndTrailingSpaceAbaRevokeActiveAndReplayedCredentials(): void
    {
        self::assertSame('utf8mb4_unicode_ci', $this->pdo->query("SELECT TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user'")->fetchColumn());
        foreach (['password_hash' => 'fixture-original', 'auth_key' => 'fixture-auth-key'] as $column => $original) {
            foreach ([strtoupper($original), str_replace('fixture', 'fixturé', $original), $original . ' '] as $temporary) {
                // These compare equal under the actual legacy collation, but
                // their byte changes must still advance the persistent epoch.
                $equal = $this->pdo->prepare('SELECT CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci <=> CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci');
                $equal->execute([$original, $temporary]); self::assertSame(1, (int) $equal->fetchColumn());
                $active = $this->refresh->create(42); $old = $this->refresh->create(42);
                $rotated = $this->finish($this->worker('rotate', $old)); self::assertSame(200, $rotated['status']);
                $before = (int) $this->pdo->query('SELECT CAST(epoch AS CHAR) FROM identity_legacy_security_versions WHERE user_id=42')->fetchColumn();
                $update = $this->pdo->prepare("UPDATE user SET `$column`=? WHERE id=42");
                $update->execute([$temporary]); $update->execute([$original]);
                $after = (int) $this->pdo->query('SELECT CAST(epoch AS CHAR) FROM identity_legacy_security_versions WHERE user_id=42')->fetchColumn();
                self::assertSame($before + 2, $after);
                $this->assertRejected($active); $this->assertRejected($old); $this->assertRejected($rotated['response']['token']['refreshToken']);
                self::assertNull($this->refresh->validate($active));
                self::assertSame(42, $this->refresh->validate($this->refresh->create(42)));
            }
        }
    }

    public function testNullSafeSecurityTriggerIgnoresSameBytesAndTracksActualNullTransitions(): void
    {
        $epoch = fn (): int => (int) $this->pdo->query('SELECT CAST(epoch AS CHAR) FROM identity_legacy_security_versions WHERE user_id=42')->fetchColumn();
        foreach (['password_hash' => 'fixture-original', 'auth_key' => 'fixture-auth-key'] as $column => $original) {
            $before = $epoch(); $this->pdo->exec("UPDATE user SET `$column`=NULL WHERE id=42"); self::assertSame($before + 1, $epoch());
            $this->pdo->exec("UPDATE user SET `$column`=NULL WHERE id=42"); self::assertSame($before + 1, $epoch());
            $update = $this->pdo->prepare("UPDATE user SET `$column`=? WHERE id=42"); $update->execute([$original]); self::assertSame($before + 2, $epoch());
            $update->execute([$original]); self::assertSame($before + 2, $epoch());
        }
    }

    private function reviewedSiblingTree(): array
    {
        $this->redis->set('refresh_token:s:id', '42'); // Equals the revocation target; it must never be deleted as a user session.
        $this->redis->rpush('refresh_token', ['1', '2', '3', '4', '5']); // Actual YiiRedisAR RPUSH primary-key pool.
        $rows = [
            '1' => ['id' => '1', 'user_id' => '42', 'key' => str_repeat('a', 32)],
            '2' => ['id' => '2', 'user_id' => '42', 'key' => str_repeat('b', 64), 'session_id' => '', 'created_at' => '1', 'expires_at' => '2'],
            '3' => ['id' => '3', 'user_id' => '42', 'key' => str_repeat('c', 64), 'session_id' => 'fixture-session', 'created_at' => '1', 'expires_at' => '2', 'user_agent' => "binary-agent-\xff", 'ip' => ''],
            '4' => ['id' => '4', 'user_id' => '42', 'key' => '', 'session_id' => '', 'created_at' => '', 'expires_at' => '', 'user_agent' => '', 'ip' => '', 'revoked_at' => '', 'auth_method' => '', 'device_sn_id' => ''],
            '5' => ['id' => '5', 'user_id' => '42', 'key' => str_repeat('d', 64), 'session_id' => 'sn-session', 'created_at' => '1', 'expires_at' => '2', 'user_agent' => 'fixture', 'ip' => '127.0.0.1', 'revoked_at' => '3', 'auth_method' => 'device_sn', 'device_sn_id' => '5'],
        ];
        foreach ($rows as $id => $fields) $this->redis->hmset('refresh_token:a:' . $id, $fields);
        $this->redis->pexpire('refresh_token:a:3', 60000);
        return ['refresh_token', 'refresh_token:s:id', 'refresh_token:a:1', 'refresh_token:a:2', 'refresh_token:a:3', 'refresh_token:a:4', 'refresh_token:a:5'];
    }

    private function siblingSnapshot(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) $result[$key] = [$this->redis->dump($key), $this->redis->executeRaw(['PEXPIRETIME', $key])];
        return $result;
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

    public function testSharedYiiArTreeIsPreservedDuringReviewedBackfillAndBothRevocationModes(): void
    {
        $siblings = $this->reviewedSiblingTree(); $before = $this->siblingSnapshot($siblings);
        $this->pdo->exec("INSERT INTO user VALUES (99,'other-fixture-user','other','fixture-other','fixture-other-auth',10)");
        $legacy = new RefreshTokenService($this->redis);
        $normal = $legacy->create(42); $device = $legacy->create(42, ['auth_method' => 'device_sn', 'device_sn_id' => 5]); $other = $legacy->create(99);
        $reviewPath = $this->marker(); $dry = $this->cli(['--out=' . $reviewPath]); self::assertSame(0, $dry['exit']);
        $report = json_decode($dry['stdout'], true, 32, JSON_THROW_ON_ERROR); self::assertSame(3, $report['total']);
        $review = json_decode(file_get_contents($reviewPath), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('y1-refresh-metadata-review-v2', $review['version']); self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $review['siblingInventorySha256']);
        // Changing even a recognized sibling after review rejects before the first Y1 metadata write.
        $this->redis->hset('refresh_token:a:2', 'expires_at', '3');
        $arguments = ['--apply', '--review=' . $reviewPath, '--reviewed-sha256=' . $report['reviewSha256']];
        self::assertSame(1, $this->cli($arguments)['exit']); self::assertSame([], $this->scanKeys(LegacyRefreshReplayStore::PREFIX . '*'));
        $this->redis->hset('refresh_token:a:2', 'expires_at', '2');
        $this->redis->del('refresh_token:s:id'); $this->redis->rpush('refresh_token:s:id', ['42']);
        self::assertSame(1, $this->cli($arguments)['exit']); self::assertSame([], $this->scanKeys(LegacyRefreshReplayStore::PREFIX . '*'));
        $this->redis->del('refresh_token:s:id'); $this->redis->set('refresh_token:s:id', '42');
        self::assertSame(0, $this->cli($arguments)['exit']); self::assertSame($before, $this->siblingSnapshot($siblings));
        $rotated = $this->finish($this->worker('rotate', $normal)); self::assertSame(200, $rotated['status']);
        $this->refresh->deleteByUserId(42);
        self::assertNull($this->redis->get('refresh_token:' . $rotated['response']['token']['refreshToken'])); self::assertNull($this->redis->get('refresh_token:' . $device));
        $this->assertRejected($normal); self::assertSame('99', $this->redis->get('refresh_token:' . $other)); self::assertSame(99, $this->refresh->validate($other));
        self::assertSame($before, $this->siblingSnapshot($siblings));
        $oldStyle = $legacy->create(42); $legacy->deleteByUserId(42);
        self::assertNull($this->redis->get('refresh_token:' . $oldStyle)); self::assertSame('99', $this->redis->get('refresh_token:' . $other));
        self::assertSame($before, $this->siblingSnapshot($siblings));
    }

    public function testUnknownSiblingOrOwnBodyMetadataCannotPartiallyDeleteUserKeys(): void
    {
        foreach (['unknown-field', 'wrong-counter-type', 'wrong-counter-value', 'unknown-counter', 'unknown-key', 'wrong-ar-id', 'noncanonical-ar-id', 'missing-ar-field', 'wrong-list-type', 'wrong-list-id', 'wrong-y1-type', 'wrong-y1-body', 'wrong-sn-body', 'wrong-y1-metadata'] as $fault) {
            $this->redis->flushdb(); $siblings = $this->reviewedSiblingTree();
            $raw = $this->refresh->create(42); $other = (new RefreshTokenService($this->redis))->create(99);
            if ($fault === 'unknown-field') $this->redis->hset('refresh_token:a:1', 'unreviewed_field', 'unknown');
            elseif ($fault === 'wrong-counter-type') { $this->redis->del('refresh_token:s:id'); $this->redis->rpush('refresh_token:s:id', ['42']); }
            elseif ($fault === 'wrong-counter-value') $this->redis->set('refresh_token:s:id', '042');
            elseif ($fault === 'unknown-counter') $this->redis->set('refresh_token:s:other', '1');
            elseif ($fault === 'unknown-key') $this->redis->set('refresh_token:unreviewed', '42');
            elseif ($fault === 'wrong-ar-id') $this->redis->hset('refresh_token:a:1', 'id', '2');
            elseif ($fault === 'noncanonical-ar-id') $this->redis->hmset('refresh_token:a:01', ['id' => '01', 'user_id' => '42', 'key' => 'fixture']);
            elseif ($fault === 'missing-ar-field') $this->redis->hdel('refresh_token:a:1', ['key']);
            elseif ($fault === 'wrong-list-type') { $this->redis->del('refresh_token'); $this->redis->set('refresh_token', '42'); }
            elseif ($fault === 'wrong-list-id') $this->redis->rpush('refresh_token', ['01']);
            elseif ($fault === 'wrong-y1-type') $this->redis->hmset('refresh_token:' . str_repeat('d', 64), ['user_id' => '42']);
            elseif ($fault === 'wrong-y1-body') $this->redis->set('refresh_token:' . str_repeat('e', 64), 'not-a-session');
            elseif ($fault === 'wrong-sn-body') $this->redis->set('refresh_token:dsn_' . str_repeat('f', 64), '42');
            else { $key = LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $raw); $this->redis->del($key); $this->redis->hmset($key, ['user_id' => '42']); }
            $before = $this->siblingSnapshot($siblings); $epochKey = LegacyRefreshReplayStore::PREFIX . 'user:42:epoch';
            $wholeBefore = $this->siblingSnapshot($this->scanKeys('*'));
            try { (new RefreshTokenService($this->redis))->deleteByUserId(42); self::fail('Legacy revocation skipped an unknown namespace.'); }
            catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
            self::assertSame($wholeBefore, $this->siblingSnapshot($this->scanKeys('*')));
            try { $this->refresh->deleteByUserId(42); self::fail('Unknown namespace was silently skipped.'); }
            catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
            // Authorized epoch advancement is conservative; no physical Y1 key or AR data may be deleted after a failed preflight.
            self::assertSame('1', $this->redis->get($epochKey)); self::assertSame('42', $this->redis->get('refresh_token:' . $raw));
            self::assertSame('99', $this->redis->get('refresh_token:' . $other)); self::assertSame($before, $this->siblingSnapshot($siblings));
        }
    }

    public function testRevocationCompareDeleteRejectsOwnershipChangeAfterValidation(): void
    {
        $legacy = new RefreshTokenService($this->redis);
        $tokens = [$legacy->create(42), $legacy->create(42)];
        usort($tokens, static fn (string $left, string $right): int => strcmp(hash('sha256', $left), hash('sha256', $right)));
        [$first, $raw] = $tokens;
        $redis = new RefreshNamespaceSwapRedis(['host' => 'codex-y1-refresh-redis', 'port' => 6379, 'database' => 0]);
        $redis->replace = 'refresh_token:' . $raw;
        try { (new RefreshTokenService($redis))->deleteByUserId(42); self::fail('Changed ownership was deleted.'); }
        catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        self::assertSame('42', $this->redis->get('refresh_token:' . $first));
        self::assertSame('99', $this->redis->get('refresh_token:' . $raw));
    }

    public function testUnknownSharedNamespacePreventsReviewAndApplyBeforeAnyMetadataWrite(): void
    {
        $legacy = new RefreshTokenService($this->redis);
        foreach (['unknown-field', 'unknown-key', 'wrong-type', 'invalid-sn', 'invalid-metadata'] as $fault) {
            $this->redis->flushdb(); $this->reviewedSiblingTree();
            $normal = $legacy->create(42); $device = $legacy->create(42, ['auth_method' => 'device_sn', 'device_sn_id' => 5]);
            $review = $this->marker(); $dry = $this->cli(['--out=' . $review]); self::assertSame(0, $dry['exit']);
            $report = json_decode($dry['stdout'], true, 32, JSON_THROW_ON_ERROR);
            if ($fault === 'unknown-field') $this->redis->hset('refresh_token:a:2', 'unknown', 'fixture');
            elseif ($fault === 'unknown-key') $this->redis->set('refresh_token:unknown', '42');
            elseif ($fault === 'wrong-type') { $this->redis->del('refresh_token:a:3'); $this->redis->set('refresh_token:a:3', '42'); }
            elseif ($fault === 'invalid-sn') $this->redis->set('refresh_token:' . $device, '{"version":1,"user_id":42}');
            else $this->redis->set(LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $normal), '{}');
            $before = $this->siblingSnapshot($this->scanKeys('*'));
            $rejectedReview = $this->marker();
            self::assertSame(1, $this->cli(['--out=' . $rejectedReview])['exit']); self::assertFileDoesNotExist($rejectedReview);
            self::assertSame($before, $this->siblingSnapshot($this->scanKeys('*')));
            self::assertSame(1, $this->cli(['--apply', '--review=' . $review, '--reviewed-sha256=' . $report['reviewSha256']])['exit']);
            self::assertSame($before, $this->siblingSnapshot($this->scanKeys('*')));
        }
    }

    public function testUnicodeUsernameMaximumAndInvalidCharactersOrBodyBounds(): void
    {
        $maximum = str_repeat('用', 255);
        $update = $this->pdo->prepare('UPDATE user SET username=? WHERE id=42'); $update->execute([$maximum]);
        $old = $this->refresh->create(42); $rotated = $this->finish($this->worker('rotate', $old));
        self::assertSame(200, $rotated['status']); self::assertSame($maximum, $rotated['response']['user']['username']);
        self::assertSame($rotated, $this->finish($this->worker('rotate', $old)));
        foreach ([['username' => str_repeat('用', 256)], ['username' => "\xc3\x28"], ['oversize' => true]] as $invalid) {
            $raw = $this->refresh->create(42); $keysBefore = $this->scanKeys('refresh_token:*');
            try {
                $this->service->rotate($raw, ['idempotencyKey' => 'fixture-response-bound'], fn () => null,
                    function (array $user, array $context, string $next) use ($invalid): array {
                        return ['token' => ['accessToken' => isset($invalid['oversize']) ? str_repeat('a', 16384) : 'fixture.access',
                            'expires' => '2026-10-13 12:00:00', 'refreshToken' => $next],
                            'nickname' => isset($invalid['oversize']) ? str_repeat('n', 4096) : 'fixture',
                            'user' => ['id' => 42, 'username' => $invalid['username'] ?? $user['username'],
                                'nickname' => isset($invalid['oversize']) ? str_repeat('n', 4096) : 'fixture', 'fixture' => false]];
                    });
                self::fail('Invalid replay response was accepted.');
            } catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
            self::assertSame('42', $this->redis->get('refresh_token:' . $raw));
            $keysAfter = $this->scanKeys('refresh_token:*'); self::assertSame($keysBefore, $keysAfter);
            self::assertNull($this->redis->get(LegacyRefreshReplayStore::PREFIX . 'response:' . hash('sha256', $raw)));
        }
    }

    public function testMissingSecurityLedgerFailsClosedAndBigintEpochStaysExact(): void
    {
        $old = $this->refresh->create(42);
        $this->pdo->exec('DELETE FROM identity_legacy_security_versions WHERE user_id=42');
        try { $this->refresh->create(42); self::fail('Missing ledger accepted.'); }
        catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        try { $this->service->rotate($old, [], fn () => null, fn () => []); self::fail('Missing ledger replay accepted.'); }
        catch (\RuntimeException $error) { self::assertSame(503, $error->getCode()); }
        self::assertSame('42', $this->redis->get('refresh_token:' . $old));
        $this->pdo->exec("INSERT INTO identity_legacy_security_versions(user_id,epoch) VALUES(42,18446744073709551615)");
        $next = $this->refresh->create(42);
        $meta = json_decode($this->redis->get(LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $next)), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('18446744073709551615', $meta['account_epoch']);
        $rotated = $this->finish($this->worker('rotate', $next));
        self::assertSame(200, $rotated['status']);
        self::assertSame($rotated, $this->finish($this->worker('rotate', $next)));
        try { $this->pdo->exec('UPDATE user SET status=0 WHERE id=42'); self::fail('Overflow allowed source security mutation.'); }
        catch (\PDOException) { self::assertSame(10, (int) $this->pdo->query('SELECT status FROM user WHERE id=42')->fetchColumn()); }
        // Restore only this test fixture, so the next fixture teardown can run its delete trigger.
        $this->pdo->exec('UPDATE identity_legacy_security_versions SET epoch=1 WHERE user_id=42');
    }

    public function testBackfillCliDefaultsToPrivateReviewAndRequiresExactHash(): void
    {
        $raw = (new RefreshTokenService($this->redis))->create(42);
        $expires = $this->redis->executeRaw(['PEXPIRETIME', 'refresh_token:' . $raw]);
        $review = $this->marker();
        $dry = $this->cli(['--out=' . $review]);
        self::assertSame(0, $dry['exit']); self::assertSame('', $dry['stderr']);
        $report = json_decode($dry['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($report['dryRun']); self::assertFalse($report['independentReady']);
        self::assertSame(0600, fileperms($review) & 0777);
        self::assertStringNotContainsString($raw, file_get_contents($review));
        self::assertStringNotContainsString('fixture-original', file_get_contents($review));
        self::assertNull($this->redis->get(LegacyRefreshReplayStore::PREFIX . 'credential:' . hash('sha256', $raw)));
        self::assertSame(1, $this->cli(['--out=' . $review])['exit']);
        self::assertSame(1, $this->cli(['--apply', '--review=' . $review, '--reviewed-sha256=' . str_repeat('0', 64)])['exit']);
        self::assertSame(1, $this->cli(['--apply', '--review=' . $review])['exit']);
        $applied = $this->cli(['--apply', '--review=' . $review, '--reviewed-sha256=' . $report['reviewSha256']]);
        self::assertSame(0, $applied['exit']); self::assertSame('', $applied['stderr']);
        self::assertSame(1, json_decode($applied['stdout'], true, 32, JSON_THROW_ON_ERROR)['applied']);
        self::assertSame($expires, $this->redis->executeRaw(['PEXPIRETIME', 'refresh_token:' . $raw]));
    }

    private function cli(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/refresh-replay-backfill.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start fixture CLI.');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
