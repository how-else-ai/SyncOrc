<?php

namespace Tests\Unit;

use App\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_ack_token_hash_is_lowercased_on_save(): void
    {
        $state = SyncState::factory()->create([
            'ack_token_hash' => 'ABCDEF',
        ]);

        $this->assertSame('abcdef', $state->ack_token_hash);
    }

    public function test_scopes_and_clock_helpers(): void
    {
        $state = SyncState::factory()->create([
            'is_acknowledged' => false,
            'vector_clock' => ['a' => 1],
        ]);

        SyncState::factory()->create([
            'is_acknowledged' => true,
            'group_id' => $state->group_id,
            'device_id' => $state->device_id,
        ]);

        $this->assertCount(1, SyncState::unacknowledged()->get());
        $this->assertCount(1, SyncState::acknowledged()->get());
        $this->assertCount(2, SyncState::forGroup($state->group_id)->get());
        $this->assertCount(2, SyncState::forDevice($state->device_id)->get());

        $state->incrementClock('b');
        $state->mergeClock(['a' => 3, 'c' => 1]);

        $this->assertSame(['a' => 3, 'b' => 1, 'c' => 1], $state->vector_clock);
    }

    public function test_happened_before_and_concurrency_checks(): void
    {
        $stateA = SyncState::factory()->make(['vector_clock' => ['a' => 1]]);
        $stateB = SyncState::factory()->make(['vector_clock' => ['a' => 2]]);
        $stateC = SyncState::factory()->make(['vector_clock' => ['a' => 1, 'b' => 1]]);

        $this->assertTrue($stateA->happenedBefore($stateB));
        $this->assertFalse($stateB->happenedBefore($stateA));
        $this->assertTrue($stateB->isConcurrentWith($stateC));
    }
}
