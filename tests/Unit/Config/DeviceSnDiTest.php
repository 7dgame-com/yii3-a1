<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Controller\V1\AuthController;
use App\Service\DeviceSnService;
use App\Service\JwtService;
use App\Service\RefreshTokenService;
use App\Tests\Support\RedisTestClientFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

final class DeviceSnDiTest extends TestCase
{
    public function testRuntimeDefinitionsWireLimiterAndSnChecksIntoControllerAndJwt(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'sn-di');
        file_put_contents($key, 'test-sn-di-native-signing-key-32-bytes');
        $redis = RedisTestClientFactory::create();
        $params = require dirname(__DIR__, 3) . '/config/common/params.php';
        $params['jwt']['keyFile'] = $key;
        $definitions = array_merge(
            require dirname(__DIR__, 3) . '/config/common/di/services.php',
            require dirname(__DIR__, 3) . '/config/common/di/jwt.php',
        );
        $sn = $this->createMock(DeviceSnService::class);
        $sn->expects($this->once())->method('authenticate')->willReturn([
            'user_id' => 42, 'device_sn_id' => 17, 'username' => 'test-user', 'nickname' => 'test-user',
        ]);
        $sn->expects($this->exactly(2))->method('authorizeSession');
        $definitions[DeviceSnService::class] = $sn;
        $definitions[ConnectionInterface::class] = $this->createMock(ConnectionInterface::class);
        $definitions[Client::class] = $redis;
        $definitions[LoggerInterface::class] = new NullLogger();
        $definitions[ResponseFactoryInterface::class] = new ResponseFactory();
        $definitions[StreamFactoryInterface::class] = new StreamFactory();
        try {
            $container = new Container(ContainerConfig::create()->withDefinitions($definitions));
            $controller = $container->get(AuthController::class);
            $request = (new ServerRequest())->withParsedBody(['sn' => '0000111122223333', 'uuid' => 'di-test-device']);
            $response = $controller->snActivate($request);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true);
            $this->assertSame(17, $container->get(JwtService::class)->parseToken($data['token']['accessToken'])['device_sn_id']);
            $container->get(RefreshTokenService::class)->delete($data['token']['refreshToken']);
        } finally {
            unlink($key);
            foreach ([['ip', 'unknown'], ['sn', '0000111122223333'], ['uuid', 'di-test-device']] as [$kind, $value]) {
                $redis->del('device-sn:rate:' . $kind . ':' . hash('sha256', $value));
            }
        }
    }
}
