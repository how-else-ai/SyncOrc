<?php

namespace Tests\Feature;

use App\Models\ChangeEntry;
use App\Models\SyncCursor;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'name' => 'ack-device',
            'api_token' => 'ack-token-1234567890abcdef',
        ]);

        $this->otherDevice = Device::create([
            'name' => 'other-device',
            'api_token' => 'other-ack-token-1234567890abcdef',
        ]);
    }

    /** @test */
    public function test_ack_advances_sync_cursor(): void
    {
        $change = ChangeEntry::create([
            'device_id' => $this->otherDevice->id,
            'entity_type' => 'note',
            'entity_id' => 'note-ack',
            'change_type' => 'create',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/ack', [
            'last_change_id' => $change->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['acknowledged' => true]);

        $this->assertDatabaseHas('sync_cursors', [
            'device_id' => $this->device->id,
        ]);

        $cursor = SyncCursor::where('device_id', $this->device->id)->first();
        $this->assertNotNull($cursor->last_synced_at);
    }

    /** @test */
    public function test_poll_after_ack_returns_no_changes_until_newer_change(): void
    {
        $change = ChangeEntry::create([
            'device_id' => $this->otherDevice->id,
            'entity_type' => 'note',
            'entity_id' => 'note-ack-2',
            'change_type' => 'create',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 1,
        ]);

        // First poll returns the change.
        $first = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->getJson('/api/sync/changes');

        $first->assertJsonCount(1, 'changes');

        // Ack the change.
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/ack', [
            'last_change_id' => $change->id,
        ]);

        // Second poll returns nothing new.
        $second = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->getJson('/api/sync/changes');

        $second->assertJsonCount(0, 'changes');
    }

    /** @test */
    public function test_ack_rejects_unknown_change_id(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/ack', [
            'last_change_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertStatus(422);
    }
}
