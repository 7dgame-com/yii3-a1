<?php
/** Isolated fixture only. Runs real Yii3 HttpApplicationRunner + production admission classes. */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Admission\{AdmissionHttpLifecycle, AdmissionLeaseScope};
use App\Middleware\AdmissionMiddleware;
use HttpSoft\Message\{Response, Stream};
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\NullLogger;
use Yiisoft\Cache\NullCache;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\{Connection, Driver};
use Yiisoft\Di\{Container, ContainerConfig};
use Yiisoft\ErrorHandler\{ErrorHandler, Middleware\ErrorCatcher, Renderer\PlainTextRenderer};
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$dir = getenv('Y1_ADMISSION_FIXTURE_DIR');
$dbName = getenv('Y1_ADMISSION_FIXTURE_DB');
$mysqlHost = getenv('Y1_ADMISSION_FIXTURE_MYSQL'); $redisHost = getenv('Y1_ADMISSION_FIXTURE_REDIS');
if (!is_string($dir) || !str_starts_with($dir, '/tmp/y1-admission-http-') || !preg_match('/^y1_admission_fixture_[a-z0-9_]+$/D', (string) $dbName)
    || $mysqlHost !== 'codex-y1-namespace-mysql-20261013' || $redisHost !== 'codex-y1-namespace-redis-20261013') throw new RuntimeException('ISOLATED_FIXTURE_REQUIRED');
