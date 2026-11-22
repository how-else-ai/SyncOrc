<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'name' => 'test-device',
            'api_token' => 'test-sync-token-1234567890abcdef',
            'capabilities' => ['notes'],
        ]);
    }

    /** @test */
    public function test_sync_endpoint_requires_bearer_token(): void
    {
        $response = $this->getJson('/api/sync/changes');

        $response->assertStatus(401)
            ->assertJson(['error' => 'sync token required']);
    }

    /** @test */
    public function test_sync_endpoint_rejects_invalid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer wrong-token',
        ])->getJson('/api/sync/changes');

        $response->assertStatus(401)
            ->assertJson(['error' => 'invalid sync token']);
    }

    /** @test */
    public function test_sync_endpoint_accepts_valid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->getJson('/api/sync/changes');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_device_profile_excludes_api_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->device->api_token,
        ])->getJson('/api/devices/me');

        $response->assertStatus(200)
            ->assertJsonMissing(['api_token'])
            ->assertJsonFragment([
                'device_id' => $this->device->id,
                'name' => 'test-device',
            ]);
    }
}
