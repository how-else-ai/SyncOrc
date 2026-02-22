<?php

namespace Tests\Unit;

use App\Models\CachedPayload;
use App\Models\Device;
use App\Models\SyncGroup;
use App\Services\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_payload_persists_data(): void
    {
        $fromDevice = Device::factory()->create();
        $toDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();
        $payload = base64_encode('payload-data');

        $service = new CacheService;

        $result = $service->storePayload(
            $fromDevice->device_id,
            $toDevice->device_id,
            $group->group_id,
            $payload,
            'v1'
        );

        $this->assertSame(11, $result['size_bytes']);
        $this->assertDatabaseHas('cached_payloads', [
            'cache_id' => $result['cache_id'],
            'group_id' => $group->id,
            'state_version' => 'v1',
        ]);
    }

    public function test_store_payload_validates_ttl_and_payload(): void
    {
        $fromDevice = Device::factory()->create();
        $toDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        $service = new CacheService;

        $this->expectException(\InvalidArgumentException::class);
        $service->storePayload(
            $fromDevice->device_id,
            $toDevice->device_id,
            $group->group_id,
            base64_encode('payload'),
            'v1',
            CacheService::MAX_TTL + 1
        );
    }

    public function test_store_payload_rejects_invalid_base64(): void
    {
        $fromDevice = Device::factory()->create();
        $toDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        $service = new CacheService;

        $this->expectException(\InvalidArgumentException::class);
        $service->storePayload(
            $fromDevice->device_id,
            $toDevice->device_id,
            $group->group_id,
            'invalid_base64',
            'v1'
        );
    }

    public function test_store_payload_rejects_large_payload(): void
    {
        $fromDevice = Device::factory()->create();
        $toDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();
        $largePayload = base64_encode(str_repeat('a', CacheService::MAX_PAYLOAD_SIZE + 1));

        $service = new CacheService;

        $this->expectException(\InvalidArgumentException::class);
        $service->storePayload(
            $fromDevice->device_id,
            $toDevice->device_id,
            $group->group_id,
            $largePayload,
            'v1'
        );
    }

    public function test_retrieve_payloads_filters_by_group(): void
    {
        $device = Device::factory()->create();
        $otherDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();
        $otherGroup = SyncGroup::factory()->create();

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'from_device_id' => $otherDevice->id,
            'group_id' => $group->id,
            'encrypted_data' => 'data',
            'size_bytes' => 4,
            'expires_at' => now()->addHour(),
        ]);

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'from_device_id' => $otherDevice->id,
            'group_id' => $otherGroup->id,
            'encrypted_data' => 'data2',
            'size_bytes' => 5,
            'expires_at' => now()->addHour(),
        ]);

        $service = new CacheService;

        $payloads = $service->retrievePayloads($device->device_id, $group->group_id);

        $this->assertCount(1, $payloads);
        $this->assertSame($group->group_id, $payloads->first()['group_id']);
    }

    public function test_retrieve_payload_returns_null_for_expired_payload(): void
    {
        $payload = CachedPayload::factory()->create([
            'expires_at' => now()->subMinute(),
        ]);

        $service = new CacheService;

        $this->assertNull($service->retrievePayload($payload->cache_id));
    }

    public function test_retrieve_and_delete_payload(): void
    {
        $payload = CachedPayload::factory()->create([
            'expires_at' => now()->addHour(),
        ]);

        $service = new CacheService;

        $result = $service->retrievePayload($payload->cache_id);

        $this->assertSame($payload->cache_id, $result['cache_id']);
        $this->assertTrue($service->deletePayload($payload->cache_id));
        $this->assertFalse($service->deletePayload($payload->cache_id));
    }

    public function test_clear_cache_and_cleanup(): void
    {
        $device = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'group_id' => $group->id,
            'expires_at' => now()->addHour(),
        ]);

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'group_id' => $group->id,
            'expires_at' => now()->subHour(),
        ]);

        $service = new CacheService;

        $this->assertSame(1, $service->cleanupExpired());
        $this->assertSame(1, $service->clearDeviceCache($device->device_id));
        $this->assertSame(0, $service->clearDeviceCache('missing-device'));
        $this->assertSame(0, $service->clearGroupCache('missing-group'));
        $this->assertSame(0, $service->clearGroupCache($group->group_id));
    }

    public function test_cache_size_and_quota_checks(): void
    {
        $device = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'group_id' => $group->id,
            'size_bytes' => 10,
            'expires_at' => now()->addHour(),
        ]);

        $service = new CacheService;

        $this->assertSame(10, $service->getDeviceCacheSize($device->device_id));
        $this->assertSame(10, $service->getGroupCacheSize($group->group_id));
        $this->assertTrue($service->isDeviceQuotaExceeded($device->device_id, 5));
        $this->assertFalse($service->isDeviceQuotaExceeded($device->device_id, 15));
        $this->assertTrue($service->isGroupQuotaExceeded($group->group_id, 5));
        $this->assertFalse($service->isGroupQuotaExceeded($group->group_id, 15));
        $this->assertSame(0, $service->getGroupCacheSize('missing-group'));
    }

    public function test_get_device_cache_stats_handles_missing_device(): void
    {
        $service = new CacheService;

        $stats = $service->getDeviceCacheStats('missing-device');

        $this->assertSame(0, $stats['total_count']);
        $this->assertSame(0, $stats['total_size']);
        $this->assertNull($stats['oldest_expires_at']);
    }

    public function test_get_device_cache_stats_returns_summary(): void
    {
        $device = Device::factory()->create();
        $oldest = now()->addMinutes(30);

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'size_bytes' => 4,
            'expires_at' => $oldest,
        ]);

        CachedPayload::factory()->create([
            'to_device_id' => $device->id,
            'size_bytes' => 6,
            'expires_at' => now()->addHour(),
        ]);

        $service = new CacheService;

        $stats = $service->getDeviceCacheStats($device->device_id);

        $this->assertSame(2, $stats['total_count']);
        $this->assertSame(10, $stats['total_size']);
        $this->assertSame($oldest->toIso8601String(), $stats['oldest_expires_at']);
    }
}
