<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Device;

class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): JsonResponse|Response
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            return response()->json(['error' => 'sync token required'], 401);
        }

        $device = Device::where('api_token', $token)->first();

        if (!$device) {
            return response()->json(['error' => 'invalid sync token'], 401);
        }

        $request->attributes->set('sync_device', $device);

        return $next($request);
    }
}
