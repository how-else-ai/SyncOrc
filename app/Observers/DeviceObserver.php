<?php

namespace App\Observers;

use App\Models\Device;

class DeviceObserver
{
    /**
     * Handle the Device "deleting" event.
     * Clean up related records before device deletion.
     */
    public function deleting(Device $device): void
    {
        // Clean up pairing requests
        $device->pairingRequests()->delete();

        // Clean up signaling offers
        $device->signalingOffersFrom()->delete();
        $device->signalingOffersTo()->delete();

        // Clean up cached payloads
        $device->cachedPayloadsFrom()->delete();
        $device->cachedPayloadsTo()->delete();

        // Remove from all groups
        $device->groupMembers()->delete();

        // Clean up sync states
        $device->syncStates()->delete();
    }

    /**
     * Handle the Device "updated" event.
     * Update the last_seen_at timestamp when the device is touched.
     */
    public function updated(Device $device): void
    {
        // If the push_token was updated, we might want to validate it
        if ($device->wasChanged('push_token') && $device->push_token) {
            // Future: Validate push token format or register with push service
        }
    }
}
