<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTHENTICATION_FAILED',
                    'message' => 'Authentication token is required',
                ],
            ], 401);
        }

        $device = \App\Services\DeviceService::getDeviceByToken($token);

        if (! $device) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTHENTICATION_FAILED',
                    'message' => 'Invalid or expired authentication token',
                ],
            ], 401);
        }

        // Attach the authenticated device to the request
        $request->merge(['authenticated_device' => $device]);

        return $next($request);
    }

    /**
     * Extract the Bearer token from the request.
     */
    protected function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! $header) {
            return null;
        }

        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
