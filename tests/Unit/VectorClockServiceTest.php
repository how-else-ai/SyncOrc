<?php

namespace Tests\Unit;

use App\Models\SyncGroup;
use App\Models\SyncState;
use App\Services\VectorClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VectorClockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_clocks_and_distance(): void
    {
        $service = new VectorClockService;

        $clock1 = ['a' => 1, 'b' => 2];
        $clock2 = ['a' => 1, 'b' => 2];
        $clock3 = ['a' => 2, 'b' => 2];
        $clock4 = ['a' => 1, 'b' => 3];

        $this->assertSame('equal', $service->compareClocks($clock1, $clock2));
        $this->assertSame('older', $service->compareClocks($clock1, $clock3));
        $this->assertSame('newer', $service->compareClocks($clock3, $clock1));
        $this->assertSame('concurrent', $service->compareClocks($clock3, $clock4));
        $this->assertSame(2, $service->clockDistance($clock1, $clock4));
    }

    public function test_detect_loop_and_latest_clocks(): void
    {
        $service = new VectorClockService;
        $group = SyncGroup::factory()->create();
        $state = SyncState::factory()->create([
            'group_id' => $group->id,
            'vector_clock' => ['device-1' => 2],
        ]);

        $this->assertTrue($service->detectLoop($group->id, $state->device_id, ['device-1' => 1]));
        $this->assertFalse($service->detectLoop($group->id, 'missing-device', ['device-1' => 1]));

        SyncState::factory()->create([
            'group_id' => $group->id,
            'device_id' => $state->device_id,
            'vector_clock' => ['device-1' => 3],
        ]);

        $latest = $service->getLatestClocksForGroup($group->id);

        $this->assertSame(['device-1' => 3], $latest[$state->device_id]);
    }
}
