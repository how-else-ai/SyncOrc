<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceRegisterController;
use App\Http\Controllers\SyncAnnounceController;
use App\Http\Controllers\SyncAckController;
use App\Http\Controllers\SyncPollController;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;

// Public: device registration with the orchestration server.
Route::post('/devices', [DeviceRegisterController::class, 'store']);

// Orchestrated sync — bearer token = device sync_token (see POST /api/devices).
Route::middleware(EnsureDeviceToken::class)->group(function () {
    Route::get('/devices/me', [DeviceController::class, 'show']);

    Route::prefix('sync')->group(function () {
        Route::post('/announce', [SyncAnnounceController::class, 'store']);
        Route::get('/changes', [SyncPollController::class, 'index']);
        Route::post('/ack', [SyncAckController::class, 'store']);
    });
});
