<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SyncGroup;
use App\Models\SyncState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncCoordinatorService
{
    protected VectorClockService $vectorClockService;

    protected NotificationService $notificationService;

    protected DeviceService $deviceService;

    public function __construct(
        VectorClockService $vectorClockService,
        NotificationService $notificationService,
        DeviceService $deviceService
    ) {
        $this->vectorClockService = $vectorClockService;
        $this->notificationService = $notificationService;
        $this->deviceService = $deviceService;
    }

    /**
     * Notify state change and handle sync coordination.
     *
     * @param  string  $sourceDeviceId  The device UUID reporting the state change
     * @param  string  $groupId  The group UUID
     * @param  string  $stateVersion  The new state version
     * @param  string  $ackTokenHash  SHA-256 hash of the acknowledgment token
     * @param  array<string, int>|null  $vectorClock  The vector clock for this state
     * @return array{loop_detected: bool, notified_devices: array<string, array>, online_count: int, push_count: int}
     *
     * @throws \Exception
     */
    public function notifyStateChange(
        string $sourceDeviceId,
        string $groupId,
        string $stateVersion,
        string $ackTokenHash,
        ?array $vectorClock = null
    ): array {
        // Validate devices and group
        $sourceDevice = Device::where('device_id', $sourceDeviceId)->firstOrFail();
        $group = SyncGroup::where('group_id', $groupId)->firstOrFail();

        // Verify source device is a member of the group
        if (! $group->hasDevice($sourceDevice->id)) {
            throw new \Exception('Device is not a member of this group', 403);
        }

        // Get or initialize vector clock
        if (! $vectorClock) {
            $vectorClock = $this->vectorClockService->initializeClock($sourceDevice->id);
        } else {
            // Increment the source device's counter in the vector clock
            $vectorClock = $this->vectorClockService->incrementClock($vectorClock, $sourceDevice->id);
        }

        // Check for loop detection
        if ($this->vectorClockService->detectLoop($group->id, $sourceDevice->id, $vectorClock)) {
            return [
                'loop_detected' => true,
                'notified_devices' => [],
                'online_count' => 0,
                'push_count' => 0,
            ];
        }

        // Create sync state record
        SyncState::create([
            'group_id' => $group->id,
            'device_id' => $sourceDevice->id,
            'state_version' => $stateVersion,
            'ack_token_hash' => $ackTokenHash,
            'vector_clock' => $vectorClock,
            'is_acknowledged' => false,
        ]);

        // Determine target devices based on topology
        $targetDevices = $this->selectTargetDevices($group, $sourceDevice->id);

        // Notify target devices
        $notificationResult = $this->notificationService->notifySyncRequired(
            $sourceDevice->device_id,
            $groupId,
            $stateVersion,
            $targetDevices
        );

        return [
            'loop_detected' => false,
            'notified_devices' => $notificationResult['notified_devices'],
            'online_count' => $notificationResult['online_count'],
            'push_count' => $notificationResult['push_count'],
        ];
    }

    /**
     * Acknowledge a sync state.
     *
     * @param  string  $deviceId  The device UUID acknowledging
     * @param  string  $groupId  The group UUID
     * @param  string  $stateVersion  The state version being acknowledged
     * @param  string  $ackTokenHash  The acknowledgment token hash
     * @return bool True if acknowledged successfully
     *
     * @throws \Exception
     */
    public function acknowledgeSync(
        string $deviceId,
        string $groupId,
        string $stateVersion,
        string $ackTokenHash
    ): bool {
        return DB::transaction(function () use ($deviceId, $groupId, $stateVersion, $ackTokenHash) {
            $device = Device::where('device_id', $deviceId)->firstOrFail();
            $group = SyncGroup::where('group_id', $groupId)->firstOrFail();

            // Find the sync state to acknowledge
            $syncState = SyncState::where('group_id', $group->id)
                ->where('state_version', $stateVersion)
                ->where('ack_token_hash', $ackTokenHash)
                ->first();

            if (! $syncState) {
                throw new \Exception('Sync state not found or invalid token', 404);
            }

            // Mark as acknowledged
            $syncState->is_acknowledged = true;
            $syncState->save();

            // Notify the group that sync has been acknowledged
            $this->notificationService->notifySyncAcknowledged(
                $groupId,
                $device->device_id,
                $stateVersion
            );

            return true;
        });
    }

    /**
     * Get sync status for a device in a group.
     *
     * @param  string  $deviceId  The device UUID
     * @param  string  $groupId  The group UUID
     * @return array{group_id: string, device_id: string, last_sync_version: string|null, is_up_to_date: bool, pending_syncs: array<int, array>, vector_clock: array<string, int>}
     *
     * @throws \Exception
     */
    public function getSyncStatus(string $deviceId, string $groupId): array
    {
        $device = Device::where('device_id', $deviceId)->firstOrFail();
        $group = SyncGroup::where('group_id', $groupId)->firstOrFail();

        // Get the latest sync state for this device in this group
        $lastSyncState = SyncState::where('group_id', $group->id)
            ->where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // Get pending syncs (unacknowledged states from other devices in the group)
        $otherDeviceStates = SyncState::where('group_id', $group->id)
            ->where('device_id', '!=', $device->id)
            ->where('is_acknowledged', false)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $pendingSyncs = [];

        foreach ($otherDeviceStates as $state) {
            // Determine if this sync is actually pending for this device
            // by checking if the state's vector clock is newer
            $deviceLastClock = $lastSyncState->vector_clock ?? [];
            $stateClock = $state->vector_clock ?? [];

            if ($this->vectorClockService->happenedBefore($deviceLastClock, $stateClock)) {
                $pendingSyncs[] = [
                    'from_device' => $state->device->device_id,
                    'state_version' => $state->state_version,
                    'timestamp' => $state->created_at->toIso8601String(),
                ];
            }
        }

        // Get merged vector clock for the group
        $latestClocks = $this->vectorClockService->getLatestClocksForGroup($group->id);
        $mergedClock = [];

        foreach ($latestClocks as $deviceId => $clock) {
            $mergedClock = $this->vectorClockService->mergeClocks($mergedClock, $clock);
        }

        return [
            'group_id' => $groupId,
            'device_id' => $device->device_id,
            'last_sync_version' => $lastSyncState?->state_version,
            'is_up_to_date' => empty($pendingSyncs),
            'pending_syncs' => $pendingSyncs,
            'vector_clock' => $mergedClock,
        ];
    }

    /**
     * Select target devices based on group topology.
     *
     * @param  SyncGroup  $group  The sync group
     * @param  string  $sourceDeviceId  The internal device ID of the source
     * @return array<string> Array of device UUIDs
     */
    protected function selectTargetDevices(SyncGroup $group, string $sourceDeviceId): array
    {
        $targetDevices = [];

        switch ($group->group_type) {
            case SyncGroup::TYPE_PAIR:
                // Pair: notify the other device
                $otherMember = $group->groupMembers()
                    ->where('device_id', '!=', $sourceDeviceId)
                    ->first();

                if ($otherMember) {
                    $targetDevices[] = $otherMember->device->device_id;
                }
                break;

            case SyncGroup::TYPE_CHAIN:
                // Chain: notify adjacent devices (previous and next)
                $previousDevice = $group->previousDeviceInChain($sourceDeviceId);
                $nextDevice = $group->nextDeviceInChain($sourceDeviceId);

                if ($previousDevice) {
                    $targetDevices[] = $previousDevice->device_id;
                }

                if ($nextDevice) {
                    $targetDevices[] = $nextDevice->device_id;
                }
                break;

            case SyncGroup::TYPE_GROUP:
                // Group: notify all other devices
                $allMembers = $group->groupMembers()
                    ->where('device_id', '!=', $sourceDeviceId)
                    ->get();

                foreach ($allMembers as $member) {
                    $targetDevices[] = $member->device->device_id;
                }
                break;
        }

        return $targetDevices;
    }

    /**
     * Get all groups a device belongs to.
     *
     * @param  string  $deviceId  The device UUID
     * @return \Illuminate\Database\Eloquent\Collection<int, array>
     */
    public function getDeviceGroups(string $deviceId): Collection
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return collect();
        }

        return $device->groups()->get()->map(function ($group) {
            return [
                'group_id' => $group->group_id,
                'group_type' => $group->group_type,
                'member_count' => $group->memberCount(),
                'position' => $group->pivot->position,
                'joined_at' => $group->pivot->joined_at->toIso8601String(),
            ];
        });
    }

    /**
     * Get the latest sync state for a device in a group.
     *
     * @param  string  $deviceId  The device UUID
     * @param  string  $groupId  The group UUID
     */
    public function getLatestSyncState(string $deviceId, string $groupId): ?SyncState
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return null;
        }

        return SyncState::where('group_id', $groupId)
            ->where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get unacknowledged sync states for a device.
     *
     * @param  string  $deviceId  The device UUID
     * @param  string|null  $groupId  Optional group filter
     * @return \Illuminate\Database\Eloquent\Collection<int, SyncState>
     */
    public function getUnacknowledgedSyncs(string $deviceId, ?string $groupId = null): Collection
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return collect();
        }

        $query = SyncState::where('device_id', $device->id)
            ->where('is_acknowledged', false)
            ->orderBy('created_at', 'desc');

        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        return $query->get();
    }
}
