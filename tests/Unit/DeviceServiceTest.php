<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\SyncGroup;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class DeviceServiceTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_register_device_creates_device_and_token(): void
    {
        $service = new DeviceService;

        $result = $service->registerDevice(base64_encode(random_bytes(16)), 'ios', 'push-token');

        $this->assertInstanceOf(Device::class, $result['device']);
        $this->assertStringStartsWith('sync_', $result['api_token']);
        $this->assertDatabaseHas('devices', [
            'device_id' => $result['device']->device_id,
            'platform' => 'ios',
        ]);
    }

    public function test_refresh_token_updates_token_and_expiry(): void
    {
        $device = Device::factory()->create([
            'api_token' => 'sync_oldtoken',
            'token_expires_at' => now()->subDay(),
        ]);

        $service = new DeviceService;
        $result = $service->refreshToken($device->device_id);

        $this->assertNotSame('sync_oldtoken', $result['api_token']);
        $this->assertTrue(Carbon::parse($result['expires_at'])->isFuture());
    }

    public function test_update_push_token_updates_device(): void
    {
        $device = Device::factory()->create(['push_token' => null]);
        $service = new DeviceService;

        $service->updatePushToken($device->device_id, 'new-token');

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'push_token' => 'new-token',
        ]);
    }

    public function test_get_device_by_token_requires_active_token(): void
    {
        $activeDevice = Device::factory()->create([
            'api_token' => 'sync_active',
            'token_expires_at' => now()->addHour(),
        ]);

        Device::factory()->create([
            'api_token' => 'sync_expired',
            'token_expires_at' => now()->subHour(),
        ]);

        $service = new DeviceService;

        $this->assertSame($activeDevice->id, $service->getDeviceByToken('sync_active')->id);
        $this->assertNull($service->getDeviceByToken('sync_expired'));
    }

    public function test_mark_as_active_sets_last_seen(): void
    {
        $device = Device::factory()->create(['last_seen_at' => null]);
        $service = new DeviceService;

        $service->markAsActive($device->device_id);

        $this->assertNotNull($device->refresh()->last_seen_at);
    }

    public function test_is_online_returns_true_when_redis_has_key(): void
    {
        $device = Device::factory()->create();
        $redis = Mockery::mock();
        $redis->shouldReceive('exists')->with('online:device:'.$device->device_id)->andReturn(true);
        app()->instance('redis', $redis);

        $service = new DeviceService;

        $this->assertTrue($service->isOnline($device->device_id));
    }

    public function test_is_online_falls_back_to_last_seen(): void
    {
        $device = Device::factory()->create(['last_seen_at' => now()->subMinutes(2)]);
        $redis = Mockery::mock();
        $redis->shouldReceive('exists')->with('online:device:'.$device->device_id)->andReturn(false);
        app()->instance('redis', $redis);

        $service = new DeviceService;

        $this->assertTrue($service->isOnline($device->device_id));

        $device->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->assertFalse($service->isOnline($device->device_id));
    }

    public function test_set_online_and_offline_use_redis(): void
    {
        $device = Device::factory()->create();
        $redis = Mockery::mock();
        $redis->shouldReceive('setex')->with('online:device:'.$device->device_id, 300, Mockery::type('int'))->once();
        $redis->shouldReceive('del')->with('online:device:'.$device->device_id)->once();
        app()->instance('redis', $redis);

        $service = new DeviceService;

        $service->setOnline($device->device_id);
        $service->setOffline($device->device_id);
    }

    public function test_validate_token_checks_token_and_expiry(): void
    {
        $device = Device::factory()->create([
            'api_token' => 'sync_valid',
            'token_expires_at' => now()->addHour(),
        ]);

        $service = new DeviceService;

        $this->assertTrue($service->validateToken($device->device_id, 'sync_valid'));
        $this->assertFalse($service->validateToken($device->device_id, 'sync_invalid'));

        $device->update(['token_expires_at' => now()->subHour()]);

        $this->assertFalse($service->validateToken($device->device_id, 'sync_valid'));
    }

    public function test_get_paired_devices_returns_other_group_members(): void
    {
        $device = Device::factory()->create();
        $otherDevice = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'position' => 0,
        ]);

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'device_id' => $otherDevice->id,
            'position' => 1,
        ]);

        $service = new DeviceService;

        $paired = $service->getPairedDevices($device->device_id);

        $this->assertCount(1, $paired);
        $this->assertSame($otherDevice->device_id, $paired->first()->device_id);
    }
}
