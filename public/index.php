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

// Create and run the HTTP application
$runner = (new HttpApplicationRunner(
    rootPath: $rootPath,
    debug: (bool) ($_ENV['YII_DEBUG'] ?? false),
    checkEvents: (bool) ($_ENV['YII_CHECK_EVENTS'] ?? false),
    environment: null,
))->run();
