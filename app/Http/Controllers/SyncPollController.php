<?php

namespace App\Http\Controllers;

use App\Models\ChangeEntry;
use App\Models\SyncCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncPollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Device $device */
        $device = $request->attributes->get('sync_device');

        $limit = min((int) $request->query('limit', 50), 500);

        $cursor = SyncCursor::firstOrNew(['device_id' => $device->id]);
        $since = $cursor->last_synced_at;

        $changes = ChangeEntry::where('device_id', '!=', $device->id)
            ->when($since, fn ($query) => $query->where('created_at', '>', $since))
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'cursor' => $since?->toIso8601String(),
            'count' => $changes->count(),
            'has_more' => $changes->count() === $limit,
            'changes' => $changes->map(fn (ChangeEntry $change) => [
                'id' => $change->id,
                'device_id' => $change->device_id,
                'entity_type' => $change->entity_type,
                'entity_id' => $change->entity_id,
                'change_type' => $change->change_type,
                'content_hash' => $change->content_hash,
                'version' => $change->version,
                'vector_clock' => $change->vector_clock,
                'payload_location' => $change->payload_location,
                'created_at' => $change->created_at,
            ])->values()->all(),
        ]);
    }
}
