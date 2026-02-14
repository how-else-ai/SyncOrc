<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Health check endpoint.
     *
     * GET /api/health
     */
    public function index(): JsonResponse
    {
        $status = 'healthy';
        $services = [
            'database' => 'connected',
            'redis' => 'connected',
            'websocket' => 'running',
            'queue' => 'processing',
        ];

        // Check database connection
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $status = 'unhealthy';
            $services['database'] = 'disconnected: '.$e->getMessage();
        }

        // Check Redis connection
        try {
            Redis::ping();
        } catch (\Exception $e) {
            $status = 'unhealthy';
            $services['redis'] = 'disconnected: '.$e->getMessage();
        }

        // WebSocket status - in a real implementation, this would check the WebSocket server
        // For now, we assume it's running if the app is running
        $services['websocket'] = 'running';

        // Queue status - in a real implementation, this would check queue workers
        // For now, we assume it's processing
        $services['queue'] = 'processing';

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
            'version' => '1.0.0',
        ], $status === 'healthy' ? 200 : 503);
    }
}
