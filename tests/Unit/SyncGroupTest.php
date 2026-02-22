<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\SyncGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_type_helpers_and_scopes(): void
    {
        $pair = SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_PAIR]);
        SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_CHAIN]);
        SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_GROUP]);

        $this->assertContains(SyncGroup::TYPE_PAIR, SyncGroup::groupTypes());
        $this->assertTrue($pair->isPair());
        $this->assertFalse($pair->isChain());
        $this->assertFalse($pair->isGroup());
        $this->assertCount(1, SyncGroup::pairs()->get());
        $this->assertCount(1, SyncGroup::chains()->get());
        $this->assertCount(1, SyncGroup::groups()->get());
    }

    public function test_chain_navigation_and_membership_checks(): void
    {
        $group = SyncGroup::factory()->create(['group_type' => SyncGroup::TYPE_CHAIN]);
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $deviceC = Device::factory()->create();

        GroupMember::factory()->create(['group_id' => $group->id, 'device_id' => $deviceA->id, 'position' => 0]);
        GroupMember::factory()->create(['group_id' => $group->id, 'device_id' => $deviceB->id, 'position' => 1]);
        GroupMember::factory()->create(['group_id' => $group->id, 'device_id' => $deviceC->id, 'position' => 2]);

        $this->assertSame(3, $group->memberCount());
        $this->assertSame($deviceB->id, $group->nextDeviceInChain($deviceA->id)->id);
        $this->assertSame($deviceB->id, $group->previousDeviceInChain($deviceC->id)->id);
        $this->assertTrue($group->hasDevice($deviceA->id));
        $this->assertFalse($group->hasDevice('missing-device'));

        $ordered = $group->devicesInChainOrder();
        $this->assertSame([$deviceA->id, $deviceB->id, $deviceC->id], $ordered->pluck('id')->all());
    }
}
