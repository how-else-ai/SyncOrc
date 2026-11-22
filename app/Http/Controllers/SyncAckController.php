<?php

namespace App\Http\Controllers;

use App\Models\ChangeEntry;
use App\Models\SyncCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncAckController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\Device $device */
        $device = $request->attributes->get('sync_device');

        $validated = $request->validate([
            'last_change_id' => 'required|uuid|exists:change_entries,id',
        ]);

        $entry = ChangeEntry::find($validated['last_change_id']);

        SyncCursor::updateOrCreate(
            ['device_id' => $device->id],
            [
                'last_synced_at' => $entry->created_at,
                'updated_at' => now(),
            ]
        );

        $device->update(['last_sync_at' => now()]);

        return response()->json([
            'acknowledged' => true,
            'cursor' => $entry->created_at->toIso8601String(),
        ]);
    }
}
