<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::get('auth/me', MeController::class)->name('api.v1.auth.me');

        Route::get('users/{user}/audit', [UserController::class, 'audit'])->name('api.v1.users.audit');
        Route::apiResource('users', UserController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:core.audit.view')
            ->name('api.v1.audit-logs.index');
    });
});
