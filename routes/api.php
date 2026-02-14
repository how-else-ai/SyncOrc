<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public endpoints
Route::get('/health', [App\Http\Controllers\HealthController::class, 'index']);

// Device registration (public)
Route::post('/v1/devices/register', [App\Http\Controllers\Api\DeviceController::class, 'register']);

// Authenticated endpoints
Route::middleware(['api.auth'])->group(function () {
    // Device management
    Route::post('/v1/devices/refresh-token', [App\Http\Controllers\Api\DeviceController::class, 'refreshToken']);
    Route::patch('/v1/devices/push-token', [App\Http\Controllers\Api\DeviceController::class, 'updatePushToken']);

    // Pairing
    Route::post('/v1/pairing/initiate', [App\Http\Controllers\Api\PairingController::class, 'initiate']);
    Route::post('/v1/pairing/accept', [App\Http\Controllers\Api\PairingController::class, 'accept']);

    // Sync
    Route::post('/v1/sync/state-changed', [App\Http\Controllers\Api\SyncController::class, 'stateChanged']);
    Route::post('/v1/sync/acknowledge', [App\Http\Controllers\Api\SyncController::class, 'acknowledge']);
    Route::get('/v1/sync/status', [App\Http\Controllers\Api\SyncController::class, 'status']);

    // Cache
    Route::post('/v1/cache/store', [App\Http\Controllers\Api\CacheController::class, 'store']);
    Route::get('/v1/cache/retrieve', [App\Http\Controllers\Api\CacheController::class, 'retrieve']);
    Route::delete('/v1/cache/{cache_id}', [App\Http\Controllers\Api\CacheController::class, 'delete']);

    // Signaling
    Route::post('/v1/signaling/offer', [App\Http\Controllers\Api\SignalingController::class, 'offer']);
    Route::get('/v1/signaling/offers', [App\Http\Controllers\Api\SignalingController::class, 'offers']);
    Route::post('/v1/signaling/answer', [App\Http\Controllers\Api\SignalingController::class, 'answer']);

    // Groups
    Route::get('/v1/groups/{group_id}/members', [App\Http\Controllers\Api\GroupController::class, 'members']);
    Route::post('/v1/groups/{group_id}/leave', [App\Http\Controllers\Api\GroupController::class, 'leave']);
});

// Catch-all for undefined routes
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'NOT_FOUND',
            'message' => 'Endpoint not found',
        ],
    ], 404);
});
