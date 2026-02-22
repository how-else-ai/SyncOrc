<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\SyncGroup;
use App\Models\SyncState;
use App\Services\DeviceService;
use App\Services\NotificationService;
use App\Services\SyncCoordinatorService;
use App\Services\VectorClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SyncCoordinatorServiceTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_notify_state_change_detects_loop(): void
    {
        $group = SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_PAIR]);
        $device = Device::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'position' => 0,
        ]);

        SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'vector_clock' => ['device' => 2],
        ]);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldNotReceive('notifySyncRequired');

        $service = new SyncCoordinatorService(
            new VectorClockService,
            $notificationService,
            Mockery::mock(DeviceService::class)
        );

        $result = $service->notifyStateChange(
            $device->device_id,
            $group->group_id,
            'v1',
            str_repeat('a', 64),
            ['device' => 1]
        );

        $this->assertTrue($result['loop_detected']);
        $this->assertSame(1, SyncState::where('group_id', $group->id)->count());
    }

    public function test_notify_state_change_creates_sync_state(): void
    {
        $group = SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_PAIR]);
        $device = Device::factory()->create();
        $otherDevice = Device::factory()->create();

        GroupMember::factory()->create(['group_id' => $group->id, 'device_id' => $device->id, 'position' => 0]);
        GroupMember::factory()->create(['group_id' => $group->id, 'device_id' => $otherDevice->id, 'position' => 1]);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('notifySyncRequired')
            ->once()
            ->withArgs(function ($source, $groupId, $version, $targets) use ($device, $group) {
                return $source === $device->device_id
                    && $groupId === $group->group_id
                    && $version === 'v1'
                    && $targets === [$otherDevice->device_id];
            })
            ->andReturn([
                'notified_devices' => [],
                'online_count' => 1,
                'push_count' => 0,
            ]);

        $service = new SyncCoordinatorService(
            new VectorClockService,
            $notificationService,
            Mockery::mock(DeviceService::class)
        );

        $result = $service->notifyStateChange(
            $device->device_id,
            $group->group_id,
            'v1',
            str_repeat('b', 64)
        );

        $this->assertFalse($result['loop_detected']);
        $this->assertDatabaseHas('sync_states', [
            'group_id' => $group->id,
            'device_id' => $device->id,
            'state_version' => 'v1',
        ]);
    }

    public function test_acknowledge_sync_updates_state(): void
    {
        $group = SyncGroup::factory()->create();
        $device = Device::factory()->create();

        $syncState = SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'state_version' => 'v1',
            'ack_token_hash' => 'abc',
            'is_acknowledged' => false,
        ]);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('notifySyncAcknowledged')->once();

        $service = new SyncCoordinatorService(
            new VectorClockService,
            $notificationService,
            Mockery::mock(DeviceService::class)
        );

        $this->assertTrue($service->acknowledgeSync($device->device_id, $group->group_id, 'v1', 'abc'));
        $this->assertTrue($syncState->refresh()->is_acknowledged);
    }

    public function test_get_sync_status_reports_pending_syncs(): void
    {
        $group = SyncGroup::factory()->create();
        $device = Device::factory()->create();
        $otherDevice = Device::factory()->create();

        SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'state_version' => 'v1',
            'vector_clock' => ['a' => 1],
        ]);

        SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $otherDevice->id,
            'state_version' => 'v2',
            'vector_clock' => ['a' => 2],
            'is_acknowledged' => false,
        ]);

        $service = new SyncCoordinatorService(
            new VectorClockService,
            Mockery::mock(NotificationService::class),
            Mockery::mock(DeviceService::class)
        );

        $status = $service->getSyncStatus($device->device_id, $group->group_id);

        $this->assertFalse($status['is_up_to_date']);
        $this->assertCount(1, $status['pending_syncs']);
    }

    public function test_get_device_groups_and_latest_states(): void
    {
        $device = Device::factory()->create();
        $group = SyncGroup::factory()->create();

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'position' => 0,
        ]);

        SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $device->id,
            'state_version' => 'v1',
        ]);

        $service = new SyncCoordinatorService(
            new VectorClockService,
            Mockery::mock(NotificationService::class),
            Mockery::mock(DeviceService::class)
        );

        $groups = $service->getDeviceGroups($device->device_id);
        $this->assertSame($group->group_id, $groups->first()['group_id']);

        $latest = $service->getLatestSyncState($device->device_id, $group->group_id);
        $this->assertSame('v1', $latest->state_version);

        $unacknowledged = $service->getUnacknowledgedSyncs($device->device_id, $group->group_id);
        $this->assertCount(1, $unacknowledged);
        $this->assertCount(0, $service->getUnacknowledgedSyncs($device->device_id, 'missing-group'));
    }
}
