<?php

namespace App\Services;

use App\Models\CachedPayload;
use App\Models\Device;
use Ramsey\Uuid\Uuid;

class CacheService
{
    /**
     * Maximum payload size in bytes (10MB).
     */
    protected const MAX_PAYLOAD_SIZE = 10485760;

    /**
     * Default TTL in seconds (24 hours).
     */
    protected const DEFAULT_TTL = 86400;

    /**
     * Maximum TTL in seconds (7 days).
     */
    protected const MAX_TTL = 604800;

    /**
     * Store an encrypted payload in the cache.
     *
     * @param  string  $fromDeviceId  The source device UUID
     * @param  string  $toDeviceId  The target device UUID
     * @param  string  $groupId  The group UUID
     * @param  string  $encryptedPayload  Base64-encoded encrypted payload
     * @param  string  $stateVersion  The state version
     * @param  int|null  $ttl  Time to live in seconds (default 24 hours)
     * @return array{cache_id: string, expires_at: string, size_bytes: int}
     *
     * @throws \Exception
     */
    public function storePayload(
        string $fromDeviceId,
        string $toDeviceId,
        string $groupId,
        string $encryptedPayload,
        string $stateVersion,
        ?int $ttl = null
    ): array {
        $ttl ??= self::DEFAULT_TTL;

        // Validate TTL
        if ($ttl > self::MAX_TTL) {
            throw new \Exception('TTL exceeds maximum allowed value', 400);
        }

        // Decode payload to check size
        $payloadData = base64_decode($encryptedPayload, true);

        if ($payloadData === false) {
            throw new \Exception('Invalid base64 payload', 400);
        }

        $sizeBytes = strlen($payloadData);

        // Validate payload size
        if ($sizeBytes > self::MAX_PAYLOAD_SIZE) {
            throw new \Exception('Payload size exceeds maximum allowed value', 400);
        }

        // Find devices
        $fromDevice = Device::where('device_id', $fromDeviceId)->first();
        $toDevice = Device::where('device_id', $toDeviceId)->first();

        if (! $fromDevice || ! $toDevice) {
            throw new \Exception('Device not found', 404);
        }

        // Create cache entry
        $cachedPayload = CachedPayload::create([
            'cache_id' => Uuid::uuid4()->toString(),
            'from_device_id' => $fromDevice->id,
            'to_device_id' => $toDevice->id,
            'group_id' => $groupId,
            'encrypted_data' => $payloadData, // Store as binary
            'state_version' => $stateVersion,
            'size_bytes' => $sizeBytes,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return [
            'cache_id' => $cachedPayload->cache_id,
            'expires_at' => $cachedPayload->expires_at->toIso8601String(),
            'size_bytes' => $cachedPayload->size_bytes,
        ];
    }

    /**
     * Retrieve cached payloads for a device.
     *
     * @param  string  $toDeviceId  The target device UUID
     * @param  string|null  $groupId  Optional group filter
     * @param  int  $limit  Maximum number of payloads to return
     * @return \Illuminate\Database\Eloquent\Collection<int, array{cache_id: string, from_device_id: string, group_id: string, encrypted_payload: string, state_version: string, timestamp: string, size_bytes: int}>
     */
    public function retrievePayloads(string $toDeviceId, ?string $groupId = null, int $limit = 100)
    {
        $device = Device::where('device_id', $toDeviceId)->first();

        if (! $device) {
            return collect();
        }

        $query = $device->cachedPayloadsTo()
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc');

        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        $payloads = $query->limit($limit)->get();

        return $payloads->map(function ($payload) {
            return [
                'cache_id' => $payload->cache_id,
                'from_device_id' => $payload->fromDevice->device_id,
                'group_id' => $payload->group_id,
                'encrypted_payload' => base64_encode($payload->encrypted_data),
                'state_version' => $payload->state_version,
                'timestamp' => $payload->created_at->toIso8601String(),
                'size_bytes' => $payload->size_bytes,
            ];
        });
    }

    /**
     * Retrieve a specific cached payload.
     *
     * @param  string  $cacheId  The cache UUID
     * @return array{cache_id: string, from_device_id: string, group_id: string, encrypted_payload: string, state_version: string, timestamp: string, size_bytes: int}|null
     */
    public function retrievePayload(string $cacheId): ?array
    {
        $payload = CachedPayload::where('cache_id', $cacheId)
            ->where('expires_at', '>', now())
            ->first();

        if (! $payload) {
            return null;
        }

        return [
            'cache_id' => $payload->cache_id,
            'from_device_id' => $payload->fromDevice->device_id,
            'group_id' => $payload->group_id,
            'encrypted_payload' => base64_encode($payload->encrypted_data),
            'state_version' => $payload->state_version,
            'timestamp' => $payload->created_at->toIso8601String(),
            'size_bytes' => $payload->size_bytes,
        ];
    }

    /**
     * Delete a cached payload.
     *
     * @param  string  $cacheId  The cache UUID
     * @return bool True if deleted, false if not found
     */
    public function deletePayload(string $cacheId): bool
    {
        $deleted = CachedPayload::where('cache_id', $cacheId)->delete();

        return $deleted > 0;
    }

    /**
     * Delete all cached payloads for a device.
     *
     * @param  string  $toDeviceId  The target device UUID
     * @return int Number of deleted payloads
     */
    public function clearDeviceCache(string $toDeviceId): int
    {
        $device = Device::where('device_id', $toDeviceId)->first();

        if (! $device) {
            return 0;
        }

        return $device->cachedPayloadsTo()->delete();
    }

    /**
     * Delete all cached payloads for a group.
     *
     * @param  string  $groupId  The group UUID
     * @return int Number of deleted payloads
     */
    public function clearGroupCache(string $groupId): int
    {
        return CachedPayload::where('group_id', $groupId)->delete();
    }

    /**
     * Clean up expired cached payloads.
     *
     * @return int Number of deleted payloads
     */
    public function cleanupExpired(): int
    {
        return CachedPayload::where('expires_at', '<=', now())->delete();
    }

    /**
     * Get total cache size for a device.
     *
     * @param  string  $toDeviceId  The target device UUID
     * @return int Total size in bytes
     */
    public function getDeviceCacheSize(string $toDeviceId): int
    {
        $device = Device::where('device_id', $toDeviceId)->first();

        if (! $device) {
            return 0;
        }

        return (int) $device->cachedPayloadsTo()
            ->where('expires_at', '>', now())
            ->sum('size_bytes');
    }

    /**
     * Get total cache size for a group.
     *
     * @param  string  $groupId  The group UUID
     * @return int Total size in bytes
     */
    public function getGroupCacheSize(string $groupId): int
    {
        return (int) CachedPayload::where('group_id', $groupId)
            ->where('expires_at', '>', now())
            ->sum('size_bytes');
    }

    /**
     * Check if a device has exceeded a cache quota.
     *
     * @param  string  $toDeviceId  The target device UUID
     * @param  int  $quotaBytes  The quota in bytes
     * @return bool True if quota exceeded
     */
    public function isDeviceQuotaExceeded(string $toDeviceId, int $quotaBytes): bool
    {
        $currentSize = $this->getDeviceCacheSize($toDeviceId);

        return $currentSize >= $quotaBytes;
    }

    /**
     * Check if a group has exceeded a cache quota.
     *
     * @param  string  $groupId  The group UUID
     * @param  int  $quotaBytes  The quota in bytes
     * @return bool True if quota exceeded
     */
    public function isGroupQuotaExceeded(string $groupId, int $quotaBytes): bool
    {
        $currentSize = $this->getGroupCacheSize($groupId);

        return $currentSize >= $quotaBytes;
    }

    /**
     * Get cache statistics for a device.
     *
     * @param  string  $toDeviceId  The target device UUID
     * @return array{total_count: int, total_size: int, oldest_expires_at: string|null}
     */
    public function getDeviceCacheStats(string $toDeviceId): array
    {
        $device = Device::where('device_id', $toDeviceId)->first();

        if (! $device) {
            return [
                'total_count' => 0,
                'total_size' => 0,
                'oldest_expires_at' => null,
            ];
        }

        $payloads = $device->cachedPayloadsTo()
            ->where('expires_at', '>', now())
            ->get();

        return [
            'total_count' => $payloads->count(),
            'total_size' => (int) $payloads->sum('size_bytes'),
            'oldest_expires_at' => $payloads->min('expires_at')?->toIso8601String(),
        ];
    }
}