$prefix = $dbName . ':';
$redis = new Predis\Client(['host' => $redisHost, 'port' => 6379, 'database' => 13]);
if (PHP_SAPI === 'cli') {
    $pdo = new PDO('mysql:host=' . $mysqlHost . ';charset=utf8mb4', 'root', ''); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (($argv[1] ?? '') === '--setup') {
        $pdo->exec('CREATE DATABASE `' . $dbName . '`'); $pdo->exec('CREATE TABLE `' . $dbName . '`.effects (id BIGINT AUTO_INCREMENT PRIMARY KEY, mode VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        echo "fixture-ready\n";
    } elseif (($argv[1] ?? '') === '--report') {
        echo json_encode(['rows' => $pdo->query('SELECT mode FROM `' . $dbName . '`.effects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'pending' => $redis->get($prefix . 'pending'), 'cleanup' => $redis->get($prefix . 'cleanup')], JSON_THROW_ON_ERROR);
    } elseif (($argv[1] ?? '') === '--cleanup') {
        $redis->del($prefix . 'pending', $prefix . 'cleanup'); $pdo->exec('DROP DATABASE `' . $dbName . '`'); echo "fixture-cleaned\n";
    } else throw new RuntimeException('FIXTURE_COMMAND_REQUIRED');
    exit;
}
$mode = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$event = static function (string $name) use ($dir): void { file_put_contents($dir . '/events', $name . "\n", FILE_APPEND | LOCK_EX); };
$db = new Connection(new Driver('mysql:host=' . $mysqlHost . ';dbname=' . $dbName . ';charset=utf8mb4', 'root', ''), new SchemaCache(new NullCache()));
$events = new class($event, $mode) implements EventDispatcherInterface {
    public function __construct(private Closure $record, private string $mode) {}
    public function dispatch(object $event): object {
        $name = (new ReflectionClass($event))->getShortName(); ($this->record)($name);
        if ($name === 'AfterMiddleware' && $this->mode === 'event-outerafter' && $event->getMiddleware() instanceof AdmissionMiddleware) throw new RuntimeException('Fixture outer dispatcher failure.');
        if ($name === 'AfterEmit' && $this->mode === 'disconnect') usleep(200000);
        if ($this->mode === 'event-' . strtolower($name)) throw new RuntimeException('Fixture lifecycle failure.');
        return $event;
    }
};
$business = new class($event, $mode, $db, $redis, $prefix, $dir) implements MiddlewareInterface {
    public function __construct(private Closure $record, private string $mode, private Connection $db, private Predis\Client $redis, private string $prefix, private string $dir) {}
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        AdmissionLeaseScope::assertWriter(); ($this->record)('handler-start'); file_put_contents($this->dir . '/pid', (string) getmypid());
        foreach (array_keys($request->getServerParams()) as $name) if (str_starts_with($name, 'HTTP_') && AdmissionMiddleware::privateMarker(str_replace('_', '-', substr($name, 5)))) throw new RuntimeException('Private marker remained in server params.');
        foreach (array_keys($request->getHeaders()) as $header) if (preg_match('/^x-(?:identity-)?admission|^x-.*continu|^x-identity-(?:instance|router|generation)/i', $header)) throw new RuntimeException('Public marker reached fixture handler.');
        if ($this->mode === 'kill') { while (true) usleep(100000); }
        if ($this->mode === 'exit') exit(0);
        if ($this->mode === 'fatal') { ini_set('memory_limit', '20M'); $unused = str_repeat('x', 64 * 1024 * 1024); }
        $tx = $this->db->beginTransaction();
        try {
            $this->db->createCommand('INSERT INTO effects (mode) VALUES (:mode)', [':mode' => $this->mode])->execute();
            $this->redis->set($this->prefix . 'pending', 'dummy-only');
            if ($this->mode === 'exception') throw new RuntimeException('Fixture handler failure.');
            if ($this->mode === 'disconnect') usleep(250000);
            if ($this->mode !== 'transaction') { $tx->commit(); ($this->record)('sql-commit'); }
            if ($this->mode === 'session') { session_save_path($this->dir); session_start(); $_SESSION['fixture'] = 'dummy-only'; ($this->record)('session-open'); }
            if (str_starts_with($this->mode, 'parent')) {
                if ($this->mode === 'parent-expired') usleep(200000);
                $headers = AdmissionLeaseScope::current()->continuationHeaders('other-old', 'existing-fixture-internal-auth');
                file_put_contents($this->dir . '/child', json_encode($headers, JSON_THROW_ON_ERROR)); chmod($this->dir . '/child', 0600);
            }
            if ($this->mode === 'emit') { echo 'fixture-early-output'; flush(); }
            $body = $this->mode === 'disconnect' ? str_repeat('x', 16 * 1024 * 1024) : json_encode(['mode' => $this->mode, 'publicAuthorization' => $request->getHeaderLine('Authorization')], JSON_THROW_ON_ERROR);
            return new Response($this->mode === '500' ? 500 : 200, ['Content-Type' => 'application/json'], (new \HttpSoft\Message\StreamFactory())->createStream($body));
        } catch (Throwable $error) { if ($tx->isActive()) { $tx->rollBack(); ($this->record)('sql-rollback'); } throw $error; }
        finally { $this->redis->del($this->prefix . 'pending'); $this->redis->incr($this->prefix . 'cleanup'); ($this->record)('handler-finally'); }
    }
};
$params = require dirname(__DIR__, 2) . '/config/web/params.php';
// Keep the production first/error boundaries and production Application DI.
$params['middlewares'] = [AdmissionMiddleware::class, ErrorCatcher::class, 'fixture.business'];
$definitions = require dirname(__DIR__, 2) . '/config/common/di/http.php';
$definitions += require dirname(__DIR__, 2) . '/config/web/di/middleware.php';
$definitions += require dirname(__DIR__, 2) . '/config/web/di/application.php';
$definitions[EventDispatcherInterface::class] = new \App\Admission\AdmissionEventDispatcher($events);
$definitions += ['fixture.business' => $business, ConnectionInterface::class => $db,
    ErrorHandler::class => new ErrorHandler(new class($dir) extends \Psr\Log\AbstractLogger { public function __construct(private string $dir) {} public function log($level, string|Stringable $message, array $context = []): void { file_put_contents($this->dir . '/errors', ($context['throwable'] ?? new RuntimeException('missing'))->getMessage()); } }, new PlainTextRenderer()),
    ErrorCatcher::class => static fn (ContainerInterface $c): ErrorCatcher => new ErrorCatcher(new HttpSoft\Message\ResponseFactory(), $c->get(ErrorHandler::class), $c, $c->get(EventDispatcherInterface::class))];
$container = new Container(ContainerConfig::create()->withDefinitions($definitions));
$config = new class implements ConfigInterface { public function get(string $group): array { return []; } public function has(string $group): bool { return false; } };
try {
    $admission = AdmissionHttpLifecycle::fromEnvironment($dir);
    $incoming = $admission->enabled() ? AdmissionHttpLifecycle::incomingCapability($_SERVER) : null; $_SERVER = AdmissionHttpLifecycle::stripServerMarkers($_SERVER);
    $runner = (new HttpApplicationRunner(rootPath: $dir))->withContainer($container)->withConfig($config);
    $admission->execute(static fn () => $runner->run(), static function () use ($container, $event): bool { $event('settled'); return AdmissionHttpLifecycle::settled($container); }, $incoming);
    $event('outer-return');
} catch (Throwable) {
    $event('outer-denied'); if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json'); echo '{"code":"ADMISSION_UNAVAILABLE"}'; }
}
