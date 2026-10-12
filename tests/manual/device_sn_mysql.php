<?php

declare(strict_types=1);

/**
 * Isolated MySQL integration/concurrency test for y1 device authorization.
 * Requires DEVICE_SN_TEST_HARNESS=1 and MYSQL_DB=y1_sn_test. Every table must be absent;
 * this script creates marked tables, rejects existing data and drops only its own tables.
 * Run in a disposable container/network, never against a development or production database.
 */

use App\Service\DeviceSnService;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

ini_set('display_errors', '0');
ini_set('log_errors', '0');

const DEVICE_SN_HARNESS_MARKER = 'y1_device_sn_test_harness_v1';

function harnessGuard(): void
{
    if (PHP_SAPI !== 'cli' || getenv('DEVICE_SN_TEST_HARNESS') !== '1'
        || getenv('MYSQL_DB') !== 'y1_sn_test' || getenv('MYSQL_HOST') !== 'codex-y1-sn-mysql') {
        throw new RuntimeException('Isolated device SN test environment is required.');
    }
}

function harnessDsn(): string
{
    return 'mysql:host=' . getenv('MYSQL_HOST') . ';dbname=y1_sn_test;charset=utf8mb4';
}

function harnessPdo(): PDO
{
    return new PDO(harnessDsn(), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function harnessService(): DeviceSnService
{
    $driver = new Driver(harnessDsn(), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASS'));
    return new DeviceSnService(new Connection($driver, new SchemaCache(new ArrayCache())));
}

function harnessSn(int $index): string
{
    // Synthetic, deterministic test values. Never read or print real credentials.
    return sprintf('%016X', $index);
}

function harnessCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $label);
    }
}

function harnessExpectStatus(callable $operation, int $status): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        harnessCheck($exception->getCode() === $status, 'expected HTTP status');
        return;
    }
    throw new RuntimeException('Expected operation to fail.');
}

/** @param list<array{sn: int, uuid: string}> $requests */
function harnessRace(array $requests): array
{
    $processes = [];
    $start = sprintf('%.6f', microtime(true) + 0.5);
    foreach ($requests as $request) {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, __FILE__, '--worker', (string) $request['sn'], $request['uuid'], $start,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start isolated worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        harnessCheck(proc_close($process) === 0, 'worker completion');
        $result = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
        harnessCheck(is_int($result['status'] ?? null), 'worker response');
        $statuses[] = $result['status'];
    }
    sort($statuses);
    return $statuses;
}

$created = [];
$pdo = null;
$result = ['ok' => false, 'error' => 'device_sn_mysql_test_failed'];
$exitCode = 1;
$phase = 'environment';

