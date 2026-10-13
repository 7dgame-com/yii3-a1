<?php

declare(strict_types=1);

// Mandatory, separate disposable fixture. This never reads application secrets,
// chooses a DSN from caller arguments, imports mrpp, or silently skips tests.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$report = null;
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 1) throw new RuntimeException('FIXTURE_CLI_REQUIRED');
    foreach (['Y1_REFRESH_FIXTURE' => '1', 'MYSQL_HOST' => 'codex-y1-refresh-mysql', 'MYSQL_DB' => 'y1_refresh_test',
        'MYSQL_USER' => 'root', 'MYSQL_PASS' => '', 'REDIS_HOST' => 'codex-y1-refresh-redis', 'REDIS_PORT' => '6379', 'REDIS_DB' => '0'] as $name => $value) {
        if (getenv($name) !== $value) throw new RuntimeException('DISPOSABLE_FIXTURE_ENVIRONMENT_REQUIRED');
    }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $pdo = new PDO('mysql:host=codex-y1-refresh-mysql;dbname=y1_refresh_test;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
    ]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('FIXTURE_DATABASE_MUST_BE_EMPTY');
    }
    $redis = new Predis\Client(['host' => 'codex-y1-refresh-redis', 'port' => 6379, 'database' => 0, 'timeout' => 3, 'read_write_timeout' => 3]);
    if ((int) $redis->dbsize() !== 0) throw new RuntimeException('FIXTURE_REDIS_MUST_BE_EMPTY');
    $report = tempnam(sys_get_temp_dir(), 'y1-refresh-ci-');
    if (!is_string($report) || !chmod($report, 0600)) throw new RuntimeException('PRIVATE_TEST_REPORT_REQUIRED');
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpunit', '--colors=never', '--do-not-cache-result',
        '--fail-on-skipped', '--fail-on-warning', '--fail-on-risky', '--log-junit=' . $report,
        dirname(__DIR__) . '/Integration/LegacyRefreshReplayMysqlTest.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) throw new RuntimeException('FIXTURE_PROCESS_REQUIRED');
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = ''; $errors = ''; $deadline = microtime(true) + 120; $exit = -1;
    try {
        do {
            $output .= stream_get_contents($pipes[1]); $errors .= stream_get_contents($pipes[2]);
            if (strlen($output) + strlen($errors) > 2 * 1024 * 1024 || microtime(true) >= $deadline) throw new RuntimeException('FIXTURE_EXECUTION_BOUND_EXCEEDED');
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            usleep(20000);
        } while (true);
        $output .= stream_get_contents($pipes[1]); $errors .= stream_get_contents($pipes[2]);
    } finally {
        if (proc_get_status($process)['running']) proc_terminate($process);
        fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process);
        if ($exit === -1) $exit = $closed;
    }
    if ($exit !== 0 || $errors !== '') { fwrite(STDERR, $output . $errors); throw new RuntimeException('REAL_FIXTURE_TESTS_FAILED'); }
    $xml = simplexml_load_file($report, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$xml instanceof SimpleXMLElement) throw new RuntimeException('REAL_FIXTURE_REPORT_REQUIRED');
    $cases = $xml->xpath('//testcase');
    $expected = ['testTwoConcurrentWorkersProduceOneIdenticalResponse', 'testLogoutWhileSqlPrepareIsHeldPreventsCommit',
        'testPasswordAndDisableWaitForPrepareThenRejectAllReplay', 'testAccountMutationCommittedBeforeLockNeverConsumesOldToken',
        'testRawSqlAbaCannotRestoreActiveCredentialsOrReplay', 'testUnicodeCiCaseAccentAndTrailingSpaceAbaRevokeActiveAndReplayedCredentials',
        'testNullSafeSecurityTriggerIgnoresSameBytesAndTracksActualNullTransitions', 'testSharedYiiArTreeIsPreservedDuringReviewedBackfillAndBothRevocationModes',
        'testUnknownSiblingOrOwnBodyMetadataCannotPartiallyDeleteUserKeys', 'testRevocationCompareDeleteRejectsOwnershipChangeAfterValidation',
        'testUnknownSharedNamespacePreventsReviewAndApplyBeforeAnyMetadataWrite',
        'testUnicodeUsernameMaximumAndInvalidCharactersOrBodyBounds',
        'testMissingSecurityLedgerFailsClosedAndBigintEpochStaysExact', 'testBackfillCliDefaultsToPrivateReviewAndRequiresExactHash'];
    $actual = [];
    foreach ($cases ?: [] as $case) {
        if ((string) $case['class'] !== 'App\\Tests\\Integration\\LegacyRefreshReplayMysqlTest'
            || $case->xpath('./failure|./error|./skipped') !== []) throw new RuntimeException('REAL_FIXTURE_CASE_DID_NOT_PASS');
        $actual[] = (string) $case['name'];
    }
    sort($actual); sort($expected);
    if ($actual !== $expected) throw new RuntimeException('ALL_REAL_FIXTURE_CASES_REQUIRED');
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('FIXTURE_TABLE_CLEANUP_INCOMPLETE');
    }
    echo $output;
    echo json_encode(['fixture' => 'isolated-y1-refresh-mysql-redis', 'tests' => count($actual), 'passed' => count($actual),
        'skipped' => 0, 'sourceSecuritySqlSha256' => '362e600839b22890305a9ef074e6e25869c5f19888843fb1bb34b86b8c26ac10',
        'fixtureTablesRemaining' => 0, 'mrppUsed' => false], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    $code = preg_match('/^[A-Z][A-Z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'REFRESH_FIXTURE_RUNNER_FAILED';
    fwrite(STDERR, $code . PHP_EOL); exit(1);
} finally {
    if (is_string($report) && is_file($report)) unlink($report);
}
