<?php

declare(strict_types=1);

use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * MrPP API - Yii3 HTTP Entry Point
 *
 * @see https://github.com/yiisoft/app-api
 */

// Ensure correct directory for relative paths
$rootPath = dirname(__DIR__);

require_once $rootPath . '/vendor/autoload.php';

// Load environment variables
if (file_exists($rootPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($rootPath);
    $dotenv->load();
}

// Set default timezone
date_default_timezone_set('Asia/Shanghai');

// Admission is default-off. The lease starts before runner/bootstrap/events;
// it completes only after the real runner has returned and source cleanup ends.
try {
    $admission = \App\Admission\AdmissionHttpLifecycle::fromEnvironment($rootPath);
    $incoming = $admission->enabled() ? \App\Admission\AdmissionHttpLifecycle::incomingCapability($_SERVER) : null;
    $_SERVER = \App\Admission\AdmissionHttpLifecycle::stripServerMarkers($_SERVER);
    $runner = new HttpApplicationRunner(
        rootPath: $rootPath,
        debug: (bool) ($_ENV['YII_DEBUG'] ?? false),
        checkEvents: (bool) ($_ENV['YII_CHECK_EVENTS'] ?? false),
        environment: null,
    );
    $admission->execute(
        static fn () => $runner->run(),
        static fn (): bool => \App\Admission\AdmissionHttpLifecycle::settled($runner->getContainer()),
        $incoming,
    );
} catch (\Throwable $error) {
    $flag = $_ENV['Y1_ADMISSION_ENABLED'] ?? getenv('Y1_ADMISSION_ENABLED');
    if ($flag === false || $flag === 'false') throw $error;
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo '{"code":"ADMISSION_UNAVAILABLE"}';
    }
}
