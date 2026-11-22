<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('sync_device');

        return response()->json([
            'device_id' => $device->id,
            'name' => $device->name,
            'public_key' => $device->public_key,
            'capabilities' => $device->capabilities,
            'last_sync_at' => $device->last_sync_at,
            'created_at' => $device->created_at,
        ]);
    }
}
