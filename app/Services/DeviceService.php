<?php

namespace App\Services;

use App\Models\Device;
use Ramsey\Uuid\Uuid;

class DeviceService
{
    /**
     * Register a new device.
     *
     * @param  string  $publicKey  Base64-encoded public key
     * @param  string  $platform  Platform type (ios, android, web)
     * @param  string|null  $pushToken  Optional push notification token
     * @return array{device: Device, api_token: string}
     */
    public function registerDevice(string $publicKey, string $platform, ?string $pushToken = null): array
    {
        $device = Device::create([
            'device_id' => Uuid::uuid4()->toString(),
            'public_key' => $publicKey,
            'platform' => $platform,
            'push_token' => $pushToken,
            'api_token' => null, // Will be auto-generated
            'token_expires_at' => now()->addDays(7),
        ]);

        return [
            'device' => $device,
            'api_token' => $device->api_token,
        ];
    }

    /**
     * Refresh the API token for a device.
     *
     * @param  string  $deviceId  The device UUID
     * @return array{api_token: string, expires_at: string}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function refreshToken(string $deviceId): array
    {
        $device = Device::where('device_id', $deviceId)->firstOrFail();

        $device->refreshToken();
        $device->save();

        return [
            'api_token' => $device->api_token,
            'expires_at' => $device->token_expires_at->toIso8601String(),
        ];
    }

    /**
     * Update the push token for a device.
     *
     * @param  string  $deviceId  The device UUID
     * @param  string  $pushToken  New push notification token
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function updatePushToken(string $deviceId, string $pushToken): Device
    {
        $device = Device::where('device_id', $deviceId)->firstOrFail();

        $device->push_token = $pushToken;
        $device->save();

        return $device;
    }

    /**
     * Get a device by its device_id.
     *
     * @param  string  $deviceId  The device UUID
     */
    public function getDevice(string $deviceId): ?Device
    {
        return Device::where('device_id', $deviceId)->first();
    }

    /**
     * Get a device by its API token (hashed comparison).
     *
     * @param  string  $apiToken  The raw API token
     */
    public function getDeviceByToken(string $apiToken): ?Device
    {
        return Device::where('api_token', $apiToken)->active()->first();
    }

    /**
     * Mark a device as currently active (update last_seen_at).
     *
     * @param  string  $deviceId  The device UUID
     */
    public function markAsActive(string $deviceId): ?Device
    {
        $device = $this->getDevice($deviceId);

        if ($device) {
            $device->markActive();
            $device->save();
        }

        return $device;
    }

    /**
     * Check if a device is online (based on Redis or last_seen_at).
     *
     * @param  string  $deviceId  The device UUID
     */
    public function isOnline(string $deviceId): bool
    {
        // Check Redis first (faster)
        $redis = app('redis');
        $onlineKey = "online:device:{$deviceId}";

        if ($redis->exists($onlineKey)) {
            return true;
        }

        // Fallback to last_seen_at
        $device = $this->getDevice($deviceId);
        if (! $device || ! $device->last_seen_at) {
            return false;
        }

        // Consider online if seen within last 5 minutes
        return $device->last_seen_at->gt(now()->subMinutes(5));
    }

    /**
     * Set a device as online in Redis.
     *
     * @param  string  $deviceId  The device UUID
     */
    public function setOnline(string $deviceId): void
    {
        $redis = app('redis');
        $redis->setex("online:device:{$deviceId}", 300, time()); // 5 minutes TTL
    }

    /**
     * Set a device as offline in Redis.
     *
     * @param  string  $deviceId  The device UUID
     */
    public function setOffline(string $deviceId): void
    {
        $redis = app('redis');
        $redis->del("online:device:{$deviceId}");
    }

    /**
     * Validate a device's API token.
     *
     * @param  string  $deviceId  The device UUID
     * @param  string  $apiToken  The raw API token
     */
    public function validateToken(string $deviceId, string $apiToken): bool
    {
        $device = $this->getDevice($deviceId);

        if (! $device) {
            return false;
        }

        return hash_equals($device->api_token, $apiToken) && $device->isTokenValid();
    }

    /**
     * Get all devices a device is paired with (in all groups).
     *
     * @param  string  $deviceId  The device UUID
     * @return \Illuminate\Database\Eloquent\Collection<int, Device>
     */
    public function getPairedDevices(string $deviceId)
    {
        $device = $this->getDevice($deviceId);

        if (! $device) {
            return collect();
        }

        // Get all groups this device belongs to
        $groupIds = $device->groups()->pluck('sync_groups.id');

        // Get all devices in those groups, excluding the current device
        return Device::whereHas('groups', function ($query) use ($groupIds) {
            $query->whereIn('sync_groups.id', $groupIds);
        })->where('device_id', '!=', $deviceId)->get();
    }
}
