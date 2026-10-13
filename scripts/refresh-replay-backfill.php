<?php

declare(strict_types=1);

use App\Service\LegacyRefreshAccountRepository;
use App\Service\LegacyRefreshMetadataBackfill;
use App\Service\LegacyRefreshReplaySettings;
use App\Service\LegacyRefreshReplayStore;
use Predis\Client;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

// Operator-only CLI. Never boot the HTTP app, create schemas, activate authority or print credentials.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException();
    require dirname(__DIR__) . '/vendor/autoload.php';
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') { if (isset($options['apply'])) throw new RuntimeException(); $options['apply'] = true; continue; }
        if (preg_match('/^--(out|review|reviewed-sha256)=(.+)$/D', $argument, $match) !== 1 || isset($options[$match[1]])) throw new RuntimeException();
        $options[$match[1]] = $match[2];
    }
    $apply = isset($options['apply']);
    if ($apply ? array_diff(array_keys($options), ['apply', 'review', 'reviewed-sha256']) || !isset($options['review'], $options['reviewed-sha256'])
        : array_keys($options) !== ['out']) throw new RuntimeException();
    foreach (['MYSQL_HOST', 'MYSQL_DB', 'MYSQL_USER', 'MYSQL_PASS', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_DB',
        'Y1_REFRESH_REPLAY_ENABLED', 'Y1_REFRESH_REPLAY_KEY'] as $name) {
        if (getenv($name) !== false) $_ENV[$name] = getenv($name);
    }
    $params = require dirname(__DIR__) . '/config/common/params.php';
    if ($params['identity']['dataSource'] !== 'legacy') throw new RuntimeException();
    $redis = new Client(['scheme' => 'tcp', 'host' => $params['redis']['host'], 'port' => $params['redis']['port'], 'database' => $params['redis']['database']]);
    $store = new LegacyRefreshReplayStore($redis, new LegacyRefreshReplaySettings(...$params['legacyRefreshReplay']));
    $db = new Connection(new Driver($params['db']['dsn'], $params['db']['username'], $params['db']['password']), new SchemaCache(new ArrayCache()));
    $backfill = new LegacyRefreshMetadataBackfill($redis, $store, new LegacyRefreshAccountRepository($db), [
        'mysqlDsnHash' => hash('sha256', $params['db']['dsn']), 'mysqlHost' => $_ENV['MYSQL_HOST'] ?? 'localhost',
        'mysqlDatabase' => $_ENV['MYSQL_DB'] ?? 'mrpp', 'redisHost' => $params['redis']['host'],
        'redisPort' => $params['redis']['port'], 'redisDatabase' => $params['redis']['database'],
    ]);
    if ($apply) {
        $path = $options['review'];
        $stat = lstat($path);
        if (!$stat || !is_file($path) || is_link($path) || ($stat['mode'] & 0077) !== 0 || $stat['size'] > 32 * 1024 * 1024
            || preg_match('/^[a-f0-9]{64}$/D', $options['reviewed-sha256']) !== 1) throw new RuntimeException();
        $source = file_get_contents($path);
        if (!is_string($source) || !hash_equals($options['reviewed-sha256'], hash('sha256', $source))) throw new RuntimeException();
        $report = $backfill->apply(json_decode($source, true, 32, JSON_THROW_ON_ERROR));
        echo json_encode($report, JSON_THROW_ON_ERROR), PHP_EOL;
    } else {
        $review = $backfill->review();
        $data = json_encode($review, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $previous = umask(0077);
        $handle = fopen($options['out'], 'x');
        umask($previous);
        if ($handle === false) throw new RuntimeException();
        try { if (fwrite($handle, $data) !== strlen($data) || !fflush($handle)) throw new RuntimeException(); }
        finally { fclose($handle); }
        echo json_encode(['dryRun' => true, 'total' => count($review['entries']), 'reviewSha256' => hash('sha256', $data), 'independentReady' => false], JSON_THROW_ON_ERROR), PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, "Refresh metadata operation failed. Check exact review, source stability and private configuration; partial metadata requires a new review.\n");
    exit(1);
}
