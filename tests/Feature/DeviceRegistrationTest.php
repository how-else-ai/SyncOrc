<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_device_registration_returns_device_id_and_sync_token(): void
    {
        $response = $this->postJson('/api/devices', [
            'name' => 'laptop',
            'public_key' => '-----BEGIN PUBLIC KEY-----...',
            'capabilities' => ['notes', 'contacts'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'device_id',
                'sync_token',
            ]);
    }

    /** @test */
    public function test_device_registration_stores_device_in_database(): void
    {
        $this->postJson('/api/devices', [
            'name' => 'phone',
            'capabilities' => ['notes'],
        ]);

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('devices', [
            'name' => 'phone',
        ]);
    }

    /** @test */
    public function test_device_registration_generates_api_token(): void
    {
        $response = $this->postJson('/api/devices', [
            'name' => 'tablet',
        ]);

        $deviceId = $response->json('device_id');
        $device = Device::find($deviceId);

        $this->assertNotNull($device->api_token);
        $this->assertIsString($device->api_token);
    }

    /** @test */
    public function test_device_registration_accepts_optional_fields(): void
    {
        $response = $this->postJson('/api/devices', []);

        $response->assertStatus(201);
    }
}
