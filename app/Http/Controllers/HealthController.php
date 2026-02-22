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
            'websocket' => 'unknown',
            'queue' => 'unknown',
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

        // Check WebSocket (Reverb) server status
        $services['websocket'] = $this->checkWebSocketStatus();
        if ($services['websocket'] !== 'running') {
            $status = 'degraded';
        }

        // Check Queue worker status
        $services['queue'] = $this->checkQueueStatus();
        if ($services['queue'] !== 'processing') {
            $status = 'degraded';
        }

        $httpStatus = match ($status) {
            'healthy' => 200,
            'degraded' => 200,
            default => 503,
        };

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
            'version' => '1.0.0',
        ], $httpStatus);
    }

    /**
     * Check WebSocket (Reverb) server status via Redis.
     */
    protected function checkWebSocketStatus(): string
    {
        try {
            // Check if Reverb presence key exists in Redis
            // Reverb stores connection info in Redis when using Redis driver
            $reverbConnections = Redis::keys('reverb:*');

            if (! empty($reverbConnections)) {
                return 'running';
            }

            // Alternative: Check if Reverb process is registered
            $reverbHeartbeat = Redis::get('reverb:heartbeat');
            if ($reverbHeartbeat) {
                $lastHeartbeat = (int) $reverbHeartbeat;
                if (time() - $lastHeartbeat < 60) {
                    return 'running';
                }

                return 'stale';
            }

            // Check configuration to see if WebSocket is enabled
            $broadcastDriver = config('broadcasting.default');
            if ($broadcastDriver === 'null' || $broadcastDriver === 'log') {
                return 'disabled';
            }

            // No evidence of WebSocket running, but not confirmed down
            return 'unknown';
        } catch (\Exception $e) {
            return 'error: '.$e->getMessage();
        }
    }

    /**
     * Check Queue worker status via Redis.
     */
    protected function checkQueueStatus(): string
    {
        try {
            $queueConnection = config('queue.default');

            if ($queueConnection === 'sync') {
                return 'sync_mode';
            }

            if ($queueConnection === 'database') {
                // For database driver, check for recent job processing
                $recentJobs = DB::table('jobs')
                    ->where('available_at', '>', now()->subMinutes(5))
                    ->exists();

                $failedJobs = DB::table('failed_jobs')
                    ->where('failed_at', '>', now()->subMinutes(5))
                    ->count();

                if ($recentJobs || $failedJobs > 0) {
                    return 'processing';
                }

                return 'idle';
            }

            if ($queueConnection === 'redis') {
                // For Redis driver, check queue lengths and worker heartbeats
                $queues = ['default', 'push-notifications'];
                $totalJobs = 0;

                foreach ($queues as $queue) {
                    $length = Redis::llen('queues:'.$queue);
                    $totalJobs += $length;
                }

                // Check for worker heartbeats
                $workers = Redis::smembers('queues:workers');
                $activeWorkers = 0;

                foreach ($workers as $worker) {
                    $heartbeat = Redis::get('queues:worker:'.$worker);
                    if ($heartbeat && time() - (int) $heartbeat < 120) {
                        $activeWorkers++;
                    }
                }

                if ($activeWorkers > 0) {
                    return 'processing';
                }

                if ($totalJobs > 0) {
                    return 'backlogged';
                }

                return 'idle';
            }

            // For other drivers (SQS, Beanstalkd, etc.), assume processing if connection works
            return 'processing';
        } catch (\Exception $e) {
            return 'error: '.$e->getMessage();
        }
    }
}
