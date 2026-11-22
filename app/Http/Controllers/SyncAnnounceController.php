<?php

namespace App\Http\Controllers;

use App\Models\ChangeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncAnnounceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\Device $device */
        $device = $request->attributes->get('sync_device');

        $validated = $request->validate([
            'changes' => 'required|array|min:1|max:500',
            'changes.*.entity_type' => 'required|string|max:100',
            'changes.*.entity_id' => 'required|string|max:255',
            'changes.*.change_type' => 'required|in:create,update,delete',
            'changes.*.content_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'changes.*.version' => 'required|integer|min:1',
            'changes.*.vector_clock' => 'nullable|array',
            'changes.*.payload_location' => 'nullable|url|max:500',
        ]);

        $accepted = collect($validated['changes'])->map(fn (array $change): ChangeEntry => ChangeEntry::create([
            'device_id' => $device->id,
            'entity_type' => $change['entity_type'],
            'entity_id' => $change['entity_id'],
            'change_type' => $change['change_type'],
            'content_hash' => $change['content_hash'],
            'version' => $change['version'],
            'vector_clock' => $change['vector_clock'] ?? null,
            'payload_location' => $change['payload_location'] ?? null,
        ]));

        return response()->json([
            'accepted' => $accepted->count(),
            'changes' => $accepted->values()->all(),
        ], 201);
    }
}
