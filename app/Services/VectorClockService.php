<?php

namespace App\Services;

use App\Models\SyncState;

class VectorClockService
{
    /**
     * Initialize a new vector clock for a device.
     *
     * @param  string  $deviceId  The device UUID
     * @return array<string, int>
     */
    public function initializeClock(string $deviceId): array
    {
        return [
            $deviceId => 1,
        ];
    }

    /**
     * Increment a vector clock for a specific device.
     *
     * @param  array<string, int>  $clock  The current vector clock
     * @param  string  $deviceId  The device UUID to increment
     * @return array<string, int>
     */
    public function incrementClock(array $clock, string $deviceId): array
    {
        $clock[$deviceId] = ($clock[$deviceId] ?? 0) + 1;

        return $clock;
    }

    /**
     * Merge two vector clocks, taking the maximum value for each device.
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     * @return array<string, int>
     */
    public function mergeClocks(array $clock1, array $clock2): array
    {
        $merged = [];

        $allDevices = array_unique(array_merge(array_keys($clock1), array_keys($clock2)));

        foreach ($allDevices as $deviceId) {
            $merged[$deviceId] = max(
                $clock1[$deviceId] ?? 0,
                $clock2[$deviceId] ?? 0
            );
        }

        return $merged;
    }

    /**
     * Check if clock1 happened before clock2 (causality).
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     * @return bool True if clock1 happened before clock2
     */
    public function happenedBefore(array $clock1, array $clock2): bool
    {
        $allDevices = array_unique(array_merge(array_keys($clock1), array_keys($clock2)));

        $allLessOrEqual = true;
        $anyLess = false;

        foreach ($allDevices as $deviceId) {
            $val1 = $clock1[$deviceId] ?? 0;
            $val2 = $clock2[$deviceId] ?? 0;

            if ($val1 > $val2) {
                // clock1 has a higher value, cannot be before
                $allLessOrEqual = false;
                break;
            }

            if ($val1 < $val2) {
                // clock1 has at least one lower value
                $anyLess = true;
            }
        }

        return $allLessOrEqual && $anyLess;
    }

    /**
     * Check if two vector clocks are concurrent (neither happened before the other).
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     * @return bool True if clocks are concurrent
     */
    public function isConcurrent(array $clock1, array $clock2): bool
    {
        return ! $this->happenedBefore($clock1, $clock2) && ! $this->happenedBefore($clock2, $clock1);
    }

    /**
     * Check if two vector clocks are equal.
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     */
    public function areEqual(array $clock1, array $clock2): bool
    {
        $allDevices = array_unique(array_merge(array_keys($clock1), array_keys($clock2)));

        foreach ($allDevices as $deviceId) {
            $val1 = $clock1[$deviceId] ?? 0;
            $val2 = $clock2[$deviceId] ?? 0;

            if ($val1 !== $val2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect a potential sync loop based on vector clock comparison.
     *
     * A loop is suspected when the incoming vector clock is not strictly greater
     * than the last known vector clock for the device in that group.
     *
     * @param  string  $groupId  The group UUID
     * @param  string  $deviceId  The device UUID
     * @param  array<string, int>  $incomingClock  The incoming vector clock
     * @return bool True if a loop is suspected
     */
    public function detectLoop(string $groupId, string $deviceId, array $incomingClock): bool
    {
        // Get the last sync state for this device in this group
        $lastState = SyncState::where('group_id', $groupId)
            ->where('device_id', $deviceId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $lastState || ! $lastState->vector_clock) {
            // No previous state, cannot be a loop
            return false;
        }

        $lastClock = $lastState->vector_clock;

        // If incoming clock happened before or is concurrent with the last clock,
        // it might be a duplicate or looped update
        return $this->happenedBefore($incomingClock, $lastClock) || $this->isConcurrent($incomingClock, $lastClock);
    }

    /**
     * Get the latest vector clock for all devices in a group.
     *
     * @param  string  $groupId  The group UUID
     * @return array<string, array<string, int>> Device ID => Vector Clock
     */
    public function getLatestClocksForGroup(string $groupId): array
    {
        $states = SyncState::where('group_id', $groupId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('device_id');

        $clocks = [];

        foreach ($states as $state) {
            if ($state->vector_clock) {
                $clocks[$state->device_id] = $state->vector_clock;
            }
        }

        return $clocks;
    }

    /**
     * Compare a state version to determine if it's newer, older, or concurrent.
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     * @return string 'newer', 'older', 'equal', or 'concurrent'
     */
    public function compareClocks(array $clock1, array $clock2): string
    {
        if ($this->areEqual($clock1, $clock2)) {
            return 'equal';
        }

        if ($this->happenedBefore($clock1, $clock2)) {
            return 'older';
        }

        if ($this->happenedBefore($clock2, $clock1)) {
            return 'newer';
        }

        return 'concurrent';
    }

    /**
     * Calculate the "distance" between two vector clocks (sum of absolute differences).
     *
     * @param  array<string, int>  $clock1  First vector clock
     * @param  array<string, int>  $clock2  Second vector clock
     * @return int The distance
     */
    public function clockDistance(array $clock1, array $clock2): int
    {
        $allDevices = array_unique(array_merge(array_keys($clock1), array_keys($clock2)));
        $distance = 0;

        foreach ($allDevices as $deviceId) {
            $val1 = $clock1[$deviceId] ?? 0;
            $val2 = $clock2[$deviceId] ?? 0;
            $distance += abs($val1 - $val2);
        }

        return $distance;
    }
}
