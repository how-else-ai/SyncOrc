<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAnnounceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'name' => 'announcing-device',
            'api_token' => 'announce-token-1234567890abcdef',
        ]);
    }

    /** @test */
    public function test_announce_changes_stores_metadata_only(): void
    {
        $change = [
            'entity_type' => 'note',
            'entity_id' => 'note-42',
            'change_type' => 'update',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 3,
            'vector_clock' => ['laptop' => 3],
            'payload_location' => 'http://device.local:9090/sync/note-42',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/announce', [
            'changes' => [$change],
        ]);

        $response->assertStatus(201)
            ->assertJson(['accepted' => 1]);

        $this->assertDatabaseCount('change_entries', 1);
        $this->assertDatabaseHas('change_entries', [
            'device_id' => $this->device->id,
            'entity_type' => 'note',
            'entity_id' => 'note-42',
            'change_type' => 'update',
            'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'version' => 3,
        ]);

        // The server never stores or returns payload content.
        $response->assertJsonMissing(['payload', 'content']);
        $this->assertDatabaseMissing('change_entries', [
            'payload_location' => null, // payload_location is metadata (a URL), not content
        ]);
    }

    /** @test */
    public function test_announce_rejects_invalid_content_hash(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/announce', [
            'changes' => [
                [
                    'entity_type' => 'note',
                    'entity_id' => 'note-42',
                    'change_type' => 'update',
                    'content_hash' => 'not-a-hash',
                    'version' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_announce_rejects_invalid_change_type(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/announce', [
            'changes' => [
                [
                    'entity_type' => 'note',
                    'entity_id' => 'note-42',
                    'change_type' => 'invalid-type',
                    'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    'version' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_announce_rejects_empty_changes(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/announce', [
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_announce_associates_changes_with_authenticated_device(): void
    {
        $otherDevice = Device::create([
            'name' => 'other',
            'api_token' => 'other-token-1234567890abcdef',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->postJson('/api/sync/announce', [
            'changes' => [
                [
                    'entity_type' => 'note',
                    'entity_id' => 'note-99',
                    'change_type' => 'create',
                    'content_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    'version' => 1,
                ],
            ],
        ]);

        $this->assertDatabaseHas('change_entries', [
            'device_id' => $this->device->id,
        ]);
        $this->assertDatabaseMissing('change_entries', [
            'device_id' => $otherDevice->id,
        ]);
    }
}
