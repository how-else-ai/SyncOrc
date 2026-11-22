<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceRegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'public_key' => 'nullable|string|max:2048',
            'capabilities' => 'nullable|array',
        ]);

        $device = Device::create([
            'name' => $validated['name'] ?? null,
            'public_key' => $validated['public_key'] ?? null,
            'capabilities' => $validated['capabilities'] ?? null,
        ]);

        return response()->json([
            'device_id' => $device->id,
            'sync_token' => $device->api_token,
        ], 201);
    }
}
