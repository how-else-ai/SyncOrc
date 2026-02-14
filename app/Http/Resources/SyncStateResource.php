<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncStateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state_version' => $this->state_version,
            'vector_clock' => $this->vector_clock,
            'is_acknowledged' => $this->is_acknowledged,
            'ack_token_hash' => $this->when(
                $request->user()?->can('view_ack_token', $this->resource),
                $this->ack_token_hash
            ),
            'group' => new SyncGroupResource($this->whenLoaded('group')),
            'device' => new DeviceResource($this->whenLoaded('device')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'sync_state',
            ],
        ];
    }
}
