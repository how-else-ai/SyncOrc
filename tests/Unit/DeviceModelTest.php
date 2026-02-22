<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_api_token_format(): void
    {
        $token = Device::generateApiToken();

        $this->assertStringStartsWith('sync_', $token);
        $this->assertSame(69, strlen($token));
    }

    public function test_creating_device_sets_token_and_expiry(): void
    {
        $device = Device::factory()->create([
            'api_token' => null,
            'token_expires_at' => null,
        ]);

        $this->assertNotNull($device->api_token);
        $this->assertNotNull($device->token_expires_at);
    }

    public function test_scopes_and_state_helpers(): void
    {
        $active = Device::factory()->create(['platform' => 'ios', 'last_seen_at' => now()]);
        $inactive = Device::factory()->create([
            'token_expires_at' => now()->subDay(),
            'platform' => 'android',
            'last_seen_at' => null,
        ]);

        $this->assertCount(1, Device::active()->get());
        $this->assertCount(1, Device::byPlatform('ios')->get());
        $this->assertCount(1, Device::recentlySeen(5)->get());
        $this->assertCount(1, Device::stale(1)->get());

        $inactive->markActive();
        $this->assertNotNull($inactive->last_seen_at);
        $this->assertFalse($inactive->isTokenValid());

        $inactive->refreshToken();
        $this->assertTrue($inactive->isTokenValid());

        $this->assertFalse($inactive->canReceivePush());
        $active->update(['push_token' => 'token']);
        $this->assertTrue($active->canReceivePush());
    }
}
