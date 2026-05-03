<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\SwaggerController;
use App\Controller\DebugController;
use App\Controller\V1\AuthController;
use App\Controller\V1\PhototypeController;
use App\Controller\V1\ServerController;
use App\Controller\V2\SnapshotController;
use App\Controller\V2\SystemController;
use App\Controller\V2\TagController;
use App\Middleware\JwtAuthMiddleware;
use Yiisoft\Router\Route;

/**
 * Application route definitions.
 *
 * All routes are configured to match the Yii2 version exactly.
 * Routes requiring authentication use JwtAuthMiddleware at the route level.
 *
 * @see Requirements 1.5, 9.1
 */
return [
    // =========================================================================
    // V1 Auth Routes
    // =========================================================================
    Route::post('/v1/auth/login')
        ->action([AuthController::class, 'login'])
        ->name('v1.auth.login'),

    Route::post('/v1/auth/refresh')
        ->action([AuthController::class, 'refresh'])
        ->name('v1.auth.refresh'),

    Route::post('/v1/auth/key-to-token')
        ->action([AuthController::class, 'keyToToken'])
        ->name('v1.auth.key-to-token'),

    // =========================================================================
    // V1 Server Routes
    // =========================================================================
    Route::get('/v1/server/test')
        ->action([ServerController::class, 'test'])
        ->name('v1.server.test'),

    Route::get('/v1/server/public')
        ->action([ServerController::class, 'listPublic'])
        ->name('v1.server.public'),

    Route::get('/v1/server/checkin')
        ->action([ServerController::class, 'checkin'])
        ->name('v1.server.checkin'),

    Route::get('/v1/server/private')
        ->middleware(JwtAuthMiddleware::class)
        ->action([ServerController::class, 'listPrivate'])
        ->name('v1.server.private'),

    Route::get('/v1/server/group')
        ->middleware(JwtAuthMiddleware::class)
        ->action([ServerController::class, 'group'])
        ->name('v1.server.group'),

    Route::get('/v1/server/tags')
        ->action([ServerController::class, 'tags'])
        ->name('v1.server.tags'),

    Route::get('/v1/server/snapshot')
        ->action([ServerController::class, 'snapshot'])
        ->name('v1.server.snapshot'),

    Route::get('/v1/phototype/info')
        ->action([PhototypeController::class, 'info'])
        ->name('v1.phototype.info'),

    // =========================================================================
    // V2 Routes
    // =========================================================================
    Route::get('/v2/snapshots')
        ->action([SnapshotController::class, 'index'])
        ->name('v2.snapshots.index'),

    Route::get('/v2/snapshots/{id:\d+}')
        ->action([SnapshotController::class, 'view'])
        ->name('v2.snapshots.view'),

    Route::get('/v2/tags')
        ->action([TagController::class, 'index'])
        ->name('v2.tags.index'),

    Route::methods(['GET', 'HEAD'], '/v2/system')
        ->action([SystemController::class, 'index'])
        ->name('v2.system.index'),

    // =========================================================================
    // Health Check
    // =========================================================================
    Route::get('/health')
        ->action([HealthController::class, 'index'])
        ->name('health'),

    // =========================================================================
    // Debug Diagnostics
    // =========================================================================
    Route::get('/debug/snapshot')
        ->action([DebugController::class, 'snapshot'])
        ->name('debug.snapshot'),

    // =========================================================================
    // Swagger Documentation
    // =========================================================================
    Route::get('/swagger')
        ->action([SwaggerController::class, 'index'])
        ->name('swagger.index'),

    Route::get('/swagger/json-schema')
        ->action([SwaggerController::class, 'jsonSchema'])
        ->name('swagger.json-schema'),
];