try {
    harnessGuard();
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (($argv[1] ?? '') === '--worker') {
        $start = (float) ($argv[4] ?? 0);
        while (microtime(true) < $start) {
            usleep(1000);
        }
        try {
            harnessService()->authenticate(harnessSn((int) $argv[2]), (string) $argv[3], true);
            $status = 200;
        } catch (RuntimeException $exception) {
            $status = in_array($exception->getCode(), [401, 409, 503], true) ? $exception->getCode() : 500;
        }
        echo json_encode(['status' => $status], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }

    $phase = 'connect';
    $pdo = harnessPdo();
    $tables = ['user', 'auth_assignment', 'device_sn', 'audit_log'];
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($tables as $table) {
        $check->execute([$table]);
        harnessCheck((int) $check->fetchColumn() === 0, 'test table must be absent');
    }

    $phase = 'schema';
    $schemas = [
        'user' => '(`id` INT NOT NULL PRIMARY KEY, `username` VARCHAR(64) NOT NULL, '
            . '`nickname` VARCHAR(64) NOT NULL, `status` SMALLINT NOT NULL DEFAULT 10)',
        'auth_assignment' => '(`user_id` VARCHAR(64) NOT NULL, `item_name` VARCHAR(64) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `item_name`))',
        'device_sn' => '(`id` INT NOT NULL PRIMARY KEY, `user_id` INT NULL, `original_user_id` INT NULL, '
            . '`sn_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, '
            . '`enabled` TINYINT NOT NULL DEFAULT 1, '
            . '`device_uuid` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE, '
            . '`activated_at` DATETIME NULL, `updated_at` DATETIME NULL, `last_login_at` DATETIME NULL, '
            . 'FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL)',
        'audit_log' => '(`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `event_type` VARCHAR(64) NOT NULL, '
            . '`user_id` INT NULL, `ip_address` VARCHAR(64) NULL, `action` VARCHAR(64) NOT NULL, '
            . '`resource` VARCHAR(255) NOT NULL, `context` JSON NULL, `created_at` DATETIME NOT NULL)',
    ];
    foreach ($schemas as $table => $schema) {
        $pdo->exec('CREATE TABLE `' . $table . '` ' . $schema . " ENGINE=InnoDB COMMENT='" . DEVICE_SN_HARNESS_MARKER . "'");
        $created[] = $table;
    }
    $pdo->exec("INSERT INTO `user` (`id`, `username`, `nickname`) VALUES (42, 'sn-fixture-user', 'Fixture')");
    $insert = $pdo->prepare('INSERT INTO `device_sn` (`id`,`user_id`,`original_user_id`,`sn_hash`) VALUES (?,42,42,?)');
    for ($i = 1; $i <= 10; $i++) {
        $insert->execute([$i, hash('sha256', harnessSn($i))]);
    }
    $phase = 'activation';
    $service = harnessService();
    $phase = 'first_activation';
    $first = $service->authenticate(harnessSn(1), 'fixture-one', true);
    harnessCheck($first['device_sn_id'] === 1 && $first['user_id'] === 42, 'activation identity');
    $service->authenticate(harnessSn(1), 'FIXTURE-ONE', true);
    $service->authenticate(harnessSn(1), 'fixture-one', false);
    harnessCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 1, 'idempotent audit');
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'different', true), 409);
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(2), 'fixture-one', true), 409);
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(2), 'unactivated', false), 409);

    $phase = 'concurrency';
    harnessCheck(harnessRace([['sn' => 3, 'uuid' => 'race-a'], ['sn' => 3, 'uuid' => 'race-b']]) === [200, 409], 'same SN race');
    harnessCheck(harnessRace([['sn' => 4, 'uuid' => 'race-shared'], ['sn' => 5, 'uuid' => 'race-shared']]) === [200, 409], 'same UUID race');
    harnessCheck(harnessRace([['sn' => 6, 'uuid' => 'race-pair'], ['sn' => 6, 'uuid' => 'race-pair']]) === [200, 200], 'same pair race');
    harnessCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'activate'")->fetchColumn() === 4, 'committed activation audit count');
    harnessCheck((int) $pdo->query('SELECT COUNT(*) FROM device_sn WHERE device_uuid IS NOT NULL')->fetchColumn() === 4, 'unique activation count');

    $phase = 'session_authorization';
    $pdo->exec('UPDATE device_sn SET enabled = 0 WHERE id = 1');
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'fixture-one', false), 401);
    harnessExpectStatus(fn () => $service->authorizeSession(1, 42), 401);
    $service->authorizeSession(1, 42, false);
    $pdo->exec('UPDATE device_sn SET enabled = 1 WHERE id = 1');
    $service->authorizeSession(1, 42);

    $pdo->exec('UPDATE user SET status = 0 WHERE id = 42');
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'fixture-one', false), 401);
    harnessExpectStatus(fn () => $service->authorizeSession(1, 42, false), 401);
    $pdo->exec('UPDATE user SET status = 10 WHERE id = 42');
    $role = $pdo->prepare('INSERT INTO auth_assignment (user_id, item_name) VALUES (42, ?)');
    foreach (['manager', 'admin', 'root'] as $privilegedRole) {
        $role->execute([$privilegedRole]);
        harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'fixture-one', false), 401);
        harnessExpectStatus(fn () => $service->authorizeSession(1, 42, false), 401);
        $pdo->exec('DELETE FROM auth_assignment');
    }
    $service->recordLogin(1);
    harnessCheck($pdo->query('SELECT last_login_at FROM device_sn WHERE id = 1')->fetchColumn() !== null, 'last login update');

    $phase = 'account_deletion';
    $pdo->exec('DELETE FROM user WHERE id = 42');
    harnessCheck($pdo->query('SELECT user_id FROM device_sn WHERE id = 1')->fetchColumn() === null, 'deleted account tombstone');
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'fixture-one', false), 401);
    harnessExpectStatus(fn () => $service->authorizeSession(1, 42, false), 401);
    $pdo->exec("INSERT INTO user (id,username,nickname) VALUES (42,'replacement','Replacement')");
    harnessExpectStatus(fn () => $service->authenticate(harnessSn(1), 'fixture-one', true), 401);
    harnessExpectStatus(fn () => $service->authorizeSession(1, 42, false), 401);

    $result = ['ok' => true, 'checks' => [
        'activation_and_idempotency', 'sn_and_uuid_conflicts', 'three_concurrent_activation_scenarios',
        'transactional_audit', 'disabled_sn_access_and_refresh', 'disabled_and_privileged_accounts',
        'deleted_account_and_id_reuse', 'last_login_record',
    ]];
    $exitCode = 0;
} catch (Throwable $exception) {
    // Never print database driver messages, connection details or credential material.
    $result['phase'] = $phase;
    $result['error_type'] = get_class($exception);
    $result['error_code'] = (int) $exception->getCode();
} finally {
    if ($pdo instanceof PDO) {
        try {
            foreach (array_reverse($created) as $table) {
                $check = $pdo->prepare('SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $check->execute([$table]);
                harnessCheck($check->fetchColumn() === DEVICE_SN_HARNESS_MARKER, 'owned table cleanup');
                $pdo->exec('DROP TABLE `' . $table . '`');
            }
        } catch (Throwable) {
            $result = ['ok' => false, 'error' => 'device_sn_mysql_test_cleanup_failed'];
            $exitCode = 1;
        }
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
exit($exitCode);
