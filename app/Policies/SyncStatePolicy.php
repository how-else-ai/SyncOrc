<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\SyncState;

class SyncStatePolicy
{
    /**
     * Determine whether the device can view the ack_token_hash field.
     *
     * Only the device that created the sync state or a device in the same group
     * should be able to view the acknowledgment token hash.
     */
    public function viewAckToken(Device $device, SyncState $syncState): bool
    {
        // The device that created the sync state can view the ack_token
        if ($syncState->device_id === $device->id) {
            return true;
        }

        // Check if the device is in the same group as the sync state
        $syncStateGroupId = $syncState->group_id;

        return $device->groups()
            ->where('sync_groups.id', $syncStateGroupId)
            ->exists();
    }

    /**
     * Determine whether the device can view the sync state.
     */
    public function view(Device $device, SyncState $syncState): bool
    {
        // Device can view if it created the sync state
        if ($syncState->device_id === $device->id) {
            return true;
        }

        // Device can view if it's in the same group
        return $device->groups()
            ->where('sync_groups.id', $syncState->group_id)
            ->exists();
    }

    /**
     * Determine whether the device can create sync states.
     */
    public function create(Device $device): bool
    {
        // Any authenticated device can create sync states
        return true;
    }

    /**
     * Determine whether the device can update the sync state.
     */
    public function update(Device $device, SyncState $syncState): bool
    {
        // Only the device that created the sync state can update it
        return $syncState->device_id === $device->id;
    }

    /**
     * Determine whether the device can delete the sync state.
     */
    public function delete(Device $device, SyncState $syncState): bool
    {
        // Only the device that created the sync state can delete it
        return $syncState->device_id === $device->id;
    }
}
