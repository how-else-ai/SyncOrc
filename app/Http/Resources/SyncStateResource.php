<?php

namespace App\Http\Resources;

use App\Models\Device;
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
        // Get the authenticated device from the request (set by ApiAuthMiddleware)
        $authenticatedDevice = $request->input('authenticated_device');

        // Check if the authenticated device can view the ack_token_hash
        $canViewAckToken = $this->canViewAckToken($authenticatedDevice);

        return [
            'id' => $this->id,
            'state_version' => $this->state_version,
            'vector_clock' => $this->vector_clock,
            'is_acknowledged' => $this->is_acknowledged,
            'ack_token_hash' => $this->when(
                $canViewAckToken,
                $this->ack_token_hash
            ),
            'group' => new SyncGroupResource($this->whenLoaded('group')),
            'device' => new DeviceResource($this->whenLoaded('device')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Determine whether the device can view the ack_token_hash field.
     */
    protected function canViewAckToken(?Device $device): bool
    {
        if (! $device instanceof Device) {
            return false;
        }

        // The device that created the sync state can view the ack_token
        if ($this->device_id === $device->id) {
            return true;
        }

        // Check if the device is in the same group as the sync state
        return $device->groups()
            ->where('sync_groups.id', $this->group_id)
            ->exists();
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
