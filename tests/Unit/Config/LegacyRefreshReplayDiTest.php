<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Service\LegacyRefreshAccountRepository;
use App\Service\LegacyRefreshReplayService;
use App\Service\LegacyRefreshReplaySettings;
use App\Service\LegacyRefreshReplayStore;
use App\Service\RefreshTokenService;
use App\Tests\Support\RedisTestClientFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

final class LegacyRefreshReplayDiTest extends TestCase
{
    public function testDefaultConfigurationResolvesWithoutLedgerOrPrivateKey(): void
    {
        $saved = $_ENV;
        try {
            $_ENV['Y1_REFRESH_REPLAY_ENABLED'] = 'false';
            $_ENV['Y1_REFRESH_REPLAY_KEY'] = '';
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';
            self::assertFalse($params['legacyRefreshReplay']['enabled']);
            $definitions = require dirname(__DIR__, 3) . '/config/common/di/services.php';
            $db = $this->createMock(ConnectionInterface::class);
            $db->expects($this->never())->method('createCommand');
            $definitions[ConnectionInterface::class] = $db;
            $definitions[Client::class] = new Client('tcp://127.0.0.1:1');
            $container = new Container(ContainerConfig::create()->withDefinitions($definitions));
            self::assertFalse($container->get(LegacyRefreshReplayService::class)->enabled());
            self::assertNull($container->get(LegacyRefreshReplaySettings::class)->key);
        } finally { $_ENV = $saved; }
    }

    public function testEnabledRuntimeInjectsAccountLockedIssuance(): void
    {
        $fixture = getenv('REDIS_HOST') === 'codex-y1-refresh-redis' && getenv('REDIS_DB') === '0';
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('REDIS_HOST') === '127.0.0.1' && getenv('REDIS_DB') === '0';
        if (!$fixture && !$ci) throw new \RuntimeException('Disposable Redis is required.');
        $saved = $_ENV; $raw = null;
        $redis = RedisTestClientFactory::create();
        try {
            $_ENV['Y1_REFRESH_REPLAY_ENABLED'] = 'true';
            $_ENV['Y1_REFRESH_REPLAY_KEY'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $params = require dirname(__DIR__, 3) . '/config/common/params.php';
            $params['identity']['dataSource'] = 'legacy';
            $definitions = require dirname(__DIR__, 3) . '/config/common/di/services.php';
            $accounts = new class extends LegacyRefreshAccountRepository {
                public int $lockedCalls = 0;
                public function __construct() {}
                public function withLockedUser(int $userId, callable $operation, bool $requireActive = true): mixed
                {
                    $this->lockedCalls++;
                    return $operation(['id' => $userId, 'status' => 10, 'password_hash' => 'fixture', 'auth_key' => 'fixture', '_identity_account_epoch' => '9007199254740993']);
                }
            };
            $definitions[Client::class] = $redis;
            $definitions[LegacyRefreshAccountRepository::class] = $accounts;
            $container = new Container(ContainerConfig::create()->withDefinitions($definitions));
            $raw = $container->get(RefreshTokenService::class)->create(42);
            self::assertSame(1, $accounts->lockedCalls);
            $state = $container->get(LegacyRefreshReplayStore::class)->inspect($raw);
            self::assertSame('active', $state['status']);
            self::assertSame('9007199254740993', $state['metadata']['account_epoch']);
        } finally {
            $_ENV = $saved;
            if ($raw !== null) {
                $hash = hash('sha256', $raw);
                $encoded = $redis->get(LegacyRefreshReplayStore::PREFIX . 'credential:' . $hash);
                if (is_string($encoded)) {
                    $meta = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
                    $redis->del(LegacyRefreshReplayStore::PREFIX . 'family:' . $meta['family_id']);
                }
                $redis->del('refresh_token:' . $raw, LegacyRefreshReplayStore::PREFIX . 'credential:' . $hash);
            }
        }
    }
}
