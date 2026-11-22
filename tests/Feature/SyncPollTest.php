<?php

namespace Tests\Feature;

use App\Models\ChangeEntry;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceA = Device::create([
            'name' => 'device-a',
            'api_token' => 'device-a-token-1234567890abcdef',
        ]);

        $this->deviceB = Device::create([
            'name' => 'device-b',
            'api_token' => 'device-b-token-1234567890abcdef',
        ]);
    }

    /** @test */
    public function test_poll_returns_changes_from_other_devices_only(): void
    {
        // Device A announces a change.
        ChangeEntry::create([
            'device_id' => $this->deviceA->id,
            'entity_type' => 'note',
            'entity_id' => 'note-1',
            'change_type' => 'create',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 1,
        ]);

        // Device B also announces a change (should not appear in its own poll).
        ChangeEntry::create([
            'device_id' => $this->deviceB->id,
            'entity_type' => 'note',
            'entity_id' => 'note-2',
            'change_type' => 'create',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->deviceB->api_token,
        ])->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'changes')
            ->assertJsonFragment([
                'entity_type' => 'note',
                'entity_id' => 'note-1',
            ]);
    }

    /** @test */
    public function test_poll_excludes_own_changes(): void
    {
        ChangeEntry::create([
            'device_id' => $this->deviceB->id,
            'entity_type' => 'note',
            'entity_id' => 'note-own',
            'change_type' => 'create',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->deviceB->api_token,
        ])->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'changes');
    }

    /** @test */
    public function test_poll_returns_empty_when_no_changes(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->deviceB->api_token,
        ])->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'changes')
            ->assertJson(['has_more' => false]);
    }

    /** @test */
    public function test_poll_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            ChangeEntry::create([
                'device_id' => $this->deviceA->id,
                'entity_type' => 'note',
                'entity_id' => "note-$i",
                'change_type' => 'create',
                'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'version' => $i,
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->deviceB->api_token,
        ])->getJson('/api/sync/changes?limit=3');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'changes')
            ->assertJson(['has_more' => true]);
    }
}
